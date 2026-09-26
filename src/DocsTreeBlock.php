<?php

namespace Dynart\Docs;

use Dynart\Micro\RouterInterface;
use Dynart\Micro\ViewInterface;
use Dynart\Dpress\Entity\Block;
use Dynart\Docs\Build\DocsBuilder;

/**
 * The *Documentation tree* block: the way around the documentation, in the sidebar
 *
 * The top of the tree always, and below it only the branch the page being read is on - open
 * down to the page and one level under it, the page itself marked. A whole tree of fifty pages
 * in a sidebar is a list nobody reads.
 *
 * **Draws nothing on a page that is not documentation**, so it can sit in the sidebar every page
 * shares without the blog's posts carrying a manual beside them.
 */
class DocsTreeBlock {

    public function __construct(
        protected ViewInterface $view,
        protected RouterInterface $router,
        protected DocsContext $context,
        protected DocsPages $pages,
        protected DocsBuilder $builder,
    ) {}

    /**
     * On a documentation page, the place the tree is in holds the tree and nothing else
     *
     * Subscribed to `block:before_render`. A tag cloud and a category list are the blog's way
     * around, and beside a manual they are noise; the other places - a footer - keep theirs, and
     * so does every page that is not documentation.
     *
     * @param Block[] $blocks
     */
    public function onBeforeRender(string $place, array &$blocks): void {
        if ($this->context->page() === null) {
            return;
        }
        $trees = array_filter($blocks, fn(Block $block): bool => $block->type === self::TYPE);
        if ($trees !== []) {
            $blocks = array_values($trees);
        }
    }

    /** The block's type, as `DocsPlugin::blocks()` names it */
    const TYPE = 'docs_tree';

    public function render(Block $block, array $settings): string {
        $page = $this->context->page();
        if ($page === null) {
            return '';
        }
        $children = $this->pages->children();
        $root = $children[0][0] ?? null;
        if ($root === null) {
            return '';
        }
        $open = array_column($this->pages->ancestors($page->id), 'id');
        $open[] = $page->id;
        return $this->view->fetch('docs:block/tree', [
            'items' => $this->items($children, $root['id'], $open, $page->id),
            'root'  => $this->item($root, $page->id),
        ]);
    }

    /**
     * The children of one page, each with its own children when it is on the open branch
     *
     * @param array<int, array[]> $children
     * @param int[] $open the ids from the root down to the current page
     */
    protected function items(array $children, int $parent, array $open, int $current): array {
        $items = [];
        foreach ($children[$parent] ?? [] as $row) {
            $item = $this->item($row, $current);
            $item['children'] = in_array($row['id'], $open, true)
                ? $this->items($children, $row['id'], $open, $current) : [];
            $item['has_children'] = !empty($children[$row['id']]);
            $items[] = $item;
        }
        return $items;
    }

    protected function item(array $row, int $current): array {
        return [
            'title'   => $row['title'],
            'url'     => $this->router->url($this->builder->route($row['path'])),
            'current' => $row['id'] === $current,
        ];
    }
}
