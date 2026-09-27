<?php

namespace Dynart\Docs;

use Dynart\Micro\Attribute\Route;
use Dynart\Micro\ConfigInterface;
use Dynart\Micro\JwtAuthInterface;
use Dynart\Micro\RequestInterface;
use Dynart\Micro\RouterInterface;
use Dynart\Micro\ViewInterface;
use Dynart\Dpress\Controller\Admin\AbstractAdminController;
use Dynart\Dpress\Form\FormFactory;
use Dynart\Dpress\Query\ListRequest;
use Dynart\Dpress\Security\Permissions;
use Dynart\Docs\Build\BuildReport;
use Dynart\Docs\Build\DocsBuilder;

/**
 * The Documentation screen: where the pages come from, how the last build went, and the tree
 *
 * Read-only apart from Build. The pages are the source folder's, so there is nothing to edit
 * here - a change is made in the Markdown and built.
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
            'columns'    => [
                'title'  => ['label' => 'Title', 'tree' => true, 'link' => 'view_url'],
                'path'   => ['label' => 'Address'],
                'source' => ['label' => 'Source'],
            ],
            'row_actions' => [
                ['title' => 'View', 'icon' => $this->icon('eye'), 'link' => 'view_url'],
            ],
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
                    'path'      => '/'.ltrim($this->builder->route($row['path']), '/'),
                    'source'    => $row['source'],
                    'view_url'  => $this->router->url($this->builder->route($row['path'])),
                ];
                $walk($row['id'], $depth + 1);
            }
        };
        $walk(0, 0);
        return $rows;
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
