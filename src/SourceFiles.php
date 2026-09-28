<?php

namespace Dynart\Docs;

use Dynart\Dpress\DpressException;

/**
 * Reading and writing a page's source file, for the editor
 *
 * **Only a file the build published**: the caller finds the page by its `source` first, so what
 * can be opened is what a `toctree` reaches - never any path somebody puts in a query string. The
 * path is still checked to be inside the source folder, because it is about to be written.
 *
 * **Written back as it was**: a browser sends a textarea's lines as `\r\n` whatever the file had,
 * so the lines are put back the way the file had them, and a final newline stays or stays away -
 * a save that changes one word shows as one word in `git diff`, not as every line.
 */
class SourceFiles {

    public function __construct(protected string $folder) {}

    public function path(string $source): string {
        $source = str_replace('\\', '/', $source);
        if ($source === '' || str_contains('/'.$source.'/', '/../') || preg_match('#^(/|[A-Za-z]:)#', $source) === 1) {
            throw new DpressException("$source is not a file of the source folder.");
        }
        return rtrim($this->folder, '/\\').'/'.$source;
    }

    public function read(string $source): string {
        $path = $this->path($source);
        if (!is_file($path)) {
            throw new DpressException("$source is not in the source folder any more - build again.");
        }
        return (string)file_get_contents($path);
    }

    /** What a file's text is compared by, to know it has not changed underneath the editor */
    public static function hash(string $text): string {
        return sha1($text);
    }

    /**
     * Writes the editor's text over the file, unless the file changed since it was opened
     *
     * @param string $opened the `hash()` of the file when the editor was drawn
     * @return bool whether anything was written - false when the text is what the file already has
     */
    public function write(string $source, string $text, string $opened): bool {
        $path = $this->path($source);
        $current = $this->read($source);
        if ($opened === '' || !hash_equals(self::hash($current), $opened)) {
            throw new DpressException('The file changed on disk after you opened it - a pull, or an edit somewhere'
                .' else. Your text is still here: copy it, open the page again, and put your change back in.');
        }
        if (preg_match('//u', $text) !== 1) {
            throw new DpressException('The text is not valid UTF-8, so it was not written.');
        }
        $text = self::asFileHas($text, $current);
        if ($text === $current) {
            return false;
        }
        if (!is_writable($path)) {
            throw new DpressException("The web server cannot write $source: the source folder has to belong to its user"
                .' (chown -R www-data: on the server).');
        }
        if (file_put_contents($path, $text, LOCK_EX) === false) {
            throw new DpressException("$source could not be written.");
        }
        return true;
    }

    /** The editor's text with the file's own line endings, and its final newline or none */
    public static function asFileHas(string $text, string $file): string {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $endsWithNewline = str_ends_with($file, "\n");
        $text = rtrim($text, "\n").($endsWithNewline ? "\n" : '');
        return str_contains($file, "\r\n") ? str_replace("\n", "\r\n", $text) : $text;
    }
}
