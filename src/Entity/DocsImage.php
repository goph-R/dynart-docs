<?php

namespace Dynart\Docs\Entity;

use Dynart\Micro\Entities\Attribute\Column;
use Dynart\Micro\Entities\Attribute\Table;
use Dynart\Micro\Entities\Entity;

/**
 * An image a built page shows - and so one the site may serve
 *
 * **The file stays in the source folder**, the one place it is: this row is only the build's
 * word that a published page uses it, which is the whole list `DocsController` serves from. A
 * file no page references is not on it, and is not served, however it got into the repository.
 * Replaced with the pages, in the same transaction, on every build.
 */
#[Table(name: 'docs_image')]
class DocsImage extends Entity {

    protected static string $eventName = 'docs_image';

    #[Column(type: Column::TYPE_INT, primaryKey: true, autoIncrement: true, notNull: true)]
    public int $id = 0;

    /** Relative to the source folder, in its own case: `dos-game-engine/BASICS/images/vga.png` */
    #[Column(type: Column::TYPE_STRING, size: 255, notNull: true, unique: true)]
    public string $path = '';

    /** sha256 of the file when it was built - the `?v=` on its address is the start of it */
    #[Column(type: Column::TYPE_STRING, size: 64, notNull: true)]
    public string $hash = '';

    #[Column(type: Column::TYPE_STRING, size: 32, notNull: true)]
    public string $mime = '';

    #[Column(type: Column::TYPE_INT, notNull: true, default: 0)]
    public int $width = 0;

    #[Column(type: Column::TYPE_INT, notNull: true, default: 0)]
    public int $height = 0;

    #[Column(type: Column::TYPE_DATETIME)]
    public ?string $built_at = null;
}
