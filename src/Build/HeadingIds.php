<?php

namespace Dynart\Docs\Build;

/**
 * Ids on a rendered page's headings, the ones Sphinx gave them
 *
 * An old `docs.dynart.net/.../VGA.html#palette` is redirected here with its `#palette`, and lands
 * only if the heading has that id - so it is **docutils' rule**, not one of this CMS's own:
 *
 * - lowercase, every run of characters that is not a letter or a digit one `-`, none at either
 *   end, and no digits or hyphens at the start (`make_id`): `## VGA Graphics` is `vga-graphics`,
 *   `#### Example:` is `example`;
 * - a second heading with the same text - and one whose text leaves nothing - is `id1`, `id2`,
 *   ... in the order they come, one counter for the page, which is what `_build/html` shows;
 * - an explicit `{#id}` is used as it is, and counts as taken.
 */
class HeadingIds {

    /**
     * @param array $explicit each heading's attributes, in order, from `Myst::convert()`: null, an
     *                        id, or `['id' => ?string, 'classes' => string[]]`
     * @return array ['html' => string, 'headings' => [['level', 'text', 'id']], 'title' => string]
     */
    public static function apply(string $html, array $explicit = []): array {
        $explicit = array_map(fn($given) => is_array($given)
            ? ['id' => $given['id'] ?? null, 'classes' => $given['classes'] ?? []]
            : ['id' => $given, 'classes' => []], $explicit);
        $taken = array_fill_keys(array_filter(array_column($explicit, 'id'), fn($id) => $id !== null), true);
        $headings = [];
        $counter = 1;
        $index = 0;
        $html = preg_replace_callback('/<h([1-6])((?:\s[^>]*)?)>(.*?)<\/h\1>/s',
            function (array $m) use ($explicit, &$taken, &$headings, &$counter, &$index) {
                $text = trim(html_entity_decode(strip_tags($m[3]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                $given = $explicit[$index++] ?? ['id' => null, 'classes' => []];
                if (preg_match('/\sid="([^"]*)"/', $m[2], $existing)) {
                    $id = $existing[1];
                    $attributes = $m[2];
                } else {
                    $id = $given['id'] ?? self::slug($text);
                    if ($given['id'] === null && ($id === '' || isset($taken[$id]))) {
                        do {
                            $id = 'id'.$counter++;
                        } while (isset($taken[$id]));
                    }
                    $attributes = ' id="'.htmlspecialchars($id, ENT_QUOTES).'"'.$m[2];
                }
                // the classes MyST puts on the section go on its heading - `.numbered-header` is
                // what the old site numbered the Terms' headings by, and a theme can do the same
                if ($given['classes'] !== []) {
                    $attributes .= ' class="'.htmlspecialchars(implode(' ', $given['classes']), ENT_QUOTES).'"';
                }
                $taken[$id] = true;
                $headings[] = ['level' => (int)$m[1], 'text' => $text, 'id' => $id];
                return '<h'.$m[1].$attributes.'>'.$m[3].'</h'.$m[1].'>';
            }, $html);
        $title = '';
        foreach ($headings as $heading) {
            if ($heading['level'] === 1) {
                $title = $heading['text'];
                break;
            }
        }
        return ['html' => $html, 'headings' => $headings, 'title' => $title];
    }

    /**
     * docutils' `make_id`, as far as a heading's text needs it
     *
     * docutils decomposes the text and **drops** what is left that is not ASCII - so `é` is `e`,
     * and a typographic `’` is gone rather than a `-`: "Children’s Privacy" is `childrens-privacy`.
     * Without `intl` there is no decomposition to call, so each character that is not ASCII is
     * transliterated on its own and kept only if that gives letters or digits - the same answer
     * for the accents and the punctuation a heading has.
     */
    public static function slug(string $text): string {
        $ascii = preg_replace_callback('/[^\x00-\x7F]/u', function (array $m) {
            // only the letters and digits of what it becomes: iconv spells `é` as `'e` on some
            // systems and `e` on others, and a `’` as `'` - which is nothing, as docutils has it
            $plain = function_exists('iconv') ? (string)@iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $m[0]) : '';
            return preg_replace('/[^A-Za-z0-9]/', '', $plain);
        }, $text);
        $id = preg_replace('/[^a-z0-9]+/', '-', strtolower((string)$ascii));
        return preg_replace('/^[-0-9]+|-+$/', '', trim($id, '-'));
    }
}
