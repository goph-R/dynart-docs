<?php

namespace Dynart\Docs;

use Dynart\Micro\Attribute\Route;
use Dynart\Micro\ConfigInterface;
use Dynart\Micro\JwtAuthInterface;
use Dynart\Micro\Micro;
use Dynart\Micro\RequestInterface;
use Dynart\Micro\ResponseInterface;
use Dynart\Micro\RouterInterface;
use Dynart\Micro\ViewInterface;
use Dynart\Dpress\Controller\Admin\AbstractAdminController;
use Dynart\Dpress\Form\FormFactory;
use Dynart\Dpress\Query\ListRequest;
use Dynart\Dpress\Security\Permissions;
use Dynart\Docs\Build\BuildReport;
use Dynart\Docs\Build\DocsBuilder;
use Dynart\Docs\Build\Images;
use Dynart\Docs\Build\SourceStatus;
use Dynart\Dpress\DpressException;

/**
 * The Documentation screen: where the pages come from, how the last build went, and the tree
 *
 * Build, and an editor for a page's source file (`edit()`): it writes the Markdown back into the
 * source folder and rebuilds. It does not commit - so every page shows whether its file differs
 * from the remote, and the editor says it should be committed while it does (`SourceStatus`).
 *
 * Behind the permission that reads the settings, since everything it shows is what two
 * settings and the last build made of them.
 */
class DocsAdminController extends AbstractAdminController {

    /** How many of a build's problems the notice after it names; the rest are a count */
    const NOTICE_PROBLEMS = 3;

    public function __construct(
        ViewInterface $view,
        RouterInterface $router,
        RequestInterface $request,
        ConfigInterface $config,
        JwtAuthInterface $jwtAuth,
        FormFactory $forms,
        ListRequest $list,
        protected DocsBuilder $builder,
        protected DocsPages $pages,
        protected SourceStatus $sourceStatus,
    ) {
        parent::__construct($view, $router, $request, $config, $jwtAuth, $forms, $list);
    }

    protected function section(): string {
        return Docs::ADMIN_SECTION;
    }

    /** Where a Build may go back to: this screen, or the settings its button is also on */
    const BACK = ['docs' => '/admin/docs', 'settings' => '/admin/settings'];

    #[Route('GET', '/admin/docs')]
    public function index(): string {
        $this->requirePermission(Permissions::SETTING_VIEW);
        $canBuild = $this->can(Permissions::SETTING_UPDATE);
        $rowActions = [];
        if ($this->can(Docs::PERMISSION_EDIT)) {
            $rowActions[] = ['title' => 'Edit', 'icon' => $this->icon('edit'), 'link' => 'edit_url'];
        }
        $rowActions[] = ['title' => 'View', 'icon' => $this->icon('eye'), 'link' => 'view_url'];
        return $this->admin('docs:admin/index', [
            'title'      => 'Documentation',
            'source'     => $this->builder->sourceFolder(),
            'pulls'      => $this->builder->pulls(),
            'public_url' => $this->router->url($this->builder->route('')),
            'last'       => $this->builder->lastBuild(),
            'status'     => $this->builder->status(),
            'build_url'  => $canBuild ? $this->router->url('/admin/docs/build', ['back' => 'docs']) : '',
            'settings_url' => $this->router->url('/admin/settings'),
            'rows'       => $this->treeRows(),
            // git is asked after the screen is drawn - see `changes()`
            'changes_url' => $this->router->url('/admin/docs/changes'),
            'columns'    => [
                'title'  => ['label' => 'Title', 'tree' => true,
                             'link' => $this->can(Docs::PERMISSION_EDIT) ? 'edit_url' : 'view_url'],
                // no Address column: it is the source's path again without `.md`, and the View
                // action is the way to the page
                'source' => ['label' => 'Source'],
                'git'    => ['label' => '', 'view' => 'html'],
            ],
            'row_actions' => $rowActions,
        ]);
    }

    /**
     * The built pages, depth first, with the depth the tree partial indents by
     */
    protected function treeRows(): array {
        $children = $this->pages->children();
        $rows = [];
        $walk = function (int $parent, int $depth) use (&$walk, &$rows, $children): void {
            foreach ($children[$parent] ?? [] as $row) {
                $rows[] = [
                    'id'        => $row['id'],
                    'parent_id' => $row['parent_id'],
                    'depth'     => $depth,
                    'title'     => $row['title'],
                    'source'    => $row['source'],
                    'view_url'  => $this->router->url($this->builder->route($row['path'])),
                    'edit_url'  => $this->router->url('/admin/docs/edit', ['file' => $row['source']]),
                    'git'       => '',   // filled in by docs-admin.js
                ];
                $walk($row['id'], $depth + 1);
            }
        };
        $walk(0, 0);
        return $rows;
    }

    /**
     * What differs from the remote, for the screen that asked - fetched by `docs-admin.js`
     *
     * Its own request so the list and the editor never wait for git: the screen is drawn at once,
     * and the warning and the tree's badges arrive a moment later. JSON, with the warning already
     * rendered so its words and markup stay in a template.
     */
    #[Route('GET', '/admin/docs/changes')]
    public function changes(): array {
        if (!$this->can(Permissions::SETTING_VIEW) && !$this->can(Docs::PERMISSION_EDIT)) {
            $this->app()->sendError(403);
        }
        $changes = $this->sourceStatus->changes($this->builder->sourceFolder());
        return [
            'badges' => array_filter(array_map(fn(string $state) => self::gitBadge($state), $changes ?? [])),
            'html'   => $this->view->fetch('docs:admin/changes', [
                'changes' => $changes,
                'file'    => (string)$this->request->get('file', ''),
            ]),
        ];
    }

    /** What the tree says about a page's file: nothing, or that it differs from the remote */
    public static function gitBadge(?string $state): string {
        return match ($state) {
            SourceStatus::UNCOMMITTED => '<span class="badge badge-draft">not committed</span>',
            SourceStatus::UNPUSHED    => '<span class="badge badge-draft">not pushed</span>',
            default                   => '',
        };
    }

    /**
     * A page's source file, in the Markdown editor
     *
     * The file is found through the page the build made of it - `?file=` is only ever looked up,
     * never opened as a path - so what can be edited is exactly what the site publishes.
     *
     * **Saving writes the file and rebuilds, and nothing more**: no commit and no push, so the
     * screen says - above the editor, for as long as it is true - that the file differs from the
     * remote and should be committed. The rebuild does not pull, which could only get in the way
     * of the edit it was made for.
     */
    #[Route('GET', '/admin/docs/edit')]
    #[Route('POST', '/admin/docs/edit')]
    public function edit(): string {
        $this->requirePermission(Docs::PERMISSION_EDIT);
        $source = (string)$this->request->get('file', '');
        $page = $source !== '' ? $this->pages->findBySource($source) : null;
        if ($page === null) {
            $this->app()->sendError(404);
        }
        $files = new SourceFiles($this->builder->sourceFolder());
        try {
            $text = $files->read($source);
        } catch (DpressException $e) {
            $this->done('/admin/docs', $e->getMessage());
            return '';
        }
        // registered here rather than in `register()`: the form factory is the web app's, and a
        // plugin that asked for it while loading failed to load in every `dpress` command
        if (!$this->forms->has(Docs::FORM_PAGE)) {
            $this->forms->add(Docs::FORM_PAGE, [DocsForms::class, 'page']);
        }
        $form = $this->forms->create(Docs::FORM_PAGE, [
            'markdown'    => $text,
            'hash'        => SourceFiles::hash($text),
            'preview_url' => $this->router->url('/admin/docs/file', ['page' => $source]),
        ]);
        if ($form->process()) {
            $values = $form->values();
            try {
                $written = $files->write($source, (string)$values['markdown'], (string)$values['hash']);
            } catch (DpressException $e) {
                $form->addFieldError('markdown', $e->getMessage());
                return $this->editor($page, $source, $form);
            }
            $notice = $written ? 'Saved. '.$this->summary($this->builder->build(null, false)) : 'Nothing changed.';
            $this->done('/admin/docs/edit', $notice, ['file' => $source]);
            return '';
        }
        return $this->editor($page, $source, $form);
    }

    /**
     * An image beside a page's source, for the editor's *Preview media* (Dpress 0.85.0)
     *
     * `?page=` is the page's source file, looked up like `edit()`'s, and `?path=` the image as
     * the page writes it, resolved against that file with the build's own rules
     * (`Images::inspect()`) - so it answers for a file no page shows yet, which is the one a
     * preview is for, and never for anything the build would not publish. Not kept: it is being
     * worked on.
     */
    #[Route('GET', '/admin/docs/file')]
    public function file(): string {
        $this->requirePermission(Docs::PERMISSION_EDIT);
        $source = (string)$this->request->get('page', '');
        $page = $source !== '' ? $this->pages->findBySource($source) : null;
        $folder = $this->builder->sourceFolder();
        $target = (string)$this->request->get('path', '');
        $checked = $page === null || $folder === '' || !Images::isRelative($target)
            ? ['problem' => 'no page']
            : Images::inspect($folder, Images::resolve(preg_replace('/\.md$/i', '', $source), $target), $target);
        if (isset($checked['problem'])) {
            $this->app()->sendError(404);
            return '';
        }
        $response = Micro::get(ResponseInterface::class);
        $response->setHeader('Content-Type', $checked['mime']);
        $response->setHeader('Cache-Control', 'no-store');
        $response->setHeader('X-Content-Type-Options', 'nosniff');
        $response->send((string)file_get_contents($checked['file']));
        $this->app()->finish();
        return '';
    }

    protected function editor(\Dynart\Docs\Entity\DocsPage $page, string $source, $form): string {
        return $this->admin('docs:admin/edit', [
            'title'    => $page->title,
            'source'   => $source,
            'changes_url' => $this->router->url('/admin/docs/changes', ['file' => $source]),
            'form'     => $form,
            'view_url' => $this->router->url($this->builder->route($page->path)),
            'back_url' => $this->router->url('/admin/docs'),
        ]);
    }

    /**
     * Builds from the saved settings and goes back where it came from, with how it went as the
     * notice - this screen, or the settings
     *
     * Behind the permission that saves the settings: a build replaces every page of the
     * documentation, which is at least as much of a change to the site as a setting is.
     */
    #[Route('POST', '/admin/docs/build')]
    public function build(): string {
        $this->requirePermission(Permissions::SETTING_UPDATE);
        $this->requireAction();
        $back = self::BACK[(string)$this->request->get('back', '')] ?? self::BACK['settings'];
        $this->done($back, $this->summary($this->builder->build()));
        return '';
    }

    /**
     * One line: how many pages, and the first few problems - `dpress docs:build` prints them all
     */
    protected function summary(BuildReport $report): string {
        $problems = array_map(
            fn(array $p) => ($p['file'] !== '' ? $p['file'].': ' : '').$p['message'],
            array_slice($report->problems, 0, self::NOTICE_PROBLEMS)
        );
        $more = count($report->problems) - count($problems);
        $tail = $problems === [] ? '' : ' '.implode(' ', $problems).($more > 0 ? " And $more more - dpress docs:build lists them all." : '');
        // what the git pull did, first - it is what decided which source this was built from
        $update = $report->update !== '' ? $report->update.' ' : '';
        if ($report->pages === 0) {
            return $update.'Nothing was built.'.$tail;
        }
        return $update."Built {$report->pages} page(s) under /".$this->builder->base().'.'
            .($problems === [] ? '' : ' '.count($report->problems).' thing(s) to look at:'.$tail);
    }
}
