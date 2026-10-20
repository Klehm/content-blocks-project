<?php

declare(strict_types=1);

namespace ContentBlocks\Media\Tests\Controller;

use ContentBlocks\Asset\AssetReferenceCollector;
use ContentBlocks\Asset\AssetResolverInterface;
use ContentBlocks\Entity\ContentArea;
use ContentBlocks\Image\ImageUrlResolverInterface;
use ContentBlocks\Image\ResolvedImage;
use ContentBlocks\Media\Controller\ManageController;
use ContentBlocks\Media\Entity\Media;
use ContentBlocks\Media\Entity\MediaFolder;
use ContentBlocks\Media\Library\MediaUsage;
use ContentBlocks\Media\Security\MediaManagerInterface;
use ContentBlocks\Preview\ContentAreaUrlResolverInterface;
use ContentBlocks\Replace\ContentAreaProviderInterface;
use ContentBlocks\Security\AccessCheckerInterface;
use ContentBlocks\Security\ContentBlocksAccessDeniedException;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

final class ManageControllerTest extends TestCase
{
    private EntityManagerInterface&MockObject $em;
    private bool $canManage = true;
    private bool $csrfValid = true;
    /** @var array<string, array<int, object>> */
    private array $rows = [];
    /** @var list<MediaFolder> */
    private array $folders = [];
    /** @var list<object> */
    private array $persisted = [];
    /** @var list<object> */
    private array $removed = [];
    /** @var list<array<string, mixed>> */
    private array $blockRows = [];
    /** @var array<int, bool> */
    private array $editable = [];

    protected function setUp(): void
    {
        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->em->method('find')->willReturnCallback(fn (string $class, int $id) => $this->rows[$class][$id] ?? null);
        $this->em->method('persist')->willReturnCallback(function (object $o): void {
            $this->persisted[] = $o;
        });
        $this->em->method('remove')->willReturnCallback(function (object $o): void {
            $this->removed[] = $o;
        });
        $repository = $this->createMock(EntityRepository::class);
        $repository->method('findBy')->willReturnCallback(fn (array $criteria) => array_values(array_filter(
            $this->folders,
            static fn (MediaFolder $f) => !\array_key_exists('parent', $criteria) || $f->getParent() === $criteria['parent'],
        )));
        $this->em->method('getRepository')->willReturn($repository);
        $this->rows[ContentArea::class][1] = new ContentArea();
    }

    public function testEveryWriteNeedsTheCsrfToken(): void
    {
        $this->csrfValid = false;

        $this->assertSame(403, $this->controller()->createFolder($this->post(['name' => 'A']))->getStatusCode());
    }

    public function testEveryWriteNeedsTheRightToManage(): void
    {
        $this->canManage = false;

        $this->expectException(ContentBlocksAccessDeniedException::class);
        $this->controller()->removeFile($this->post(['id' => 1]));
    }

    public function testANewFolderGoesLastAmongItsSiblings(): void
    {
        $parent = $this->folder(7, 'Brand');
        $a = new MediaFolder('A', $parent);
        $a->moveTo($parent, 0);
        $b = new MediaFolder('B', $parent);
        $b->moveTo($parent, 4);
        $this->folders = [$a, $b];

        $response = $this->controller()->createFolder($this->post(['name' => '  Logos  ', 'parent' => 7]));

        $this->assertSame(200, $response->getStatusCode());
        $created = $this->persisted[0];
        $this->assertInstanceOf(MediaFolder::class, $created);
        $this->assertSame(['Logos', 5, $parent], [$created->getName(), $created->getPosition(), $created->getParent()]);
    }

    public function testAFolderNeedsAName(): void
    {
        foreach (['', '   ', str_repeat('x', 256), null, 42] as $name) {
            $this->assertSame(400, $this->controller()->createFolder($this->post(['name' => $name]))->getStatusCode());
        }
        $this->assertSame([], $this->persisted);
    }

    public function testAnUnknownParentIsNotFound(): void
    {
        $this->assertSame(404, $this->controller()->createFolder($this->post(['name' => 'A', 'parent' => 99]))->getStatusCode());
    }

    public function testRenameAndDeleteAFolder(): void
    {
        $folder = $this->folder(7, 'Old');

        $this->controller()->renameFolder($this->post(['id' => 7, 'name' => 'New']));
        $this->controller()->deleteFolder($this->post(['id' => 7]));

        $this->assertSame('New', $folder->getName());
        $this->assertSame([$folder], $this->removed);
    }

    public function testTitleAndAltAreEditedApart(): void
    {
        $media = $this->media(3);

        $this->controller()->updateFile($this->post(['id' => 3, 'alt' => ' Our logo ']));
        $this->assertSame(['a', 'Our logo'], [$media->getTitle(), $media->getAlt()]);

        $this->controller()->updateFile($this->post(['id' => 3, 'title' => 'Logo']));
        $this->assertSame(['Logo', 'Our logo'], [$media->getTitle(), $media->getAlt()]);
    }

    public function testAnAltMayBeEmptiedButATitleMayNot(): void
    {
        $media = $this->media(3);

        $this->assertSame(200, $this->controller()->updateFile($this->post(['id' => 3, 'alt' => '']))->getStatusCode());
        $this->assertSame(400, $this->controller()->updateFile($this->post(['id' => 3, 'title' => '']))->getStatusCode());
        $this->assertSame(400, $this->controller()->updateFile($this->post(['id' => 3, 'alt' => str_repeat('x', 256)]))->getStatusCode());
        $this->assertSame('a', $media->getTitle());
    }

    public function testMoveToAFolderOrBackToTheRoot(): void
    {
        $media = $this->media(3);
        $folder = $this->folder(7, 'Logos');

        $this->controller()->moveFile($this->post(['id' => 3, 'folder' => 7]));
        $this->assertSame($folder, $media->getFolder());

        $this->controller()->moveFile($this->post(['id' => 3, 'folder' => null]));
        $this->assertNull($media->getFolder());
    }

    // The file itself is the GC's call, like after any editor action.
    public function testRemovingDeletesTheRowOnly(): void
    {
        $media = $this->media(3);

        $response = $this->controller()->removeFile($this->post(['id' => 3]));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame([$media], $this->removed);
    }

    public function testUsageNamesOnlyThePagesTheEditorMayEdit(): void
    {
        $this->media(3);
        $this->rows[ContentArea::class][4] = new ContentArea();
        $this->rows[ContentArea::class][5] = new ContentArea();
        $this->editable = [1 => true, 4 => true, 5 => false];
        $this->blockRows = [
            ['content_area_id' => '4', 'draft_data' => '{"src":"/uploads/a.png"}', 'published_data' => null, 'deleted' => '0'],
            ['content_area_id' => '5', 'draft_data' => '{"src":"/uploads/a.png"}', 'published_data' => null, 'deleted' => '0'],
        ];

        $response = $this->controller()->usage(new Request(['area' => '1', 'id' => '3']));

        $this->assertSame([
            'pages' => [['area' => 4, 'label' => 'Page 4', 'url' => null, 'draft' => true, 'published' => false]],
            'others' => 1,
        ], json_decode((string) $response->getContent(), true));
    }

    public function testFoldersAreListedDepthFirstWithTheirDepth(): void
    {
        $brand = $this->folder(1, 'Brand');
        $logos = $this->folder(2, 'Logos', $brand);
        $team = $this->folder(3, 'Team');

        $this->assertSame(
            [['Brand', 0], ['Logos', 1], ['Team', 0]],
            array_map(static fn (array $f) => [$f['name'], $f['depth']], ManageController::flatten([$brand, $team, $logos])),
        );
    }

    private function folder(int $id, string $name, ?MediaFolder $parent = null): MediaFolder
    {
        $folder = new MediaFolder($name, $parent);
        (new \ReflectionProperty(MediaFolder::class, 'id'))->setValue($folder, $id);

        return $this->rows[MediaFolder::class][$id] = $folder;
    }

    private function media(int $id): Media
    {
        $media = new Media('/uploads/a.png', 'image', 'a');
        (new \ReflectionProperty(Media::class, 'id'))->setValue($media, $id);

        return $this->rows[Media::class][$id] = $media;
    }

    /** @param array<string, mixed> $body */
    private function post(array $body): Request
    {
        return Request::create('/x', 'POST', [], [], [], ['HTTP_X_CSRF_TOKEN' => 'tok'], json_encode($body, \JSON_THROW_ON_ERROR));
    }

    private function controller(): ManageController
    {
        $access = $this->createMock(AccessCheckerInterface::class);
        $access->method('canEdit')->willReturnCallback(fn (ContentArea $area) => $this->editable[$this->idOf($area)] ?? true);
        $manager = $this->createMock(MediaManagerInterface::class);
        $manager->method('canManage')->willReturnCallback(fn () => $this->canManage);
        $csrf = $this->createMock(CsrfTokenManagerInterface::class);
        $csrf->method('isTokenValid')->willReturnCallback(fn () => $this->csrfValid);
        $images = $this->createMock(ImageUrlResolverInterface::class);
        $images->method('resolve')->willReturnCallback(fn (string $src) => new ResolvedImage($src));

        $connection = $this->createMock(Connection::class);
        $connection->method('getDatabasePlatform')->willReturn(new MySQLPlatform());
        $connection->method('fetchAllAssociative')->willReturnCallback(fn () => $this->blockRows);
        $resolver = $this->createMock(AssetResolverInterface::class);
        $resolver->method('isAssetPath')->willReturnCallback(static fn (string $v) => str_starts_with($v, '/uploads/'));

        $provider = $this->createMock(ContentAreaProviderInterface::class);
        $provider->method('getLabel')->willReturnCallback(fn (ContentArea $area) => 'Page ' . $this->idOf($area));
        $urls = $this->createMock(ContentAreaUrlResolverInterface::class);
        $urls->method('resolve')->willThrowException(new \LogicException('No resolver.'));

        return new ManageController(
            $this->em,
            $access,
            $manager,
            $csrf,
            $images,
            new MediaUsage($connection, new AssetReferenceCollector($resolver)),
            $provider,
            $urls,
        );
    }

    private function idOf(ContentArea $area): int
    {
        return (int) array_search($area, $this->rows[ContentArea::class], true);
    }
}
