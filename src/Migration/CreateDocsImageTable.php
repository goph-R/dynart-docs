<?php

namespace Dynart\Docs\Migration;

use Dynart\Micro\Entities\MigrationInterface;
use Dynart\Micro\Entities\QueryExecutor;
use Dynart\Docs\Entity\DocsImage;

/** The images the built pages show - see `DocsImage` */
class CreateDocsImageTable implements MigrationInterface {

    public function __construct(private QueryExecutor $queryExecutor) {}

    public function version(): string {
        return '2026_09_29_001_create_docs_image_table';
    }

    public function up(): void {
        $this->queryExecutor->createTable(DocsImage::class, true);
    }
}
