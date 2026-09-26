<?php

namespace Dynart\Docs\Entity;

use Dynart\Micro\Entities\Attribute\Column;
use Dynart\Micro\Entities\Attribute\Table;
use Dynart\Micro\Entities\Entity;

/**
 * One built page of the documentation
 *
 * **Not the source.** The Markdown in the source folder is; this is what the last build made of
 * it, and the next build replaces every row. So nothing here is edited, nothing is audited, and
 * nothing points at it from the CMS's own tables - a plugin that adds columns to `content` is a
 * plugin you cannot uninstall, and one whose rows are thrown away on every build has no reason to.
 */
#[Table(name: 'docs_page')]
class DocsPage extends Entity {

    protected static string $eventName = 'docs_page';

    #[Column(type: Column::TYPE_INT, primaryKey: true, autoIncrement: true, notNull: true)]
    public int $id = 0;

    /**
     * The address below the base, in the source's own case, without `.md`: `dos-game-engine/BASICS/VGA`.
     * A folder's `index.md` is the folder - `dos-game-engine` - and the root one is ''.
     */
    #[Column(type: Column::TYPE_STRING, size: 255, notNull: true, unique: true)]
    public string $path = '';

    #[Column(type: Column::TYPE_STRING, size: 255, notNull: true)]
    public string $title = '';

    /** The page whose `toctree` lists this one; null for the root */
    #[Column(type: Column::TYPE_INT)]
    public ?int $parent_id = null;

    /** Where among its parent's entries, from 0 */
    #[Column(type: Column::TYPE_INT, notNull: true, default: 0)]
    public int $position = 0;

    /** Where in the whole tree read top to bottom, from 0 - what previous and next follow */
    #[Column(type: Column::TYPE_INT, notNull: true, default: 0)]
    public int $sequence = 0;

    #[Column(type: Column::TYPE_STRING)]
    public ?string $html = null;

    /** JSON: `[{"level": 2, "text": "...", "id": "..."}]`, for an "on this page" list */
    #[Column(type: Column::TYPE_STRING)]
    public ?string $headings = null;

    /** The file it was built from, relative to the source folder */
    #[Column(type: Column::TYPE_STRING, size: 255, notNull: true)]
    public string $source = '';

    #[Column(type: Column::TYPE_STRING, size: 64, notNull: true)]
    public string $source_hash = '';

    #[Column(type: Column::TYPE_DATETIME, notNull: true)]
    public ?string $built_at = null;
}
