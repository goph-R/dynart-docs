<?php

use PHPUnit\Framework\TestCase;
use Dynart\Docs\DocsController;
use Dynart\Docs\DocsPages;

/** A tree with no database behind it: the outline is all the navigation is computed from */
class OutlineDocsPages extends DocsPages {
    public function __construct(private array $rows) {}
    public function outline(): array {
        $keyed = [];
        foreach ($this->rows as $row) {
            $keyed[$row['id']] = $row;
        }
        return $keyed;
    }
}

final class PublicTest extends TestCase {

    public function testSphinxAddressesComeToTheirPage(): void {
        $this->assertSame('dos-game-engine/BASICS/VGA', DocsController::canonicalPath('dos-game-engine/BASICS/VGA.html'));
        $this->assertSame('dos-game-engine', DocsController::canonicalPath('dos-game-engine/index.html'));
        $this->assertSame('dos-game-engine', DocsController::canonicalPath('dos-game-engine/index'));
        $this->assertSame('', DocsController::canonicalPath('index.html'));
        $this->assertSame('', DocsController::canonicalPath('index'));
    }

    public function testAnOrdinaryPathIsLeftAlone(): void {
        $this->assertSame('dos-game-engine/BASICS/VGA', DocsController::canonicalPath('dos-game-engine/BASICS/VGA'));
        // a page called something ending in "index" is not a folder's index
        $this->assertSame('engine/reindex', DocsController::canonicalPath('engine/reindex'));
    }

    public function testTheTitleIsTakenOutOfTheBodyKeepingItsId(): void {
        [$title, $body] = DocsController::splitTitle("<h1 id=\"vga\">VGA</h1>\n<p>Text</p>");
        $this->assertSame('<h1 class="entry-title" id="vga">VGA</h1>', $title);
        $this->assertSame('<p>Text</p>', $body);
    }

    public function testAClassOnTheTitleIsKept(): void {
        [$title] = DocsController::splitTitle('<h1 id="x" class="numbered-header">X</h1>');
        $this->assertSame('<h1 id="x" class="entry-title numbered-header">X</h1>', $title);
    }

    public function testABodyNotOpeningWithATitleIsLeftWhole(): void {
        $this->assertSame(['', '<p>a</p><h1>b</h1>'], DocsController::splitTitle('<p>a</p><h1>b</h1>'));
    }

    private function pages(): OutlineDocsPages {
        // root > a (a1, a2), b - in reading order
        return new OutlineDocsPages([
            ['id' => 1, 'path' => '', 'title' => 'Root', 'parent_id' => null, 'position' => 0, 'sequence' => 0],
            ['id' => 2, 'path' => 'a', 'title' => 'A', 'parent_id' => 1, 'position' => 0, 'sequence' => 1],
            ['id' => 3, 'path' => 'a/1', 'title' => 'A1', 'parent_id' => 2, 'position' => 0, 'sequence' => 2],
            ['id' => 4, 'path' => 'a/2', 'title' => 'A2', 'parent_id' => 2, 'position' => 1, 'sequence' => 3],
            ['id' => 5, 'path' => 'b', 'title' => 'B', 'parent_id' => 1, 'position' => 1, 'sequence' => 4],
        ]);
    }

    public function testAncestorsRunFromTheRootDown(): void {
        $this->assertSame([1, 2], array_column($this->pages()->ancestors(4), 'id'));
        $this->assertSame([], $this->pages()->ancestors(1));
    }

    public function testNeighboursFollowReadingOrderAcrossBranches(): void {
        [$prev, $next] = $this->pages()->neighbours(4);
        $this->assertSame(3, $prev['id']);
        $this->assertSame(5, $next['id']);
        $this->assertSame([null, 2], [$this->pages()->neighbours(1)[0], $this->pages()->neighbours(1)[1]['id']]);
        $this->assertNull($this->pages()->neighbours(5)[1]);
    }

    public function testChildrenAreGroupedByParentInTheirToctreeOrder(): void {
        $children = $this->pages()->children();
        $this->assertSame([1], array_column($children[0], 'id'));
        $this->assertSame([2, 5], array_column($children[1], 'id'));
        $this->assertSame([3, 4], array_column($children[2], 'id'));
    }
}
