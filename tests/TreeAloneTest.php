<?php

use PHPUnit\Framework\TestCase;
use Dynart\Dpress\Entity\Block;
use Dynart\Docs\DocsContext;
use Dynart\Docs\DocsTreeBlock;
use Dynart\Docs\Entity\DocsPage;

final class TreeAloneTest extends TestCase {

    private function tree(bool $onDocsPage): DocsTreeBlock {
        $context = new DocsContext();
        $context->set($onDocsPage ? new DocsPage() : null);
        $block = (new ReflectionClass(DocsTreeBlock::class))->newInstanceWithoutConstructor();
        (new ReflectionProperty(DocsTreeBlock::class, 'context'))->setValue($block, $context);
        return $block;
    }

    private function blocks(string ...$types): array {
        return array_map(function (string $type): Block {
            $block = new Block();
            $block->type = $type;
            return $block;
        }, $types);
    }

    public function testOnADocumentationPageTheTreeIsAloneInItsPlace(): void {
        $blocks = $this->blocks('tag_cloud', DocsTreeBlock::TYPE, 'kofi');
        $this->tree(true)->onBeforeRender('sidebar', $blocks);
        $this->assertSame([DocsTreeBlock::TYPE], array_map(fn($b) => $b->type, $blocks));
    }

    public function testAPlaceWithoutTheTreeKeepsItsBlocks(): void {
        $blocks = $this->blocks('markdown', 'kofi');
        $this->tree(true)->onBeforeRender('footer', $blocks);
        $this->assertCount(2, $blocks);
    }

    public function testAnyOtherPageKeepsEverything(): void {
        $blocks = $this->blocks('tag_cloud', DocsTreeBlock::TYPE);
        $this->tree(false)->onBeforeRender('sidebar', $blocks);
        $this->assertCount(2, $blocks);
    }
}
