<?php

namespace Dynart\Docs;

/**
 * The plugin's names, in one place
 */
class Docs {

    /** The folder the Markdown is read from - absolute, or relative to the site's root */
    const SOURCE = 'docs_source';

    /** The address the documentation lives under, without slashes: `docs` is `/docs/...` */
    const BASE = 'docs_base';
    const DEFAULT_BASE = 'docs';

    /** The settings screen's section the two go in */
    const SECTION = 'Documentation';

    /** The file a folder's page is, and the one the whole tree starts at */
    const INDEX = 'index';
}
