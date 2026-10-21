<?php

declare(strict_types=1);

namespace ContentBlocks\Media\Tests\Controller;

use ContentBlocks\Entity\ContentArea;
use ContentBlocks\Image\ImageUrlResolverInterface;
use ContentBlocks\Image\ResolvedImage;
use ContentBlocks\Media\Controller\MediaController;
use ContentBlocks\Media\Entity\Media;
use ContentBlocks\Media\Entity\MediaFolder;
use ContentBlocks\Media\Library\MediaBrowser;
use ContentBlocks\Media\Security\MediaManagerInterface;
use ContentBlocks\Security\AccessCheckerInterface;
use ContentBlocks\Security\ContentBlocksAccessDeniedException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

final class MediaControllerTest extends TestCase
{
    private EntityManagerInterface&MockObject $em;
    private bool $canEdit = true;
    private bool $csrfValid = true;
    private bool $canManage = true;
    private ?Media $media = null;
    private ?MediaFolder $folder = null;

    protected function setUp(): void
    {
        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->em->method('find')->willReturnCallback(fn (string $class, int $id) => match ($class) {
            ContentArea::class => $id === 1 ? new ContentArea() : null,
            MediaFolder::class => $id === 7 ? $this->folder : null,
            default => null,
        });
        $repository = $this->createMock(EntityRepository::class);
        $repository->method('findOneBy')->willReturnCallback(
            fn (array $criteria) => $this->media?->getPath() === $criteria['path'] ? $this->media : null,
        );
        $this->em->method('getRepository')->willReturn($repository);
    }

    public function testListingNeedsAnAreaTheEditorMayEdit(): void
    {
        $this->assertSame(404, $this->controller()->list(new Request(['area' => '99']))->getStatusCode());
        $this->assertSame(404, $this->controller()->list(new Request(['area' => 'abc']))->getStatusCode());

        $this->canEdit = false;
        $this->expectException(ContentBlocksAccessDeniedException::class);
        $this->controller()->list(new Request(['area' => '1']));
    }

    public function testOnlyImagesAndVideosAreListed(): void
    {
        $response = $this->controller()->list(new Request(['area' => '1', 'kind' => 'file']));

        $this->assertSame(400, $response->getStatusCode());
    }

    public function testAnUnknownFolderIsNotFound(): void
    {
        $response = $this->controller()->list(new Request(['area' => '1', 'folder' => '8']));

        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame(
            ['error' => 'folder_not_found', 'message' => 'cb_media.error.folder_not_found'],
            json_decode((string) $response->getContent(), true),
        );
    }

    public function testPlacingNeedsTheCsrfToken(): void
    {
        $this->csrfValid = false;

        $this->assertSame(403, $this->controller()->place($this->post(['area' => 1, 'path' => '/u/a.png']))->getStatusCode());
    }

    public function testPlacingAFileOutsideTheLibraryIsNotFound(): void
    {
        $response = $this->controller()->place($this->post(['area' => 1, 'path' => '/u/elsewhere.png']));

        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame('not_in_library', json_decode((string) $response->getContent(), true)['error']);
    }

    public function testPlacingAlsoChecksTheArea(): void
    {
        $this->canEdit = false;
        $this->media = new Media('/u/a.png', 'image', 'A');

        $this->expectException(ContentBlocksAccessDeniedException::class);
        $this->controller()->place($this->post(['area' => 1, 'path' => '/u/a.png']));
    }

    public function testAFileIsFiledUnderTheFolderAndReturned(): void
    {
        $this->media = new Media('/u/a.png', 'image', 'A');
        $this->folder = new MediaFolder('Logos');
        $this->em->expects($this->once())->method('flush');

        $response = $this->controller()->place($this->post(['area' => 1, 'path' => '/u/a.png', 'folder' => 7]));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame($this->folder, $this->media->getFolder());
        $this->assertSame('/u/a.png', json_decode((string) $response->getContent(), true)['path']);
    }

    public function testANullFolderIsTheRoot(): void
    {
        $this->media = new Media('/u/a.png', 'image', 'A');
        $this->media->moveTo(new MediaFolder('Old'));

        $this->controller()->place($this->post(['area' => 1, 'path' => '/u/a.png', 'folder' => null]));

        $this->assertNull($this->media->getFolder());
    }

    public function testAnEditorWhoCannotManageFilesOnlyAJustUploadedFile(): void
    {
        $this->canManage = false;
        $this->folder = new MediaFolder('Logos');
        $this->media = new Media('/u/a.png', 'image', 'A');

        $this->controller()->place($this->post(['area' => 1, 'path' => '/u/a.png', 'folder' => 7]));

        $this->assertSame($this->folder, $this->media->getFolder());
    }

    // Filed once, it is no longer the upload in flight: moving it is managing.
    public function testButNotAFileAlreadyFiled(): void
    {
        $this->canManage = false;
        $this->folder = new MediaFolder('Logos');
        $this->media = new Media('/u/a.png', 'image', 'A');
        $this->media->moveTo(new MediaFolder('Elsewhere'));

        $this->expectException(ContentBlocksAccessDeniedException::class);
        $this->controller()->place($this->post(['area' => 1, 'path' => '/u/a.png', 'folder' => 7]));
    }

    public function testNorAnOldOne(): void
    {
        $this->canManage = false;
        $this->media = new Media('/u/a.png', 'image', 'A');
        (new \ReflectionProperty(Media::class, 'createdAt'))->setValue($this->media, new \DateTimeImmutable('-2 hours'));

        $this->expectException(ContentBlocksAccessDeniedException::class);
        $this->controller()->place($this->post(['area' => 1, 'path' => '/u/a.png', 'folder' => null]));
    }

    public function testABodyThatIsNotJsonIsRefused(): void
    {
        $request = Request::create('/place', 'POST', [], [], [], [], 'not json');

        $this->assertSame(400, $this->controller()->place($request)->getStatusCode());
    }

    /** @param array<string, mixed> $body */
    private function post(array $body): Request
    {
        return Request::create('/place', 'POST', [], [], [], ['HTTP_X_CSRF_TOKEN' => 'tok'], json_encode($body, \JSON_THROW_ON_ERROR));
    }

    private function controller(): MediaController
    {
        $access = $this->createMock(AccessCheckerInterface::class);
        $access->method('canEdit')->willReturnCallback(fn () => $this->canEdit);
        $csrf = $this->createMock(CsrfTokenManagerInterface::class);
        $csrf->method('isTokenValid')->willReturnCallback(fn () => $this->csrfValid);
        $images = $this->createMock(ImageUrlResolverInterface::class);
        $images->method('resolve')->willReturnCallback(fn (string $src) => new ResolvedImage($src));

        $manager = $this->createMock(MediaManagerInterface::class);
        $manager->method('canManage')->willReturnCallback(fn () => $this->canManage);

        return new MediaController($this->em, $access, $csrf, new MediaBrowser($this->em, $images), $images, $manager);
    }
}
