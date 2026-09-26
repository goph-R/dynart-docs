<?php

namespace Dynart\Docs\Test;

use Dynart\Docs\Build\BuildReport;
use Dynart\Docs\Build\DocsBuilder;
use Dynart\Docs\Build\HeadingIds;
use Dynart\Docs\Build\SourceTree;
use PHPUnit\Framework\TestCase;

/**
 * The tree the toctrees make, and the ids Sphinx gave the headings
 *
 * @covers \Dynart\Docs\Build\SourceTree
 * @covers \Dynart\Docs\Build\HeadingIds
 * @covers \Dynart\Docs\Build\DocsBuilder
 */
class TreeAndIdsTest extends TestCase {

    // --- names and paths ---

    public function testAnEntryIsResolvedFromThePagesFolder(): void {
        $this->assertSame('dos/BASICS/VGA', SourceTree::resolve('dos/BASICS/index', 'VGA'));
        $this->assertSame('dos/ENGINE/BASEGAME', SourceTree::resolve('dos/BASICS/VGA', '../ENGINE/BASEGAME.md'));
        $this->assertSame('legal/index', SourceTree::resolve('index', 'legal/index'));
        $this->assertSame('legal/index', SourceTree::resolve('dos/BASICS/VGA', '/legal/index'));
    }

    public function testAFoldersIndexIsTheFolder(): void {
        $this->assertSame('', SourceTree::pathOf('index'));
        $this->assertSame('dos-game-engine', SourceTree::pathOf('dos-game-engine/index'));
        $this->assertSame('dos-game-engine/BASICS/VGA', SourceTree::pathOf('dos-game-engine/BASICS/VGA'));
    }

    // --- the walk ---

    private function source(array $files): string {
        $root = sys_get_temp_dir().'/dpress-docs-'.getmypid().'-'.count($files).'-'.mt_rand();
        foreach ($files as $name => $text) {
            @mkdir(dirname($root.'/'.$name), 0777, true);
            file_put_contents($root.'/'.$name, $text);
        }
        return $root;
    }

    /**
     * Only what a toctree reaches is published - which is what keeps a README out without a list
     */
    public function testOnlyWhatAToctreeReachesIsInTheTree(): void {
        $root = $this->source([
            'index.md' => "# Home\n\n```{toctree}\nguide/index\n```",
            'guide/index.md' => "# Guide\n\n```{toctree}\nintro\n```",
            'guide/intro.md' => '# Intro',
            'guide/README.md' => '# Not published',
        ]);
        $report = new BuildReport();
        $nodes = (new SourceTree($root, $report))->walk();
        $this->assertSame(['index', 'guide/index', 'guide/intro'], array_keys($nodes));
        $this->assertSame([0, 1, 2], array_column($nodes, 'sequence'));
        $this->assertSame('guide/index', $nodes['guide/intro']['parent']);
        $this->assertTrue($report->ok());
    }

    public function testAnEntryWithNoFileIsReported(): void {
        $root = $this->source(['index.md' => "```{toctree}\nmissing\n```"]);
        $report = new BuildReport();
        (new SourceTree($root, $report))->walk();
        $this->assertStringContainsString('there is no missing.md', $report->problems[0]['message']);
    }

    public function testNoIndexIsNoTree(): void {
        $report = new BuildReport();
        $this->assertSame([], (new SourceTree($this->source(['other.md' => '#']), $report))->walk());
        $this->assertFalse($report->ok());
    }

    // --- heading ids: docutils' rule ---

    public function testTheIdIsTheTextAsDocutilsMakesIt(): void {
        $this->assertSame('vga-graphics', HeadingIds::slug('VGA Graphics'));
        $this->assertSame('graphics-input', HeadingIds::slug('Graphics & Input'));
        $this->assertSame('example', HeadingIds::slug('Example:'));
        $this->assertSame('intro', HeadingIds::slug('1. Intro'));
        $this->assertSame('game-engine-tbasegame', HeadingIds::slug('Game Engine (TBaseGame)'));
    }

    /**
     * A typographic apostrophe is dropped, not a hyphen - "Children’s Privacy" is what the old
     * site's links say - and an accent is its letter
     */
    public function testWhatIsNotAsciiIsDroppedOrItsLetter(): void {
        $this->assertSame('childrens-privacy', HeadingIds::slug('Children’s Privacy'));
        $this->assertSame('ev-szam', HeadingIds::slug('Év szám'));
    }

    /**
     * The second heading with the same text is `id1`, then `id2` - one counter for the page
     */
    public function testARepeatedHeadingIsNumberedTheWaySphinxDid(): void {
        $result = HeadingIds::apply('<h1>A</h1><h4>Example:</h4><h4>Example:</h4><h3>Limit</h3><h3>Limit</h3>');
        $this->assertSame(['a', 'example', 'id1', 'limit', 'id2'], array_column($result['headings'], 'id'));
        $this->assertSame('A', $result['title']);
    }

    public function testAnExplicitIdAndClassesAreUsed(): void {
        $result = HeadingIds::apply('<h2>Terms</h2><h2>Rights</h2>', [['id' => 'intro', 'classes' => ['numbered-header']], null]);
        $this->assertStringContainsString('<h2 id="intro" class="numbered-header">Terms</h2>', $result['html']);
        $this->assertStringContainsString('<h2 id="rights">Rights</h2>', $result['html']);
    }

    // --- a page's title, from its source ---

    public function testTheTitleIsTheFirstHeadingOutsideCode(): void {
        $this->assertSame('Real', DocsBuilder::rawTitle("```bash\n# not a title\n```\n# Real {#real}"));
        $this->assertNull(DocsBuilder::rawTitle('no heading'));
    }
}
