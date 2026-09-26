<?php

namespace Dynart\Docs;

use Dynart\Micro\EventServiceInterface;
use Dynart\Micro\Micro;
use Dynart\Micro\RouterInterface;
use Dynart\Dpress\Form\AdminForms;
use Dynart\Dpress\Form\FormFactory;
use Dynart\Dpress\Plugin\AbstractPlugin;
use Dynart\Dpress\Service\SettingFields;
use Dynart\Docs\Build\DocsBuilder;

/**
 * A documentation site from a folder of MyST Markdown
 *
 * What `sphinx-build` does for `docs-public`, done by the blog: the source folder's `toctree`s
 * make the tree, every page is rendered with the blog's own Markdown - shortcodes, callouts, code
 * highlighting - and the site's theme draws them. The files are the source and the only one; the
 * plugin's table is what the last build made of them. See DESIGN.md.
 */
class DocsPlugin extends AbstractPlugin {

    public function services(): array {
        return [
            DocsBuilder::class => DocsBuilder::class,
            DocsCommands::class => DocsCommands::class,
            DocsSettings::class => DocsSettings::class,
            DocsPages::class => DocsPages::class,
            DocsContext::class => DocsContext::class,
            DocsTreeBlock::class => DocsTreeBlock::class,
        ];
    }

    public function controllers(): array {
        return [DocsAdminController::class, DocsController::class];
    }

    public function views(): array {
        return ['docs' => dirname(__DIR__).'/views'];
    }

    /** The Build button's widget, in the settings - see `DocsSettings` */
    public function widgets(): array {
        return [DocsSettings::FIELD => 'docs:widget/build'];
    }

    /** The tree, in whichever place a site puts it - it draws only on a documentation page */
    public function blocks(): array {
        return [
            'docs_tree' => [
                'title'  => 'Documentation tree',
                'render' => [DocsTreeBlock::class, 'render'],
                'fields' => [],
            ],
        ];
    }

    /** `data-docs` is on the documentation page and on the tree, and nowhere else */
    public function pageAssets(): array {
        return ['docs.css' => 'data-docs'];
    }

    public function entities(): array {
        return [Entity\DocsPage::class];
    }

    public function migrations(): array {
        return [Migration\CreateDocsPageTable::class];
    }

    public function commands(): array {
        return [
            'docs:build' => [
                'callable'    => [DocsCommands::class, 'build'],
                'description' => 'Build the documentation from its source folder',
                'params'      => ['source'],
            ],
        ];
    }

    public function register(): void {
        $this->registerSettings();
        $this->registerRoutes();
        // the Build button, after the section's two settings
        Micro::get(EventServiceInterface::class)->subscribe(
            FormFactory::eventName(AdminForms::SETTINGS),
            [DocsSettings::class, 'onSettingsForm']
        );
    }

    /**
     * `/docs` and everything under it, wherever the setting puts them
     *
     * Here rather than in `#[Route]`s, which are constants, and the address is a setting.
     * `DocsController` is registered after this returns, and a route names its class, not an
     * instance, so the order does not matter.
     */
    protected function registerRoutes(): void {
        $base = Micro::get(DocsBuilder::class)->base();
        $router = Micro::get(RouterInterface::class);
        $router->add('/'.$base, [DocsController::class, 'root']);
        $router->add('/'.$base.'/*', [DocsController::class, 'page']);
    }

    /**
     * The source folder and the base path, in a *Documentation* section of the settings' Site tab
     */
    protected function registerSettings(): void {
        $fields = Micro::get(SettingFields::class);
        $fields->add(Docs::SOURCE, 'string', [
            'type' => 'text', 'label' => 'Source folder', 'required' => false,
            'section' => Docs::SECTION,
            'description' => 'The folder the documentation\'s Markdown is in, with index.md at its top.'
                .' Absolute, or from the site\'s root; ~/ is the site\'s root too.',
        ]);
        $fields->add(Docs::BASE, 'string', [
            'type' => 'text', 'label' => 'Address', 'required' => false,
            'section' => Docs::SECTION,
            'description' => 'What the documentation lives under - docs is /docs/... - . Rebuild after changing it.',
        ]);
    }
}
