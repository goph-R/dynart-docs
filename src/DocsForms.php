<?php

namespace Dynart\Docs;

use Dynart\Dpress\Form\DpressForm;

/**
 * The editor's form: the page's source, and the fingerprint of the file as it was opened
 */
class DocsForms {

    /**
     * @param array $context `markdown` - the file's text; `hash` - `RepositoryFiles::hash()` of it;
     *                       `preview_url` - where *Preview media* asks for a relative image
     */
    public function page(DpressForm $form, array $context): void {
        $form->addFields([
            'markdown' => ['type' => 'markdown', 'label' => 'Source', 'numbers' => true,
                           'description' => 'The Markdown file itself, MyST and all - saving writes it back as it is here.',
                           // a relative image is the page's folder in the source, not the site's
                           'attributes' => ['data-relative-preview' => (string)($context['preview_url'] ?? '')]],
            // what the file was when this form was drawn: a save onto a file that has changed since
            // - a pull, an edit on the server - is refused rather than written over it
            'hash'     => ['type' => 'hidden', 'required' => false],
        ]);
        $form->addValues([
            'markdown' => (string)($context['markdown'] ?? ''),
            'hash'     => (string)($context['hash'] ?? ''),
        ]);
    }
}
