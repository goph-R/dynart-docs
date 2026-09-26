<?php

namespace Dynart\Docs;

use Dynart\Micro\RouterInterface;
use Dynart\Dpress\Form\DpressForm;
use Dynart\Docs\Build\DocsBuilder;

/**
 * The Build button, in the Documentation section of the settings
 *
 * A field of the settings form in shape only: its type is a widget of this plugin's that draws a
 * button and what the last build left, and it is not a setting - `SettingFields` does not know it,
 * so the settings screen never writes it. The button posts elsewhere, to `DocsAdminController`.
 */
class DocsSettings {

    /** The field's name, and the widget type that draws it */
    const FIELD = 'docs_build';

    public function __construct(
        protected RouterInterface $router,
        protected DocsBuilder $builder,
    ) {}

    /**
     * Subscribed to `form.admin_settings:created`, so it lands after the section's two settings
     */
    public function onSettingsForm(DpressForm $form, array $context = []): void {
        $form->addFields([
            self::FIELD => [
                'type'        => self::FIELD,
                'label'       => 'Build',
                'required'    => false,
                'section'     => Docs::SECTION,
                'url'         => $this->router->url('/admin/docs/build', ['back' => 'settings']),
                'status'      => $this->statusText(),
                'description' => 'Builds the documentation from the saved source folder - if you have just changed the settings, save first.',
            ],
        ], false);
    }

    protected function statusText(): string {
        $status = $this->builder->status();
        if ($status['pages'] === 0) {
            return 'Not built yet.';
        }
        return $status['pages'].' page(s), built '.substr((string)$status['built_at'], 0, 16).' UTC.';
    }
}
