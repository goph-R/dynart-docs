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
 * The build, from the admin - what the Build button in the settings posts to
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
    ) {
        parent::__construct($view, $router, $request, $config, $jwtAuth, $forms, $list);
    }

    protected function section(): string {
        return 'settings';
    }

    /**
     * Builds from the saved settings and goes back to them, with how it went as the notice
     *
     * Behind the permission that saves the settings: a build replaces every page of the
     * documentation, which is at least as much of a change to the site as a setting is.
     */
    #[Route('POST', '/admin/docs/build')]
    public function build(): string {
        $this->requirePermission(Permissions::SETTING_UPDATE);
        $this->requireAction();
        $this->done('/admin/settings', $this->summary($this->builder->build()));
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
        if ($report->pages === 0) {
            return 'Nothing was built.'.$tail;
        }
        return "Built {$report->pages} page(s) under /".$this->builder->base().'.'
            .($problems === [] ? '' : ' '.count($report->problems).' thing(s) to look at:'.$tail);
    }
}
