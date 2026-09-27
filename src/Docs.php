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

    /**
     * What the last build left, as JSON: `at`, `pages`, `problems` - a setting nobody edits,
     * written by every build, from the admin or from `dpress docs:build`
     */
    const LAST_BUILD = 'docs_last_build';

    /** Whether a build first pulls the source folder with git - see `SourceUpdater` */
    const GIT_PULL = 'docs_git_pull';

    /** The admin navigation's key for the Documentation screen */
    const ADMIN_SECTION = 'docs';
}
