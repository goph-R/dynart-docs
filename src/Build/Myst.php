<?php

namespace Dynart\Docs\Build;

/**
 * MyST, as far as the documentation uses it, turned into the Markdown Dpress renders
 *
 * Dpress's renderer is CommonMark with a table extension, callouts and shortcodes; MyST is
 * CommonMark with directives and roles. The gap, in what `docs-public` actually writes:
 *
 * | MyST | becomes |
 * |---|---|
 * | ```` ```{toctree} ```` | a list of links to the pages it names, nested to `:maxdepth:` |
 * | ```` ```{note} ```` and the other admonitions | a callout, `> [!NOTE]` |
 * | `{#id}` alone on the line before a heading, `## Title {#id}` | the heading's id |
 * | ``{ref}`id` `` and ``{ref}`text <id>` `` | a link to the heading that label names |
 * | ``{doc}`path` `` | a link to that page, its title as the text |
 * | `[text](../ENGINE/BASEGAME.md#x)` | a link to that page's address |
 * | `![alt](images/vga.png)` | the image's address under the base (`Images`) |
 * | `<br>` | `{{ br() }}` - raw HTML is stripped by the renderer |
 *
 * **Line by line, and never inside a code block**: a Pascal listing is full of `{` and `<`, and
 * `{#` in a comment is not a label. Inline, the roles are read first - they are written with
 * backticks - and then the code spans are kept whole while links and `<br>` are rewritten.
 *
 * Everything it could not do goes to the `BuildReport`, against the file.
 */
class Myst {

    /** The admonitions MyST has, onto the callouts Dpress has */
    const ADMONITIONS = [
        'note' => 'NOTE', 'tip' => 'TIP', 'hint' => 'TIP', 'important' => 'IMPORTANT',
        'seealso' => 'NOTE', 'attention' => 'WARNING', 'warning' => 'WARNING',
        'caution' => 'CAUTION', 'danger' => 'DANGER', 'error' => 'DANGER', 'admonition' => 'NOTE',
    ];

    const FENCE = '/^(\s{0,3})(`{3,}|~{3,})(.*)$/';

    /** The language a fence that names none is given - see `convert()` */
    const BARE_FENCE = 'text';
    const HEADING = '/^\s{0,3}(#{1,6})\s+(.*?)\s*$/';
    /**
     * MyST's block attributes: `{#id}`, `{.class}`, `{#id .class .other}` - alone on the line
     * before a block, or at the end of a heading. An id and any number of classes, in any order.
     */
    const ATTRS_LINE = '/^\s*\{\s*((?:[#.][A-Za-z0-9_:.-]+\s*)+)\}\s*$/';
    const INLINE_ATTRS = '/\s*\{\s*((?:[#.][A-Za-z0-9_:.-]+\s*)+)\}\s*$/';

    /**
     * The id and the classes of one attribute block's inside
     *
     * @return array{id: ?string, classes: string[]}
     */
    public static function attributes(string $inside): array {
        $id = null;
        $classes = [];
        foreach (preg_split('/\s+/', trim($inside)) as $token) {
            if (str_starts_with($token, '#') && strlen($token) > 1) {
                $id = substr($token, 1);
            } else if (str_starts_with($token, '.') && strlen($token) > 1) {
                $classes[] = substr($token, 1);
            }
        }
        return ['id' => $id, 'classes' => $classes];
    }

    /**
     * @param array $context what the page needs of the rest of the build:
     *   `docname` string, `report` BuildReport,
     *   `url` callable(string docname): ?string - null for a page no toctree reaches,
     *   `title` callable(string docname): string,
     *   `children` callable(string docname): string[],
     *   `labels` array<string, array{docname: string, id: string, title: string}>,
     *   `images` ?Images - the build's; without one an image is left as it is written
     */
    public function __construct(private array $context) {}

    /**
     * @return array ['markdown' => string, 'ids' => array<?array{id: ?string, classes: string[]}>] -
     *               `ids` is each heading's attributes, in order, or null where it has none
     */
    public function convert(string $markdown): array {
        $lines = preg_split('/\r\n|\r|\n/', $markdown);
        $out = [];
        $ids = [];
        $pending = null;
        $fence = null;   // [char, length] while inside an ordinary code block
        $count = count($lines);
        for ($i = 0; $i < $count; $i++) {
            $line = $lines[$i];
            if ($fence !== null) {
                $out[] = $line;
                if (self::closes($line, $fence)) {
                    $fence = null;
                }
                continue;
            }
            if (preg_match(self::FENCE, $line, $m)) {
                $info = trim($m[3]);
                if (preg_match('/^\{([A-Za-z0-9_-]+)\}\s*(.*)$/', $info, $d)) {
                    $directive = self::readDirective($lines, $i, strtolower($d[1]), trim($d[2]), [$m[2][0], strlen($m[2])]);
                    $i = $directive['end'];
                    foreach ($this->directive($directive) as $produced) {
                        $out[] = $produced;
                    }
                    continue;
                }
                $fence = [$m[2][0], strlen($m[2])];
                // A fence with no language is `text`: Sphinx draws every literal block in the
                // highlighter's box, and the blog renders a bare fence as a plain `<pre>` beside
                // the boxed ones - a tree diagram looked like it belonged to another site.
                // `text` is the highlighter's `raw`: the box, and no colours guessed at.
                $out[] = $info === '' ? $m[1].$m[2].self::BARE_FENCE : $line;
                continue;
            }
            if (preg_match(self::ATTRS_LINE, $line, $m)) {
                $pending = self::attributes($m[1]);
                continue;
            }
            if (preg_match(self::HEADING, $line, $m)) {
                $text = $m[2];
                $attributes = $pending;
                if (preg_match(self::INLINE_ATTRS, $text, $a)) {
                    // on the heading itself as well as before it: the heading's own id wins, and
                    // the classes are both
                    $inline = self::attributes($a[1]);
                    $attributes = [
                        'id' => $inline['id'] ?? $attributes['id'] ?? null,
                        'classes' => array_values(array_unique(array_merge($attributes['classes'] ?? [], $inline['classes']))),
                    ];
                    $text = rtrim(substr($text, 0, -strlen($a[0])));
                }
                $ids[] = $attributes;
                $pending = null;
                $out[] = $m[1].' '.$this->inline($text);
                continue;
            }
            if ($pending !== null && trim($line) !== '') {
                // a class on a paragraph is presentation only, and goes quietly; a label that is
                // gone is a `{ref}` that will not land, and is said
                if ($pending['id'] !== null) {
                    $this->problem("The label '{#{$pending['id']}}' is before something that is not a heading, and was left out.");
                }
                $pending = null;
            }
            $out[] = $this->inline($line);
        }
        return ['markdown' => implode("\n", $out), 'ids' => $ids];
    }

    // --- directives ---

    /**
     * Every directive block of a page, outside code blocks - what `SourceTree` reads toctrees from
     *
     * @return array[] ['name', 'argument', 'options' => [name => value], 'body' => string[], 'start', 'end']
     */
    public static function directives(string $markdown): array {
        $lines = preg_split('/\r\n|\r|\n/', $markdown);
        $found = [];
        $fence = null;
        $count = count($lines);
        for ($i = 0; $i < $count; $i++) {
            if ($fence !== null) {
                if (self::closes($lines[$i], $fence)) {
                    $fence = null;
                }
                continue;
            }
            if (!preg_match(self::FENCE, $lines[$i], $m)) {
                continue;
            }
            if (preg_match('/^\{([A-Za-z0-9_-]+)\}\s*(.*)$/', trim($m[3]), $d)) {
                $directive = self::readDirective($lines, $i, strtolower($d[1]), trim($d[2]), [$m[2][0], strlen($m[2])]);
                $found[] = $directive;
                $i = $directive['end'];
                continue;
            }
            $fence = [$m[2][0], strlen($m[2])];
        }
        return $found;
    }

    /**
     * One directive, from its opening fence to its closing one - or to the end of the page, when
     * somebody forgot it, which is what CommonMark does with an unclosed fence too
     */
    private static function readDirective(array $lines, int $start, string $name, string $argument, array $fence): array {
        $options = [];
        $body = [];
        $inOptions = true;
        $end = count($lines) - 1;
        for ($j = $start + 1; $j < count($lines); $j++) {
            if (self::closes($lines[$j], $fence)) {
                $end = $j;
                break;
            }
            if ($inOptions && preg_match('/^\s*:([A-Za-z0-9_-]+):\s*(.*)$/', $lines[$j], $o)) {
                $options[strtolower($o[1])] = trim($o[2]);
                continue;
            }
            $inOptions = false;
            $body[] = $lines[$j];
        }
        return ['name' => $name, 'argument' => $argument, 'options' => $options, 'body' => $body,
                'start' => $start, 'end' => $end];
    }

    private static function closes(string $line, array $fence): bool {
        [$char, $length] = $fence;
        return (bool)preg_match('/^\s{0,3}'.preg_quote($char, '/').'{'.$length.',}\s*$/', $line);
    }

    /** @return string[] the Markdown lines a directive becomes */
    private function directive(array $directive): array {
        $name = $directive['name'];
        if ($name === 'toctree') {
            return $this->toctree($directive);
        }
        if (isset(self::ADMONITIONS[$name])) {
            return $this->admonition(self::ADMONITIONS[$name], $directive);
        }
        $this->problem("The directive {{$name}} is not one this build knows; its text is shown as it is.");
        return array_merge(['```text'], $directive['body'], ['```']);
    }

    /**
     * A `toctree` is the list of links Sphinx draws in its place
     *
     * `:hidden:` draws nothing - the entries are still in the tree, only not listed here.
     * `:maxdepth:` nests each entry's own entries under it, that many levels; none is all of them.
     */
    private function toctree(array $directive): array {
        if (array_key_exists('hidden', $directive['options'])) {
            return [];
        }
        $depth = isset($directive['options']['maxdepth']) ? max(1, (int)$directive['options']['maxdepth']) : PHP_INT_MAX;
        $lines = [];
        if (($directive['options']['caption'] ?? '') !== '') {
            $lines[] = '**'.$directive['options']['caption'].'**';
            $lines[] = '';
        }
        foreach (SourceTree::toctrees("```{toctree}\n".implode("\n", $directive['body'])."\n```") as $toctree) {
            foreach ($toctree['entries'] as $entry) {
                $docname = SourceTree::resolve($this->context['docname'], $entry['target']);
                foreach ($this->listItem($docname, $entry['title'], 0, $depth) as $line) {
                    $lines[] = $line;
                }
            }
        }
        $lines[] = '';
        return $lines;
    }

    private function listItem(string $docname, ?string $title, int $level, int $depth): array {
        $url = ($this->context['url'])($docname);
        if ($url === null) {
            return []; // `SourceTree` has already reported a toctree entry with no file behind it
        }
        $text = self::escapeLinkText($title ?? ($this->context['title'])($docname));
        $lines = [str_repeat('  ', $level).'- ['.$text.']('.$url.')'];
        if ($level + 1 < $depth) {
            foreach (($this->context['children'])($docname) as $child) {
                foreach ($this->listItem($child, null, $level + 1, $depth) as $line) {
                    $lines[] = $line;
                }
            }
        }
        return $lines;
    }

    /**
     * An admonition is a callout: its argument, if it has one, on the marker's line; its body -
     * converted like the rest of the page - quoted under it
     */
    private function admonition(string $kind, array $directive): array {
        $lines = ['> [!'.$kind.']'.($directive['argument'] !== '' ? ' **'.$directive['argument'].'**' : '')];
        $body = (new self($this->context))->convert(implode("\n", $directive['body']))['markdown'];
        foreach (preg_split('/\n/', $body) as $line) {
            $lines[] = rtrim('> '.$line);
        }
        $lines[] = '';
        return $lines;
    }

    // --- inline ---

    /**
     * One line of prose: the roles first, since they are written with backticks; then, with the
     * code spans kept whole, the links to other pages and the `<br>`s
     */
    private function inline(string $line): string {
        $line = preg_replace_callback('/\{(ref|doc)\}`([^`]+)`/', fn(array $m) => $this->role($m[1], $m[2]), $line);
        $parts = preg_split('/(`+[^`]*?`+)/', $line, -1, PREG_SPLIT_DELIM_CAPTURE);
        foreach ($parts as $index => $part) {
            if ($index % 2 === 1) {
                continue; // a code span, left as written
            }
            // `![alt](path "title")`: the alt may hold escaped brackets, the path may be in `<>`
            $part = preg_replace_callback(
                '/(!\[(?:[^\[\]\\\\]|\\\\.)*\]\()\s*(<[^>]*>|[^()\s]+)(\s+"[^"]*")?\s*\)/',
                fn(array $m) => $this->image($m[0], $m[1], $m[2], $m[3] ?? ''),
                $part
            );
            $part = preg_replace_callback(
                '/\]\(\s*([^()\s]+?\.md)(#[^()\s]*)?\s*\)/i',
                fn(array $m) => $this->link($m[0], $m[1], $m[2] ?? ''),
                $part
            );
            $parts[$index] = preg_replace('/<br\s*\/?>/i', '{{ br() }}', $part);
        }
        return implode('', $parts);
    }

    /** ``{ref}`label` `` and ``{ref}`text <label>` ``, ``{doc}`path` `` and ``{doc}`text <path>` `` */
    private function role(string $role, string $content): string {
        [$text, $target] = preg_match('/^(.*\S)\s*<([^>]+)>$/', $content, $m) ? [trim($m[1]), trim($m[2])] : [null, trim($content)];
        if ($role === 'doc') {
            $docname = SourceTree::resolve($this->context['docname'], $target);
            $url = ($this->context['url'])($docname);
            if ($url === null) {
                $this->problem("{doc}`$content` names a page no toctree reaches.");
                return $text ?? $target;
            }
            return '['.self::escapeLinkText($text ?? ($this->context['title'])($docname)).']('.$url.')';
        }
        $label = $this->context['labels'][strtolower($target)] ?? $this->context['labels'][$target] ?? null;
        if ($label === null) {
            $this->problem("{ref}`$content` names a label that is not anywhere in the documentation.");
            return $text ?? $target;
        }
        $page = $label['docname'] === $this->context['docname'] ? '' : (($this->context['url'])($label['docname']) ?? '');
        return '['.self::escapeLinkText($text ?? $label['title']).']('.$page.'#'.$label['id'].')';
    }

    /**
     * An image in the source folder, to its address - or as it was written, with the reason it
     * has none in the report
     */
    private function image(string $whole, string $opening, string $target, string $title): string {
        $images = $this->context['images'] ?? null;
        $target = trim($target, '<>');
        if (!$images instanceof Images || !Images::isRelative($target)) {
            return $whole;
        }
        $published = $images->publish($this->context['docname'], $target);
        if (isset($published['problem'])) {
            $this->problem($published['problem']);
            return $whole;
        }
        return $opening.$published['url'].$title.')';
    }

    /** A relative link to another page's `.md`, to that page's address */
    private function link(string $whole, string $target, string $fragment): string {
        if (str_contains($target, '://') || str_starts_with($target, 'mailto:')) {
            return $whole;
        }
        $docname = SourceTree::resolve($this->context['docname'], $target);
        $url = ($this->context['url'])($docname);
        if ($url === null) {
            $this->problem("A link names $target, which no toctree reaches - it is left as it is.");
            return $whole;
        }
        return ']('.$url.$fragment.')';
    }

    private function problem(string $message): void {
        $this->context['report']->problem($this->context['docname'].'.md', $message);
    }

    private static function escapeLinkText(string $text): string {
        return str_replace(['[', ']'], ['\\[', '\\]'], $text);
    }

    // --- labels, across the whole documentation ---

    /**
     * The labels one page defines, with the heading each is on
     *
     * Read before any page is converted, so a `{ref}` can name a label on a page that comes later
     * in the tree.
     *
     * @return array<string, array{id: string, title: string}> lowercased label => the heading's id
     *         and its text
     */
    public static function labels(string $markdown): array {
        $found = [];
        $pending = null;
        $fence = null;
        foreach (preg_split('/\r\n|\r|\n/', $markdown) as $line) {
            if ($fence !== null) {
                if (self::closes($line, $fence)) {
                    $fence = null;
                }
                continue;
            }
            if (preg_match(self::FENCE, $line, $m)) {
                $fence = [$m[2][0], strlen($m[2])];
                continue;
            }
            if (preg_match(self::ATTRS_LINE, $line, $m)) {
                $pending = self::attributes($m[1])['id'];
                continue;
            }
            if (preg_match(self::HEADING, $line, $m)) {
                $text = $m[2];
                $inline = null;
                if (preg_match(self::INLINE_ATTRS, $text, $a)) {
                    $inline = self::attributes($a[1])['id'];
                    $text = substr($text, 0, -strlen($a[0]));
                }
                $label = $inline ?? $pending;
                if ($label !== null) {
                    $found[strtolower($label)] = ['id' => $label, 'title' => trim($text)];
                }
                $pending = null;
                continue;
            }
            if (trim($line) !== '') {
                $pending = null;
            }
        }
        return $found;
    }
}
