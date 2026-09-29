<?php

namespace Dynart\Docs\Build;

/**
 * The images of one build: which files the pages show, checked, and where each is served
 *
 * An image is written the way it is anywhere else - `![The palette](images/vga.png)`, relative to
 * the page's own file - so the page reads the same on GitHub and in any editor. The build turns
 * that into the file's address under the documentation's base, and the file itself **stays in
 * the source folder**: the site serves it from there (`DocsController`), and only the files a
 * published page asked for, which is the list this collects.
 *
 * A file is published when all of this holds, and is a problem against the page otherwise:
 *
 * - the path stays inside the source folder, and passes through no hidden folder (`.git`);
 * - it has no whitespace in it - Apache refuses a space in the address the front controller is
 *   rewritten to (`AH10411`), so the image would be a 403 whatever this did;
 * - it is a **png, jpg, gif or webp** - not SVG, which opened on the site's own address can run
 *   script;
 * - it is there, it is a file, and its real path is inside the source folder too (a link out);
 * - it is no larger than `MAX_BYTES`, and `getimagesize()` agrees it is what its name says.
 *
 * Its address carries `?v=` and the start of its hash, so it can be cached for good and a
 * changed image is a new address after the next build.
 */
class Images {

    /** What is served, by extension, and as what */
    const TYPES = [
        'png'  => ['mime' => 'image/png', 'type' => IMAGETYPE_PNG],
        'jpg'  => ['mime' => 'image/jpeg', 'type' => IMAGETYPE_JPEG],
        'jpeg' => ['mime' => 'image/jpeg', 'type' => IMAGETYPE_JPEG],
        'gif'  => ['mime' => 'image/gif', 'type' => IMAGETYPE_GIF],
        'webp' => ['mime' => 'image/webp', 'type' => IMAGETYPE_WEBP],
    ];

    const MAX_BYTES = 10 * 1024 * 1024;

    /** How much of the hash goes on the address */
    const VERSION_LENGTH = 12;

    /** @var array<string, array{hash: string, mime: string, width: int, height: int, url: string}> by path */
    private array $found = [];

    /**
     * @param string $folder the source folder
     * @param \Closure $url fn(string $path, string $version): string - the address of a path
     */
    public function __construct(private string $folder, private \Closure $url) {}

    /** Whether a path names a file of a type this serves - what tells an image's address from a page's */
    public static function isImagePath(string $path): bool {
        return isset(self::TYPES[strtolower(pathinfo($path, PATHINFO_EXTENSION))]);
    }

    /**
     * Whether a destination is a file in the source at all, rather than an address or a library
     * reference - which are left as they are written
     */
    public static function isRelative(string $target): bool {
        return $target !== ''
            && !str_contains($target, '://')
            && !str_starts_with($target, '//')
            && !preg_match('/^(data|mailto|javascript):/i', $target)
            && !preg_match('/^(media|post|page|content|category|tag)#\d+/', $target);
    }

    /**
     * A destination written on a page, as a path in the source folder
     *
     * Relative to the page's folder; from the source folder's root when it starts with `/`, which
     * is how Sphinx reads it too. Null when the `..`s climb out of the source folder.
     */
    public static function resolve(string $fromDocname, string $target): ?string {
        $target = rawurldecode(preg_replace('/[?#].*$/', '', $target));
        $base = str_starts_with($target, '/') ? '' : dirname($fromDocname);
        $parts = [];
        foreach (explode('/', ($base === '.' || $base === '' ? '' : $base.'/').ltrim($target, '/')) as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }
            if ($part === '..') {
                if ($parts === []) {
                    return null;
                }
                array_pop($parts);
                continue;
            }
            $parts[] = $part;
        }
        return $parts === [] ? null : implode('/', $parts);
    }

    /**
     * An image a page shows: its address, or why it has none
     *
     * @return array{url?: string, problem?: string}
     */
    public function publish(string $fromDocname, string $target): array {
        $path = self::resolve($fromDocname, $target);
        if ($path !== null && isset($this->found[$path])) {
            return ['url' => $this->found[$path]['url']];
        }
        $checked = self::inspect($this->folder, $path, $target);
        if (isset($checked['problem'])) {
            return $checked;
        }
        $hash = hash_file('sha256', $checked['file']);
        $url = ($this->url)($path, substr($hash, 0, self::VERSION_LENGTH));
        $this->found[$path] = [
            'hash'   => $hash,
            'mime'   => $checked['mime'],
            'width'  => $checked['width'],
            'height' => $checked['height'],
            'url'    => $url,
        ];
        return ['url' => $url];
    }

    /**
     * Whether a path in the source folder is an image this serves - every rule above, in one
     * place, for the build and for the editor's preview of a file no page shows yet
     *
     * @param ?string $path from `resolve()` - null when it climbed out of the folder
     * @param string $target as the page wrote it, for the message
     * @return array{problem?: string, file?: string, mime?: string, width?: int, height?: int}
     */
    public static function inspect(string $folder, ?string $path, string $target): array {
        if ($path === null) {
            return ['problem' => "The image $target leads outside the source folder; it is left out."];
        }
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if ($extension === 'svg') {
            return ['problem' => "The image $target is an SVG, which is not served - opened on this site's address it could run script. A PNG of it is."];
        }
        if (!isset(self::TYPES[$extension])) {
            return ['problem' => "The image $target is not a png, jpg, gif or webp; it is left out."];
        }
        foreach (explode('/', $path) as $part) {
            if (str_starts_with($part, '.')) {
                return ['problem' => "The image $target is in a hidden folder; it is left out."];
            }
        }
        if (preg_match('/\s/', $path)) {
            return ['problem' => "The image $target has a space in its path, which the web server refuses in an address; a - in its place works."];
        }
        $file = $folder.'/'.$path;
        if (!is_file($file)) {
            return ['problem' => "The image $target is not there."];
        }
        if (!self::inside($folder, $file)) {
            return ['problem' => "The image $target is a link to outside the source folder; it is left out."];
        }
        $size = (int)filesize($file);
        if ($size > self::MAX_BYTES) {
            return ['problem' => "The image $target is ".round($size / 1048576, 1).' MB, over the '.(self::MAX_BYTES / 1048576).' MB an image may be.'];
        }
        $info = @getimagesize($file);
        if ($info === false || $info[2] !== self::TYPES[$extension]['type']) {
            return ['problem' => "The image $target is not a $extension image, whatever its name says."];
        }
        return ['file' => $file, 'mime' => self::TYPES[$extension]['mime'], 'width' => (int)$info[0], 'height' => (int)$info[1]];
    }

    /** Whether a file's real path - links followed - is inside the folder's */
    public static function inside(string $folder, string $file): bool {
        $root = realpath($folder);
        $real = realpath($file);
        if ($root === false || $real === false) {
            return false;
        }
        $root = rtrim(str_replace('\\', '/', $root), '/').'/';
        $real = str_replace('\\', '/', $real);
        // a case-insensitive file system can answer either spelling of the folder
        return stripos($real, $root) === 0 && (PHP_OS_FAMILY === 'Windows' || str_starts_with($real, $root));
    }

    /**
     * The rendered page with each of its images' size, and `loading="lazy"`
     *
     * The size is what keeps the text from jumping down as an image arrives: with it the browser
     * saves the room before it has a byte of the file. Only this build's images are touched - an
     * image from anywhere else has no size known here.
     */
    public function decorate(string $html): string {
        if ($this->found === []) {
            return $html;
        }
        $byUrl = [];
        foreach ($this->found as $image) {
            $byUrl[$image['url']] = $image;
        }
        return preg_replace_callback('/<img\b([^>]*?)(\s*\/?)>/i', function (array $m) use ($byUrl) {
            if (!preg_match('/\ssrc="([^"]*)"/i', $m[1], $src)) {
                return $m[0];
            }
            $image = $byUrl[html_entity_decode($src[1], ENT_QUOTES | ENT_HTML5)] ?? null;
            if ($image === null) {
                return $m[0];
            }
            $attributes = $m[1];
            foreach (['width' => $image['width'], 'height' => $image['height'], 'loading' => 'lazy'] as $name => $value) {
                if (!preg_match('/\s'.$name.'=/i', $attributes)) {
                    $attributes .= ' '.$name.'="'.$value.'"';
                }
            }
            return '<img'.$attributes.$m[2].'>';
        }, $html);
    }

    /** @return array<string, array{hash: string, mime: string, width: int, height: int, url: string}> by path */
    public function found(): array {
        return $this->found;
    }
}
