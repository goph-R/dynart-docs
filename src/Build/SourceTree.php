<?php

namespace Dynart\Docs\Build;

use Dynart\Docs\Docs;

/**
 * The pages of a documentation source, in the tree its `toctree`s make
 *
 * Sphinx's rule for what is published: **what a `toctree` reaches from the root `index.md`**, and
 * nothing else. That is what keeps a folder's `README.md`, a `CLAUDE.md` and everything under
 * `_build/` out without a list of exclusions - they are simply never named.
 *
 * A page is known by its **docname**, Sphinx's word: the file's path from the source root without
 * `.md`, in the file's own case - `dos-game-engine/BASICS/VGA`, `dos-game-engine/index`, `index`.
 * Its **path**, the address below the base, is the docname with a trailing `index` taken off.
 */
class SourceTree {

    /** @var array<string, array> docname => node, in reading order - parents before children */
    private array $nodes = [];

    public function __construct(private string $root, private BuildReport $report) {
        $this->root = rtrim(str_replace('\\', '/', $root), '/');
    }

    /**
     * Walks the tree from the root `index.md`
     *
     * @return array<string, array> docname => ['docname', 'file', 'path', 'parent', 'position',
     *                              'sequence', 'children' => docname[], 'toctrees' => [...]]
     */
    public function walk(): array {
        $this->nodes = [];
        if (!is_file($this->file(Docs::INDEX))) {
            $this->report->problem(Docs::INDEX.'.md', 'The source folder has no index.md, which is where the tree starts.');
            return [];
        }
        $this->visit(Docs::INDEX, null, 0);
        $sequence = 0;
        foreach ($this->nodes as $docname => $node) {
            $this->nodes[$docname]['sequence'] = $sequence++;
        }
        return $this->nodes;
    }

    private function visit(string $docname, ?string $parent, int $position): void {
        $markdown = (string)file_get_contents($this->file($docname));
        $toctrees = self::toctrees($markdown);
        $this->nodes[$docname] = [
            'docname'  => $docname,
            'file'     => $docname.'.md',
            'path'     => self::pathOf($docname),
            'parent'   => $parent,
            'position' => $position,
            'sequence' => 0,
            'children' => [],
            'toctrees' => $toctrees,
        ];
        $index = 0;
        foreach ($toctrees as $toctree) {
            foreach ($toctree['entries'] as $entry) {
                $child = self::resolve($docname, $entry['target']);
                if (isset($this->nodes[$child])) {
                    // Sphinx warns about a document in two toctrees and keeps the first; so does this
                    $this->report->problem($docname.'.md', "'{$entry['target']}' is in a toctree already and is left where it was first.");
                    continue;
                }
                if (!is_file($this->file($child))) {
                    $this->report->problem($docname.'.md', "The toctree names '{$entry['target']}', and there is no $child.md.");
                    continue;
                }
                $this->nodes[$docname]['children'][] = $child;
                $this->visit($child, $docname, $index++);
            }
        }
    }

    private function file(string $docname): string {
        return $this->root.'/'.$docname.'.md';
    }

    // --- the pure part ---

    /**
     * The `toctree` blocks of one page, in order
     *
     * ````
     * ```{toctree}
     * :maxdepth: 1
     * :caption: Contents
     *
     * lisa-engine/index
     * Custom title <dos-game-engine/index>
     * ```
     * ````
     *
     * @return array[] ['options' => [name => value], 'entries' => [['target' => ..., 'title' => ?string]]]
     */
    public static function toctrees(string $markdown): array {
        $found = [];
        foreach (Myst::directives($markdown) as $directive) {
            if ($directive['name'] !== 'toctree') {
                continue;
            }
            $entries = [];
            foreach ($directive['body'] as $line) {
                $line = trim($line);
                if ($line === '') {
                    continue;
                }
                // `Title <target>`, the form that overrides the page's own title
                if (preg_match('/^(.*\S)\s*<([^>]+)>$/', $line, $m)) {
                    $entries[] = ['target' => trim($m[2]), 'title' => trim($m[1])];
                } else {
                    $entries[] = ['target' => $line, 'title' => null];
                }
            }
            $found[] = ['options' => $directive['options'], 'entries' => $entries];
        }
        return $found;
    }

    /**
     * The docname a toctree entry or a link names, from the page it is written in
     *
     * Relative to that page's folder, as Sphinx reads it; a leading `/` is from the source root.
     * `.md` is taken off if it is there, and `..` and `.` are resolved - never above the root.
     */
    public static function resolve(string $fromDocname, string $target): string {
        $target = preg_replace('/\.md$/i', '', trim($target));
        $base = str_starts_with($target, '/') ? '' : dirname($fromDocname);
        $parts = [];
        foreach (explode('/', ($base === '.' || $base === '' ? '' : $base.'/').ltrim($target, '/')) as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }
            if ($part === '..') {
                array_pop($parts);
                continue;
            }
            $parts[] = $part;
        }
        return implode('/', $parts);
    }

    /** The address below the base: the docname, less a trailing `index` */
    public static function pathOf(string $docname): string {
        if ($docname === Docs::INDEX) {
            return '';
        }
        return preg_replace('#/'.Docs::INDEX.'$#', '', $docname);
    }
}
