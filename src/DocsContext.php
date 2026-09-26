<?php

namespace Dynart\Docs;

use Dynart\Docs\Entity\DocsPage;

/**
 * Which documentation page is being read, if any
 *
 * The core's `PageContext` for this plugin's pages: a block renderer is handed nothing but its
 * own settings, and the tree block has to know which branch to open - and that it is on a
 * documentation page at all, since it draws nothing anywhere else.
 */
class DocsContext {

    private ?DocsPage $page = null;

    public function set(?DocsPage $page): void {
        $this->page = $page;
    }

    public function page(): ?DocsPage {
        return $this->page;
    }
}
