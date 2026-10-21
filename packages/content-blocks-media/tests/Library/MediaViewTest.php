<?php

declare(strict_types=1);

namespace ContentBlocks\Media\Tests\Library;

use ContentBlocks\Image\ImageUrlResolverInterface;
use ContentBlocks\Image\ResolvedImage;
use ContentBlocks\Media\Entity\Media;
use ContentBlocks\Media\Entity\MediaFolder;
use ContentBlocks\Media\Library\MediaBrowser;
use ContentBlocks\Media\Library\MediaView;
use PHPUnit\Framework\TestCase;

final class MediaViewTest extends TestCase
{
    public function testAnImageThumbnailGoesThroughTheHostResolver(): void
    {
        $images = $this->createMock(ImageUrlResolverInterface::class);
        $images->expects($this->once())->method('resolve')
            ->with('/u/a.png', MediaView::THUMB_WIDTH)
            ->willReturn(new ResolvedImage('/cache/w320/u/a.webp', '/cache/w320/u/a.webp 320w'));

        $view = MediaView::media(new Media('/u/a.png', 'image', 'Logo'), $images);

        $this->assertSame(['src' => '/cache/w320/u/a.webp', 'srcset' => '/cache/w320/u/a.webp 320w'], $view['thumb']);
        $this->assertSame(['/u/a.png', 'Logo', 'image', ''], [$view['path'], $view['title'], $view['kind'], $view['alt']]);
    }

    public function testAVideoHasNoThumbnailToResolve(): void
    {
        $images = $this->createMock(ImageUrlResolverInterface::class);
        $images->expects($this->never())->method('resolve');

        $this->assertNull(MediaView::media(new Media('/u/c.mp4', 'video', 'Clip'), $images)['thumb']);
    }

    public function testTheBreadcrumbRunsFromTheRootDown(): void
    {
        $brand = new MediaFolder('Brand');
        $logos = new MediaFolder('Logos', $brand);

        $this->assertSame(['Brand', 'Logos'], array_column(MediaBrowser::breadcrumb($logos), 'name'));
        $this->assertSame([], MediaBrowser::breadcrumb(null));
    }

    // A parent cycle in the table must not hang the request.
    public function testABrokenParentCycleStillEnds(): void
    {
        $a = new MediaFolder('A');
        $b = new MediaFolder('B', $a);
        $a->moveTo($b, 0);

        $this->assertCount(64, MediaBrowser::breadcrumb($a));
    }

    public function testASearchIsCaseInsensitiveAndTakesWildcardsLiterally(): void
    {
        $this->assertSame('%100\%\_off\\\\%', MediaBrowser::likePattern('100%_OFF\\'));
        $this->assertSame('%écran%', MediaBrowser::likePattern('ÉCRAN'));
    }
}
