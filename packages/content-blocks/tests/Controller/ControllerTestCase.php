<?php

declare(strict_types=1);

namespace ContentBlocks\Tests\Controller;

use ContentBlocks\Block\BlockDataDefaults;
use ContentBlocks\Block\BlockDecoratorCollection;
use ContentBlocks\BlockType\AbstractBlockType;
use ContentBlocks\BlockType\BlockTypeRegistry;
use ContentBlocks\Entity\Block;
use ContentBlocks\Entity\Column;
use ContentBlocks\Entity\ContentArea;
use ContentBlocks\Entity\Section;
use ContentBlocks\Rendering\BlockRenderer;
use ContentBlocks\Section\SectionDecoratorCollection;
use ContentBlocks\Section\SectionSettingsDefaults;
use ContentBlocks\Security\AllowAllAccessChecker;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

/**
 * Shared plumbing for the AJAX controller unit tests. The controllers are
 * plain final classes with constructor injection (no AbstractController),
 * so they are tested by direct instantiation with an EntityManager test
 * double; no kernel / database is involved.
 */
abstract class ControllerTestCase extends TestCase
{
    /** @var list<object> Entities passed to em->persist(). */
    protected array $persisted = [];
    protected int $flushCount = 0;

    /**
     * EM double whose find() resolves from an in-memory identity map. Keys
     * are entity instances; ids must have been assigned via setEntityId().
     *
     * @param list<object> $entities
     */
    protected function makeEm(array $entities = []): EntityManagerInterface
    {
        $this->persisted = [];
        $this->flushCount = 0;

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('find')->willReturnCallback(function (string $class, mixed $id) use ($entities): ?object {
            foreach ($entities as $entity) {
                if ($entity instanceof $class && $entity->getId() === $id) {
                    return $entity;
                }
            }

            return null;
        });
        $em->method('persist')->willReturnCallback(function (object $entity): void {
            $this->persisted[] = $entity;
        });
        $em->method('flush')->willReturnCallback(function (): void {
            ++$this->flushCount;
        });

        return $em;
    }

    /** CSRF manager double; controllers only call isTokenValid(). */
    protected function makeCsrfManager(bool $valid = true): CsrfTokenManagerInterface
    {
        $manager = $this->createMock(CsrfTokenManagerInterface::class);
        $manager->method('isTokenValid')->willReturn($valid);

        return $manager;
    }

    protected function makeAccessChecker(): AllowAllAccessChecker
    {
        return new AllowAllAccessChecker();
    }

    /**
     * BlockRenderer is final (not mockable); the tests use block types that
     * opt out of preview hot reload, so the renderer is never actually
     * invoked — it just needs to satisfy the constructor signature.
     */
    protected function makeUnusedRenderer(): BlockRenderer
    {
        return new BlockRenderer(
            $this->createMock(Environment::class),
            new RequestStack(),
            new AllowAllAccessChecker(),
            new BlockTypeRegistry(),
            new SectionDecoratorCollection([]),
            new SectionSettingsDefaults([]),
            $this->createMock(TranslatorInterface::class),
            new BlockDecoratorCollection([]),
            new BlockDataDefaults([]),
        );
    }

    /** Registry holding the single "fake" type used by the block tests. */
    protected function makeRegistry(): BlockTypeRegistry
    {
        $registry = new BlockTypeRegistry();
        $registry->register(new FakeBlockType());

        return $registry;
    }

    protected function makeJsonRequest(array $payload = []): Request
    {
        return Request::create(
            '/_content-blocks/test',
            'POST',
            server: ['HTTP_X-CSRF-Token' => 'token'],
            content: json_encode($payload, \JSON_THROW_ON_ERROR),
        );
    }

    // ---------- Entity factories (ids assigned via reflection, the way
    // Doctrine would on a real flush) ----------

    protected function setEntityId(object $entity, int $id): void
    {
        $ref = new \ReflectionProperty($entity::class, 'id');
        $ref->setValue($entity, $id);
    }

    protected function makeArea(int $id): ContentArea
    {
        $area = new ContentArea();
        $this->setEntityId($area, $id);

        return $area;
    }

    protected function makeSection(ContentArea $area, int $id, int $previewPosition = 0, string $layout = Section::LAYOUT_FULL): Section
    {
        $section = new Section();
        $this->setEntityId($section, $id);
        $section->setLayout($layout);
        $section->setPreviewPosition($previewPosition);
        $area->addSection($section);

        return $section;
    }

    protected function makeColumn(Section $section, int $id, int $previewPosition = 0): Column
    {
        $column = new Column();
        $this->setEntityId($column, $id);
        $column->setPreset('col-12');
        $column->setPreviewPosition($previewPosition);
        $section->addColumn($column);

        return $column;
    }

    protected function makeBlock(Column $column, int $id, int $previewPosition = 0, string $type = FakeBlockType::TYPE): Block
    {
        $block = new Block();
        $this->setEntityId($block, $id);
        $block->setType($type);
        $block->setPreviewPosition($previewPosition);
        $column->addBlock($block);

        return $block;
    }
}

/**
 * Minimal block type fixture. Opts OUT of preview hot reload so controller
 * tests never reach the (unmockable, unused) BlockRenderer.
 */
final class FakeBlockType extends AbstractBlockType
{
    public const TYPE = 'fake';

    public static function getType(): string
    {
        return self::TYPE;
    }

    public static function getLabel(): string
    {
        return 'Fake';
    }

    public function buildForm(FormBuilderInterface $builder, array $data): void
    {
    }

    public function getDefaultData(): array
    {
        return ['content' => 'default'];
    }

    public function supportsPreviewHotReload(): bool
    {
        return false;
    }
}
