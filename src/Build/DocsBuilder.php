<?php

namespace Dynart\Docs\Build;

use Dynart\Micro\ConfigInterface;
use Dynart\Micro\RouterInterface;
use Dynart\Micro\Entities\Database;
use Dynart\Micro\Entities\EntityManager;
use Dynart\Dpress\Content\MarkdownRenderer;
use Dynart\Dpress\Repository\RepositoryUpdater;
use Dynart\Dpress\Service\SettingService;
use Dynart\Docs\Docs;
use Dynart\Docs\Entity\DocsImage;
use Dynart\Docs\Entity\DocsPage;

/**
 * What `sphinx-build` does, through Dpress's own renderer
 *
 * 1. **Walk** the tree from the root `index.md` (`SourceTree`) - only what a `toctree` reaches.
 * 2. **Read** every page's title and labels first, so a `toctree` can list a page and a `{ref}`
 *    can name a label that come later in the tree.
 * 3. **Convert** each page's MyST (`Myst`), **render** it with `MarkdownRenderer` - so shortcodes,
 *    callouts, code highlighting and bare URLs are the blog's - and give its headings Sphinx's ids
 *    (`HeadingIds`).
 *    The images the pages show are checked and given their address on the way (`Images`).
 * 4. **Replace** the whole built tree, and the list of images, in one transaction: a page taken out of the source is taken
 *    off the site, and a build that fails half way shows nothing of itself.
 *
 * The addresses in the pages are **full URLs, resolved at build time** - the way a post's
 * `media#12` is resolved when it is saved - so a change of `app.base_url` or of the base path
 * wants a rebuild, as it wants `content:rerender` for the posts.
 */
class DocsBuilder {

    public function __construct(
        protected SettingService $settings,
        protected ConfigInterface $config,
        protected RouterInterface $router,
        protected MarkdownRenderer $markdown,
        protected EntityManager $em,
        protected Database $db,
        protected RepositoryUpdater $updater,
    ) {}

    /** The source folder the setting names, as a path on this machine, or '' when none is set */
    public function sourceFolder(): string {
        $source = trim((string)$this->settings->get(Docs::SOURCE, ''));
        if ($source === '') {
            return '';
        }
        if (str_starts_with($source, '~')) {
            return rtrim($this->config->getFullPath($source), '/\\');
        }
        // absolute on either system, or else from the site's root rather than from wherever the
        // command happened to be run
        if (preg_match('#^([A-Za-z]:[\\\\/]|[\\\\/])#', $source)) {
            return rtrim($source, '/\\');
        }
        return rtrim($this->config->rootPath(), '/\\').'/'.trim($source, '/\\');
    }

    /** The base path, without slashes: `docs` */
    public function base(): string {
        $base = trim((string)$this->settings->get(Docs::BASE, Docs::DEFAULT_BASE), "/ \t");
        return $base === '' ? Docs::DEFAULT_BASE : $base;
    }

    /**
     * What the last build left: how many pages, and when
     *
     * @return array{pages: int, built_at: ?string}
     */
    public function status(): array {
        $row = $this->db->fetch(
            'select count(1) as `pages`, max(`built_at`) as `built_at` from '.$this->em->safeTableName(DocsPage::class)
        );
        return ['pages' => (int)($row['pages'] ?? 0), 'built_at' => $row['built_at'] ?? null];
    }

    /**
     * The address of an image, from its path in the source folder - under the base, where the
     * page beside it is, with the start of its hash as the version
     */
    public function imageUrl(string $path, string $version): string {
        $encoded = implode('/', array_map('rawurlencode', explode('/', $path)));
        return $this->router->url($this->route($encoded), ['v' => $version]);
    }

    /** The route of a page, from its path below the base */
    public function route(string $path): string {
        return '/'.$this->base().($path !== '' ? '/'.$path : '');
    }

    /**
     * How the last build went, or null before the first one
     *
     * Not `status()`: a build that finds nothing to build leaves the pages it did not replace,
     * so the table can say "54 pages" while the last attempt said "the folder is not there" -
     * and the second is what somebody pressing Build wants to read.
     *
     * @return array{at: string, pages: int, problems: array[]}|null
     */
    public function lastBuild(): ?array {
        $last = json_decode((string)$this->settings->get(Docs::LAST_BUILD, ''), true);
        return is_array($last) && isset($last['at']) ? $last + ['pages' => 0, 'problems' => []] : null;
    }

    /**
     * Builds, and keeps how it went for `lastBuild()`
     *
     * With the *Update* setting on, the source is pulled with git first. A pull that fails is a
     * problem in the report and **not** the end of the build: what is in the folder is still the
     * last good source, and building it is no worse than not.
     *
     * `$pull = false` for the build after a save in the editor: that edit is not committed, and a
     * pull straight after it could only get in its way.
     */
    public function build(?string $folder = null, bool $pull = true): BuildReport {
        $report = new BuildReport();
        if ($pull) {
            $this->updateSource($folder ?? $this->sourceFolder(), $report);
        }
        $this->run($folder, $report);
        $this->settings->set(Docs::LAST_BUILD, json_encode([
            'at'       => gmdate('Y-m-d H:i:s'),
            'pages'    => $report->pages,
            // a broken source can have a problem on every line; a screen needs the first ones
            'problems' => array_slice($report->problems, 0, self::KEEP_PROBLEMS),
            'more'     => max(0, count($report->problems) - self::KEEP_PROBLEMS),
            'update'   => $report->update,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        return $report;
    }

    /** How many of a build's problems `lastBuild()` keeps */
    const KEEP_PROBLEMS = 50;

    /** Whether a build pulls the source with git first */
    public function pulls(): bool {
        return $this->settings->getBool(Docs::GIT_PULL, false);
    }

    /** The git pull a build starts with, when the setting asks for one */
    protected function updateSource(string $folder, BuildReport $report): void {
        if (!$this->pulls() || $folder === '' || !is_dir($folder)) {
            return;   // no folder is a problem the build itself reports
        }
        $result = $this->updater->update($folder);
        if ($result['ok']) {
            // said as the source, which is what somebody reading a build's report is asking about
            $report->update = $result['before'] === $result['after']
                ? "The source was up to date, at {$result['after']}."
                : "The source was updated from {$result['before']} to {$result['after']}.";
        } else {
            // a problem rather than the update line, so it is listed where problems are read
            $report->problem('', 'The source was not updated, so this is the last one pulled. '.$result['message']);
        }
    }

    protected function run(?string $folder, BuildReport $report): BuildReport {
        $folder = $folder ?? $this->sourceFolder();
        if ($folder === '' || !is_dir($folder)) {
            $report->problem('', $folder === ''
                ? 'No source folder is set.'
                : "The source folder $folder is not there.");
            return $report;
        }
        $nodes = (new SourceTree($folder, $report))->walk();
        if ($nodes === []) {
            return $report;
        }

        // every page's markdown, title and labels, before any of them is converted
        $sources = [];
        $titles = [];
        $labels = [];
        foreach ($nodes as $docname => $node) {
            $sources[$docname] = (string)file_get_contents($folder.'/'.$node['file']);
            if (preg_match('//u', $sources[$docname]) !== 1) {
                // the renderer refuses anything else, and would take the whole build down with it
                $report->problem($node['file'], 'The file is not valid UTF-8; the bytes that are not were replaced.');
                $sources[$docname] = mb_scrub($sources[$docname], 'UTF-8');
            }
            $titles[$docname] = self::rawTitle($sources[$docname]) ?? basename($docname);
            foreach (Myst::labels($sources[$docname]) as $key => $label) {
                if (isset($labels[$key])) {
                    $report->problem($node['file'], "The label '{$label['id']}' is defined in {$labels[$key]['docname']}.md too; the first one is used.");
                    continue;
                }
                $labels[$key] = $label + ['docname' => $docname];
            }
        }
        $images = new Images($folder, fn(string $path, string $version) => $this->imageUrl($path, $version));
        $context = [
            'report'   => $report,
            'images'   => $images,
            'url'      => fn(string $docname) => isset($nodes[$docname]) ? $this->router->url($this->route($nodes[$docname]['path'])) : null,
            'title'    => fn(string $docname) => $titles[$docname] ?? basename($docname),
            'children' => fn(string $docname) => $nodes[$docname]['children'] ?? [],
            'labels'   => $labels,
        ];

        $built = [];
        foreach ($nodes as $docname => $node) {
            $converted = (new Myst(['docname' => $docname] + $context))->convert($sources[$docname]);
            $rendered = HeadingIds::apply($images->decorate($this->markdown->render($converted['markdown'])), $converted['ids']);
            $built[$docname] = [
                'node'     => $node,
                'title'    => $rendered['title'] !== '' ? $rendered['title'] : $titles[$docname],
                'html'     => $rendered['html'],
                'headings' => $rendered['headings'],
                'hash'     => hash('sha256', $sources[$docname]),
            ];
        }
        $this->store($built, $images->found());
        $report->pages = count($built);
        return $report;
    }

    /**
     * The whole tree in place of the last one, or nothing at all
     *
     * Parents first - `SourceTree` gives them in reading order - so each child knows its parent's
     * new id when it is written.
     */
    protected function store(array $built, array $images = []): void {
        $this->db->runInTransaction(function () use ($built, $images) {
            $this->db->query('delete from '.$this->em->safeTableName(DocsPage::class));
            $this->db->query('delete from '.$this->em->safeTableName(DocsImage::class));
            $ids = [];
            $now = gmdate('Y-m-d H:i:s');
            foreach ($images as $path => $image) {
                $row = new DocsImage();
                $row->path = $path;
                $row->hash = $image['hash'];
                $row->mime = $image['mime'];
                $row->width = $image['width'];
                $row->height = $image['height'];
                $row->built_at = $now;
                $this->em->save($row);
            }
            foreach ($built as $docname => $page) {
                $node = $page['node'];
                $row = new DocsPage();
                $row->path = $node['path'];
                $row->title = $page['title'];
                $row->parent_id = $node['parent'] !== null ? ($ids[$node['parent']] ?? null) : null;
                $row->position = $node['position'];
                $row->sequence = $node['sequence'];
                $row->html = $page['html'];
                $row->headings = json_encode($page['headings'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                $row->source = $node['file'];
                $row->source_hash = $page['hash'];
                $row->built_at = $now;
                $this->em->save($row);
                $ids[$docname] = $row->id;
            }
        });
    }

    /** The first `# ` heading of a page's source, less any `{#id}` on it - the title a toctree lists */
    public static function rawTitle(string $markdown): ?string {
        $fence = null;
        foreach (preg_split('/\r\n|\r|\n/', $markdown) as $line) {
            // a `# comment` in a shell listing is not the page's title
            if (preg_match(Myst::FENCE, $line, $m)) {
                if ($fence === null) {
                    $fence = $m[2][0];
                } else if (trim($m[3]) === '' && $m[2][0] === $fence) {
                    $fence = null;
                }
                continue;
            }
            if ($fence === null && preg_match('/^\s{0,3}#\s+(.*?)\s*$/', $line, $m)) {
                return trim(preg_replace(Myst::INLINE_ATTRS, '', $m[1]));
            }
        }
        return null;
    }
}
