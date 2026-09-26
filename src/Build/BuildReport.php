<?php

namespace Dynart\Docs\Build;

/**
 * What a build has to say: how many pages, and everything it could not do
 *
 * A build never stops at the first problem - a documentation site with one broken link is still a
 * documentation site - so each one is noted here against the file it was in, and the admin screen
 * and `docs:build` both print the list.
 */
class BuildReport {

    /** @var array[] ['file' => ..., 'message' => ...] */
    public array $problems = [];

    public int $pages = 0;

    public function problem(string $file, string $message): void {
        $this->problems[] = ['file' => $file, 'message' => $message];
    }

    public function ok(): bool {
        return $this->problems === [];
    }
}
