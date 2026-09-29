<?php

namespace Dynart\Docs;

use Dynart\Micro\Entities\Database;
use Dynart\Micro\Entities\EntityManager;
use Dynart\Docs\Entity\DocsImage;
use Dynart\Docs\Entity\DocsPage;

/**
 * The built tree, as the public side reads it
 *
 * Two queries at most for a page: the page itself with its HTML, and the tree without any -
 * which is everything breadcrumbs, previous / next and the tree block need, and small enough
 * (a row per page, no bodies) to read whole once per request.
 */
class DocsPages {

    /** @var array<int, array>|null the tree without its HTML, keyed by id, in reading order */
    private ?array $outline = null;

    public function __construct(
        protected Database $db,
        protected EntityManager $em,
    ) {}

    /**
     * The page at a path, matched regardless of case - a caller that got a different spelling
     * back redirects to the one it got
     */
    public function findByPath(string $path): ?DocsPage {
        $rows = $this->db->fetchAll(
            'select * from '.$this->table().' where lower(`path`) = lower(:path)',
            [':path' => $path], DocsPage::class
        );
        foreach ($rows as $row) {
            if ($row->path === $path) {
                return $row;
            }
        }
        return $rows[0] ?? null;
    }

    /** The page built from a source file, by its path in the source folder - `index.md` */
    public function findBySource(string $source): ?DocsPage {
        $page = $this->db->fetch(
            'select * from '.$this->table().' where `source` = :source', [':source' => $source], DocsPage::class
        );
        return $page instanceof DocsPage ? $page : null;
    }

    /**
     * Every page without its HTML: `id`, `path`, `title`, `parent_id`, `position`, `sequence`, `source`
     *
     * @return array<int, array> keyed by id, in reading order
     */
    public function outline(): array {
        if ($this->outline === null) {
            $this->outline = [];
            $rows = $this->db->fetchAll(
                'select `id`, `path`, `title`, `parent_id`, `position`, `sequence`, `source` from '.$this->table()
                .' order by `sequence`'
            );
            foreach ($rows as $row) {
                $row['id'] = (int)$row['id'];
                $row['parent_id'] = $row['parent_id'] === null ? null : (int)$row['parent_id'];
                $this->outline[$row['id']] = $row;
            }
        }
        return $this->outline;
    }

    /**
     * The pages above one, the root first, the page itself not included
     *
     * @return array[] outline rows
     */
    public function ancestors(int $id): array {
        $outline = $this->outline();
        $found = [];
        $parent = $outline[$id]['parent_id'] ?? null;
        // bounded by the size of the tree, so a loop in the data cannot hang a request
        while ($parent !== null && isset($outline[$parent]) && count($found) < count($outline)) {
            array_unshift($found, $outline[$parent]);
            $parent = $outline[$parent]['parent_id'];
        }
        return $found;
    }

    /**
     * The pages before and after one in reading order, as Sphinx's theme links them
     *
     * @return array{0: ?array, 1: ?array} outline rows, `null` at either end
     */
    public function neighbours(int $id): array {
        $ids = array_keys($this->outline());
        $at = array_search($id, $ids, true);
        if ($at === false) {
            return [null, null];
        }
        $outline = $this->outline();
        return [
            $at > 0 ? $outline[$ids[$at - 1]] : null,
            $at < count($ids) - 1 ? $outline[$ids[$at + 1]] : null,
        ];
    }

    /**
     * The children of each page, in their toctree's order
     *
     * @return array<int, array[]> keyed by parent id; the root's parent is `0`
     */
    public function children(): array {
        $children = [];
        foreach ($this->outline() as $row) {
            $children[$row['parent_id'] ?? 0][] = $row;
        }
        foreach ($children as &$list) {
            usort($list, fn(array $a, array $b): int => $a['position'] <=> $b['position']);
        }
        return $children;
    }

    /**
     * An image the last build published, by its path in the source folder - in exactly that case,
     * since a file system that minds case would not find another spelling of it
     */
    public function findImage(string $path): ?DocsImage {
        $rows = $this->db->fetchAll(
            'select * from '.$this->em->safeTableName(DocsImage::class).' where `path` = :path',
            [':path' => $path], DocsImage::class
        );
        foreach ($rows as $row) {
            if ($row->path === $path) {
                return $row;
            }
        }
        return null;
    }

    protected function table(): string {
        return $this->em->safeTableName(DocsPage::class);
    }
}
