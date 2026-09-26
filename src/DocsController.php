<?php

namespace Dynart\Docs;

use Dynart\Micro\ConfigInterface;
use Dynart\Micro\JwtAuthInterface;
use Dynart\Micro\RequestInterface;
use Dynart\Micro\RouterInterface;
use Dynart\Micro\ViewInterface;
use Dynart\Dpress\Controller\AbstractController;
use Dynart\Docs\Build\DocsBuilder;
use Dynart\Docs\Entity\DocsPage;

/**
 * The documentation, as a visitor reads it
 *
 * No `#[Route]`s: where the documentation lives is a setting, so `DocsPlugin::register()` adds
 * the two routes from it. The site's own `/*` does not swallow them because the router tries a
 * longer catch-all first (dynart-micro 0.20.3).
 *
 * **One page, one address.** Everything else that names a page is answered with a 301 to it:
 *
 * | asked for | answered with |
 * |---|---|
 * | `/docs/engine/VGA.html` - the address Sphinx published | `/docs/engine/VGA` |
 * | `/docs/engine/index.html`, `/docs/engine/index` | `/docs/engine` |
 * | `/docs/engine/vga` - another case | `/docs/engine/VGA` |
 */
class DocsController extends AbstractController {

    public function __construct(
        ViewInterface $view,
        RouterInterface $router,
        RequestInterface $request,
        ConfigInterface $config,
        JwtAuthInterface $jwtAuth,
        protected DocsPages $pages,
        protected DocsBuilder $builder,
        protected DocsContext $context,
    ) {
        parent::__construct($view, $router, $request, $config, $jwtAuth);
    }

    /** `/docs` - the root `index.md` */
    public function root(): string {
        return $this->page('');
    }

    /** `/docs/<path>` */
    public function page(string $path): string {
        $canonical = self::canonicalPath($path);
        $page = $this->pages->findByPath($canonical);
        if ($page === null) {
            $this->app()->sendError(404);
        }
        if ($page->path !== $path) {
            $this->app()->redirect($this->builder->route($page->path), [], 301);
        }
        return $this->renderPage($page);
    }

    /**
     * A path with what Sphinx's addresses carry and ours do not taken off: `.html`, and the
     * `index` a folder's own page is published as
     */
    public static function canonicalPath(string $path): string {
        $path = trim($path, '/');
        if (str_ends_with($path, '.html')) {
            $path = substr($path, 0, -5);
        }
        if ($path === Docs::INDEX) {
            return '';
        }
        if (str_ends_with($path, '/'.Docs::INDEX)) {
            return substr($path, 0, -strlen('/'.Docs::INDEX));
        }
        return $path;
    }

    /**
     * The page's own `<h1>` out of its body, as the theme's `entry-title`
     *
     * A source page begins with its title, so the built HTML does - but a theme draws a page's
     * title itself, above the prose and in its own size, and a post's and a page's title are
     * drawn that way. Its id stays on it, so an old `#dos-game-engine` link still lands.
     *
     * @return array{0: string, 1: string} the heading with `entry-title` added, or '' when the
     *                                     body does not open with one; the rest of the body
     */
    public static function splitTitle(string $html): array {
        if (preg_match('#^\s*<h1(\s[^>]*)?>(.*?)</h1>\s*#s', $html, $match) !== 1) {
            return ['', $html];
        }
        $attributes = $match[1] ?? '';
        if (preg_match('#\sclass="([^"]*)"#', $attributes) === 1) {
            $attributes = preg_replace('#\sclass="([^"]*)"#', ' class="entry-title $1"', $attributes, 1);
        } else {
            $attributes = ' class="entry-title"'.$attributes;
        }
        return ['<h1'.$attributes.'>'.$match[2].'</h1>', substr($html, strlen($match[0]))];
    }

    protected function renderPage(DocsPage $page): string {
        // for the tree block, which draws nothing on a page that is not documentation
        $this->context->set($page);
        // No way along from the root: it is the contents, and the contents is where a reader
        // chooses - "Next" under it would pick the first chapter for them. The pages keep theirs,
        // the first one's "Previous" included, which is the way back up to here.
        [$prev, $next] = $page->path === '' ? [null, null] : $this->pages->neighbours($page->id);
        [$titleHtml, $bodyHtml] = self::splitTitle((string)$page->html);
        return $this->render('docs:page', [
            'title'      => $page->title,
            'page'       => $page,
            'title_html' => $titleHtml,
            'body_html'  => $bodyHtml,
            'headings'  => json_decode((string)$page->headings, true) ?: [],
            'ancestors' => $this->linked($this->pages->ancestors($page->id)),
            'prev'      => $prev === null ? null : $this->linked([$prev])[0],
            'next'      => $next === null ? null : $this->linked([$next])[0],
        ], 'page');
    }

    /**
     * Outline rows with their `url`, since a template cannot know where the documentation lives
     *
     * @param array[] $rows
     * @return array[]
     */
    protected function linked(array $rows): array {
        return array_map(fn(array $row): array => $row + [
            'url' => $this->router->url($this->builder->route($row['path'])),
        ], $rows);
    }
}
