<?php

namespace Dynart\Docs\Migration;

use Dynart\Micro\Entities\MigrationInterface;
use Dynart\Micro\Entities\QueryExecutor;
use Dynart\Docs\Entity\DocsPage;

/**
 * The version sorts after every core migration, which is all the interleaving needs
 */
class CreateDocsPageTable implements MigrationInterface {

    public function __construct(private QueryExecutor $queryExecutor) {}

    public function version(): string {
        return '2026_09_26_001_create_docs_page_table';
    }

    public function up(): void {
        $this->queryExecutor->createTable(DocsPage::class, true);
    }
}
