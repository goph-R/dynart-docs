<?php

namespace Dynart\Docs\Test;

use Dynart\Docs\Build\BuildReport;
use Dynart\Docs\Build\Myst;
use PHPUnit\Framework\TestCase;

/**
 * MyST into the Markdown Dpress renders - the constructs `docs-public` actually uses
 *
 * @covers \Dynart\Docs\Build\Myst
 */
class MystTest extends TestCase {

    private BuildReport $report;

    private function myst(string $docname = 'guide/index', array $labels = []): Myst {
        $this->report = new BuildReport();
        $pages = [
            'guide/index' => 'Guide', 'guide/intro' => 'Introduction', 'guide/setup' => 'Setup',
            'guide/setup/linux' => 'On Linux', 'index' => 'Home',
        ];
        $children = ['guide/setup' => ['guide/setup/linux']];
        return new Myst([
            'docname'  => $docname,
            'report'   => $this->report,
            'url'      => fn(string $d) => isset($pages[$d]) ? 'https://x.test/docs/'.$d : null,
            'title'    => fn(string $d) => $pages[$d] ?? $d,
            'children' => fn(string $d) => $children[$d] ?? [],
            'labels'   => $labels,
        ]);
    }

    private function convert(string $markdown, string $docname = 'guide/index', array $labels = []): array {
        return $this->myst($docname, $labels)->convert($markdown);
    }

    // --- toctree ---

    public function testAToctreeIsTheListOfItsPages(): void {
        $md = "```{toctree}\n:maxdepth: 1\n:caption: Contents\n\nintro\nsetup\n```";
        $this->assertSame(
            "**Contents**\n\n- [Introduction](https://x.test/docs/guide/intro)\n- [Setup](https://x.test/docs/guide/setup)\n",
            $this->convert($md)['markdown']
        );
    }

    public function testMaxdepthNestsTheEntriesOwnEntries(): void {
        $md = "```{toctree}\n:maxdepth: 2\n\nsetup\n```";
        $this->assertStringContainsString("- [Setup](https://x.test/docs/guide/setup)\n  - [On Linux](https://x.test/docs/guide/setup/linux)",
            $this->convert($md)['markdown']);
    }

    public function testATitledEntryKeepsItsTitle(): void {
        $this->assertStringContainsString('[Start here](https://x.test/docs/guide/intro)',
            $this->convert("```{toctree}\nStart here <intro>\n```")['markdown']);
    }

    public function testAHiddenToctreeDrawsNothing(): void {
        $this->assertSame('', trim($this->convert("```{toctree}\n:hidden:\n\nintro\n```")['markdown']));
    }

    // --- admonitions ---

    public function testANoteIsACallout(): void {
        $this->assertSame("> [!NOTE]\n> Read this.\n", $this->convert("```{note}\nRead this.\n```")['markdown']);
    }

    public function testAWarningWithATitle(): void {
        $this->assertStringStartsWith('> [!WARNING] **Careful**', $this->convert("```{warning} Careful\nHot.\n```")['markdown']);
    }

    public function testAnUnknownDirectiveIsShownAndReported(): void {
        $out = $this->convert("```{figure} x.png\nA caption\n```")['markdown'];
        $this->assertStringContainsString("```text\nA caption\n```", $out);
        $this->assertStringContainsString('{figure}', $this->report->problems[0]['message']);
    }

    // --- code is left alone ---

    public function testNothingInsideACodeBlockIsConverted(): void {
        $md = "```pascal\n{#label}\nWriteLn('<br>');\n```";
        $this->assertSame($md, $this->convert($md)['markdown']);
    }

    // --- labels and roles ---

    public function testALabelLineGivesTheNextHeadingItsId(): void {
        $out = $this->convert("{#start .numbered}\n## Getting started");
        $this->assertSame("## Getting started", $out['markdown']);
        $this->assertSame([['id' => 'start', 'classes' => ['numbered']]], $out['ids']);
    }

    public function testAClassOnlyLineGivesClassesAndNoId(): void {
        $out = $this->convert("{.numbered-header}\n## Rights");
        $this->assertSame([['id' => null, 'classes' => ['numbered-header']]], $out['ids']);
        $this->assertStringNotContainsString('{', $out['markdown']);
    }

    public function testAnInlineIdOnAHeading(): void {
        $out = $this->convert("## Types {#types-section}");
        $this->assertSame('## Types', $out['markdown']);
        $this->assertSame('types-section', $out['ids'][0]['id']);
    }

    public function testARefIsALinkToTheLabelledHeading(): void {
        $labels = ['details' => ['id' => 'details', 'title' => 'Company details', 'docname' => 'guide/index'],
                   'elsewhere' => ['id' => 'x', 'title' => 'Far', 'docname' => 'guide/intro']];
        $out = $this->convert("See {ref}`details` and {ref}`there <elsewhere>`.", 'guide/index', $labels)['markdown'];
        $this->assertSame('See [Company details](#details) and [there](https://x.test/docs/guide/intro#x).', $out);
    }

    public function testAnUnknownRefIsItsTextAndReported(): void {
        $this->assertSame('See nowhere.', $this->convert('See {ref}`nowhere`.')['markdown']);
        $this->assertNotEmpty($this->report->problems);
    }

    // --- links and <br> ---

    public function testARelativeMdLinkIsThePagesAddress(): void {
        $out = $this->convert('The [intro](intro.md#first) and [setup](./setup.md).')['markdown'];
        $this->assertSame('The [intro](https://x.test/docs/guide/intro#first) and [setup](https://x.test/docs/guide/setup).', $out);
    }

    public function testALinkUpAFolder(): void {
        $this->assertStringContainsString('(https://x.test/docs/index)',
            $this->convert('[home](../index.md)', 'guide/setup')['markdown']);
    }

    public function testALinkToAPageNoToctreeReachesIsLeftAndReported(): void {
        $this->assertSame('[x](notes.md)', $this->convert('[x](notes.md)')['markdown']);
        $this->assertStringContainsString('no toctree reaches', $this->report->problems[0]['message']);
    }

    public function testAnOutsideLinkIsLeftAlone(): void {
        $this->assertSame('[x](https://example.com/a.md)', $this->convert('[x](https://example.com/a.md)')['markdown']);
    }

    public function testABreakInATableIsTheShortcode(): void {
        $this->assertSame('| a{{ br() }}b | `<br>` |', $this->convert('| a<br>b | `<br>` |')['markdown']);
    }

    // --- labels across the documentation ---

    public function testLabelsAreCollectedWithTheirHeadings(): void {
        $this->assertSame([
            'definitions' => ['id' => 'definitions', 'title' => 'Definitions'],
            'company' => ['id' => 'company', 'title' => 'Company details'],
        ], Myst::labels("{#definitions}\n## Definitions\n\ntext\n\n## Company details {#company .x}"));
    }
}
