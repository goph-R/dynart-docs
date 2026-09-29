<?php

namespace Dynart\Docs\Test;

use Dynart\Docs\Build\BuildReport;
use Dynart\Docs\Build\Images;
use Dynart\Docs\Build\Myst;
use Dynart\Docs\DocsController;
use PHPUnit\Framework\TestCase;

/**
 * Images in the documentation: which files a page may show, their addresses, and how they are
 * answered
 *
 * @covers \Dynart\Docs\Build\Images
 */
class ImagesTest extends TestCase {

    /** A 2x1 PNG, so the size read back is not the 1x1 anything would answer */
    const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAIAAAABCAYAAAD0In+KAAAAC0lEQVR42mNgQAcAAA0AAQ4ZHUIAAAAASUVORK5CYII=';

    private string $folder;

    protected function setUp(): void {
        $this->folder = sys_get_temp_dir().'/docs-images-'.bin2hex(random_bytes(4));
        mkdir($this->folder.'/engine/images', 0777, true);
        mkdir($this->folder.'/.git', 0777, true);
        file_put_contents($this->folder.'/engine/images/vga.png', base64_decode(self::PNG));
        file_put_contents($this->folder.'/engine/images/fake.png', 'not a picture at all');
        file_put_contents($this->folder.'/engine/images/logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"/>');
        file_put_contents($this->folder.'/engine/notes.txt', 'text');
        file_put_contents($this->folder.'/.git/secret.png', base64_decode(self::PNG));
    }

    protected function tearDown(): void {
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->folder, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($this->folder);
    }

    private function images(): Images {
        return new Images($this->folder, fn(string $path, string $version) => 'https://x.test/docs/'.$path.'?v='.$version);
    }

    // --- where a destination points ---

    public function testAPathIsRelativeToThePagesFolder(): void {
        $this->assertSame('engine/images/vga.png', Images::resolve('engine/VGA', 'images/vga.png'));
        $this->assertSame('engine/images/vga.png', Images::resolve('engine/sub/page', '../images/vga.png'));
        $this->assertSame('images/a.png', Images::resolve('index', 'images/a.png'));
    }

    public function testALeadingSlashIsTheSourceFoldersRoot(): void {
        $this->assertSame('shared/a.png', Images::resolve('engine/deep/page', '/shared/a.png'));
    }

    public function testAPathMayNotClimbOutOfTheSourceFolder(): void {
        $this->assertNull(Images::resolve('engine/VGA', '../../etc/a.png'));
        $this->assertNull(Images::resolve('index', '../a.png'));
    }

    public function testAnEncodedSpaceAndAQueryAreTheFilesName(): void {
        $this->assertSame('engine/my shot.png', Images::resolve('engine/VGA', 'my%20shot.png?x=1#top'));
    }

    public function testAddressesAndLibraryReferencesAreNotFiles(): void {
        foreach (['https://example.com/a.png', '//cdn.test/a.png', 'media#12', 'data:image/png;base64,AA', ''] as $target) {
            $this->assertFalse(Images::isRelative($target), $target);
        }
        $this->assertTrue(Images::isRelative('images/a.png'));
    }

    public function testOnlyTheServedTypesAreImagePaths(): void {
        $this->assertTrue(Images::isImagePath('engine/images/VGA.PNG'));
        $this->assertTrue(Images::isImagePath('a/b.webp'));
        $this->assertFalse(Images::isImagePath('engine/images/logo.svg'));
        $this->assertFalse(Images::isImagePath('engine/VGA'));
    }

    // --- what is published ---

    public function testAnImageIsPublishedWithItsVersion(): void {
        $images = $this->images();
        $published = $images->publish('engine/VGA', 'images/vga.png');
        $hash = hash('sha256', base64_decode(self::PNG));
        $this->assertSame('https://x.test/docs/engine/images/vga.png?v='.substr($hash, 0, 12), $published['url']);
        $found = $images->found()['engine/images/vga.png'];
        $this->assertSame([$hash, 'image/png', 2, 1], [$found['hash'], $found['mime'], $found['width'], $found['height']]);
    }

    /** the editor's preview: the build's rules, for a file no page shows - and nothing is recorded */
    public function testAnImageIsInspectedWithoutBeingPublished(): void {
        $checked = Images::inspect($this->folder, Images::resolve('engine/VGA', 'images/vga.png'), 'images/vga.png');
        $this->assertSame(['image/png', 2, 1], [$checked['mime'], $checked['width'], $checked['height']]);
        $this->assertSame($this->folder.'/engine/images/vga.png', $checked['file']);
        $this->assertArrayHasKey('problem', Images::inspect($this->folder, Images::resolve('engine/VGA', 'images/logo.svg'), 'images/logo.svg'));
        $this->assertArrayHasKey('problem', Images::inspect($this->folder, null, '../../x.png'));
    }

    public function testTheSameImageTwiceIsOneEntry(): void {
        $images = $this->images();
        $images->publish('engine/VGA', 'images/vga.png');
        $images->publish('engine/sub/other', '../images/vga.png');
        $this->assertCount(1, $images->found());
    }

    /** @dataProvider refused */
    public function testWhatIsNotServedIsAProblem(string $target, string $says): void {
        $images = $this->images();
        $published = $images->publish('engine/VGA', $target);
        $this->assertArrayNotHasKey('url', $published);
        $this->assertStringContainsString($says, $published['problem']);
        $this->assertSame([], $images->found());
    }

    public static function refused(): array {
        return [
            'missing'          => ['images/none.png', 'is not there'],
            'svg'              => ['images/logo.svg', 'SVG'],
            'another type'     => ['notes.txt', 'not a png, jpg, gif or webp'],
            'not what it says' => ['images/fake.png', 'not a png image'],
            'hidden folder'    => ['../.git/secret.png', 'hidden folder'],
            'out of the folder'=> ['../../x.png', 'outside the source folder'],
            'a space'          => ['images/my%20shot.png', 'a space'],
        ];
    }

    public function testTheSizeAndLazyLoadingGoOnThisBuildsImagesOnly(): void {
        $images = $this->images();
        $url = $images->publish('engine/VGA', 'images/vga.png')['url'];
        $html = '<p><img src="'.htmlspecialchars($url).'" alt="VGA" /> <img src="https://else.test/a.png" alt="" /></p>';
        $this->assertSame(
            '<p><img src="'.htmlspecialchars($url).'" alt="VGA" width="2" height="1" loading="lazy" />'
            .' <img src="https://else.test/a.png" alt="" /></p>',
            $images->decorate($html)
        );
    }

    // --- in a page ---

    private function convert(string $markdown, BuildReport $report): string {
        return (new Myst([
            'docname'  => 'engine/VGA',
            'report'   => $report,
            'url'      => fn(string $d) => null,
            'title'    => fn(string $d) => $d,
            'children' => fn(string $d) => [],
            'labels'   => [],
            'images'   => $this->images(),
        ]))->convert($markdown)['markdown'];
    }

    public function testAPagesImageIsGivenItsAddress(): void {
        $report = new BuildReport();
        $markdown = $this->convert('A ![The palette](images/vga.png "VGA") and `![x](images/vga.png)`.', $report);
        $this->assertMatchesRegularExpression(
            '#^A !\[The palette\]\(https://x\.test/docs/engine/images/vga\.png\?v=[0-9a-f]{12} "VGA"\) and `!\[x\]\(images/vga\.png\)`\.$#',
            $markdown
        );
        $this->assertSame([], $report->problems);
    }

    public function testAnImageThatCannotBeServedIsLeftAndReported(): void {
        $report = new BuildReport();
        $this->assertSame('![Logo](images/logo.svg)', $this->convert('![Logo](images/logo.svg)', $report));
        $this->assertCount(1, $report->problems);
    }

    public function testAnAddressIsLeftAsItIs(): void {
        $report = new BuildReport();
        $this->assertSame('![a](https://example.com/a.png)', $this->convert('![a](https://example.com/a.png)', $report));
        $this->assertSame([], $report->problems);
    }

    // --- how it is answered ---

    public function testTheAddressAPageHasIsKeptForAYear(): void {
        $answer = DocsController::imageAnswer('3fa9c1d2e4b5aa', '3fa9c1d2e4b5', true, '"12-34"', '');
        $this->assertSame(200, $answer['status']);
        $this->assertSame('public, max-age=31536000, immutable', $answer['headers']['Cache-Control']);
        $this->assertSame('nosniff', $answer['headers']['X-Content-Type-Options']);
    }

    public function testAnyOtherAddressIsAskedAboutAgain(): void {
        foreach ([['', true], ['000000000000', true], ['3fa9c1d2e4b5', false], ['3f', true]] as [$version, $built]) {
            $answer = DocsController::imageAnswer('3fa9c1d2e4b5aa', $version, $built, '"12-34"', '');
            $this->assertSame('public, max-age=300', $answer['headers']['Cache-Control'], $version);
        }
    }

    public function testAnUnchangedFileIsA304(): void {
        $this->assertSame(304, DocsController::imageAnswer('h', '', true, '"12-34"', '"99-1", "12-34"')['status']);
        $this->assertSame(200, DocsController::imageAnswer('h', '', true, '"12-34"', '"12-35"')['status']);
    }
}
