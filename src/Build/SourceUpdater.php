<?php

namespace Dynart\Docs\Build;

/**
 * Brings the source folder up to date with git, before a build reads it
 *
 * What somebody would otherwise type on the server - `git pull` in the folder, then the
 * submodules - so a push to the documentation's repository reaches the site with nothing more
 * than the Build button, or `dpress docs:build` from a cron job.
 *
 * **Nothing from a request reaches the command.** The folder is a setting only an administrator
 * can change, the arguments are fixed here, and the command is an argument list rather than a
 * string handed to a shell, so no character in a folder name is ever interpreted.
 *
 * **`--ff-only`**: the server's copy is a mirror, not a place anybody works. A pull that cannot
 * fast-forward - somebody edited a file on the server - fails and says so, rather than making a
 * merge commit there that the next pull would have to live with.
 *
 * **It cannot hang a request.** A prompt for a password would wait for ever on a web request, so
 * git is told there is nobody to ask (`GIT_TERMINAL_PROMPT=0`), and a connection that slows to
 * almost nothing is given up after half a minute.
 */
class SourceUpdater {

    /** Below this many bytes a second, for `LOW_SPEED_TIME` seconds, git gives up on a fetch */
    const LOW_SPEED_LIMIT = 1000;
    const LOW_SPEED_TIME = 30;

    /** How much of git's own output a failure reports - its last lines are the ones that say why */
    const OUTPUT_LINES = 4;

    /**
     * @return array{ok: bool, message: string} one line either way: what changed, or why not
     */
    public function update(string $folder): array {
        if (!function_exists('proc_open')) {
            return self::failed('PHP cannot run git here: proc_open is disabled.');
        }
        if (!file_exists($folder.'/.git')) {
            return self::failed("$folder is not a git clone.");
        }
        [, $before] = $this->git($folder, ['rev-parse', '--short', 'HEAD']);
        foreach ([
            ['pull', '--ff-only', '--recurse-submodules'],
            ['submodule', 'update', '--init', '--recursive'],
        ] as $arguments) {
            [$code, $output] = $this->git($folder, $arguments);
            if ($code !== 0) {
                return self::failed('git '.$arguments[0].' failed: '.self::tail($output));
            }
        }
        [, $after] = $this->git($folder, ['rev-parse', '--short', 'HEAD']);
        $before = trim($before);
        $after = trim($after);
        return [
            'ok'      => true,
            'message' => $before === $after
                ? "The source was up to date, at $after."
                : "The source was updated from $before to $after.",
        ];
    }

    /**
     * One git command in the folder, its exit code and everything it printed
     *
     * @return array{0: int, 1: string}
     */
    protected function git(string $folder, array $arguments): array {
        $process = proc_open(
            array_merge(['git', '-C', $folder], $arguments),
            // one stream for both, so neither can fill up and stall git while the other is read
            [1 => ['pipe', 'w'], 2 => ['redirect', 1]],
            $pipes,
            $folder,
            self::environment()
        );
        if (!is_resource($process)) {
            return [-1, 'git could not be started.'];
        }
        $output = (string)stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        return [proc_close($process), $output];
    }

    /** What git runs with: this process's own environment, and nobody to ask for a password */
    protected static function environment(): array {
        return array_merge(getenv(), [
            'GIT_TERMINAL_PROMPT'      => '0',
            'GIT_HTTP_LOW_SPEED_LIMIT' => (string)self::LOW_SPEED_LIMIT,
            'GIT_HTTP_LOW_SPEED_TIME'  => (string)self::LOW_SPEED_TIME,
        ]);
    }

    /**
     * Why git failed, on one line: its `fatal:` and `error:` lines when it printed any, or else
     * its last few lines - never its `hint:` lines, which are advice about configuring git and
     * crowd out the one line that says what went wrong
     */
    public static function tail(string $output): string {
        $lines = array_values(array_filter(
            array_map('trim', preg_split('/\r\n|\r|\n/', $output)),
            fn(string $line): bool => $line !== '' && !str_starts_with($line, 'hint:')
        ));
        $errors = array_values(array_filter($lines, fn(string $line): bool => preg_match('/^(fatal|error):/', $line) === 1));
        $tail = implode(' ', array_slice($errors !== [] ? $errors : $lines, -self::OUTPUT_LINES));
        return $tail !== '' ? $tail : 'it printed nothing.';
    }

    protected static function failed(string $message): array {
        return ['ok' => false, 'message' => $message];
    }
}
