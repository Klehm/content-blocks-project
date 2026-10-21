<?php

declare(strict_types=1);

namespace ContentBlocks\Tests\Controller;

use ContentBlocks\Ai\AiAction;
use ContentBlocks\Ai\AiFeatures;
use ContentBlocks\Ai\AltTextRequest;
use ContentBlocks\Ai\ContentRequest;
use ContentBlocks\Ai\GeneratedContentWriter;
use ContentBlocks\Ai\TextRequest;
use ContentBlocks\Block\BlockDataDefaults;
use ContentBlocks\Block\BlockDataKeys;
use ContentBlocks\Block\CollectionItemIds;
use ContentBlocks\BlockType\AbstractBlockType;
use ContentBlocks\BlockType\BlockTypeRegistry;
use ContentBlocks\Builder\BuilderStructure;
use ContentBlocks\Clipboard\BlockDataReplayer;
use ContentBlocks\Clipboard\ClipboardPaster;
use ContentBlocks\Content\ContentManipulator;
use ContentBlocks\Content\DraftOrder;
use ContentBlocks\Controller\AiController;
use ContentBlocks\Entity\ContentArea;
use ContentBlocks\Form\Extension\BlockFormExtensionCollection;
use ContentBlocks\Form\Type\BlockFormType;
use ContentBlocks\Form\Type\PaletteColorType;
use ContentBlocks\Form\Type\SectionSettingsType;
use ContentBlocks\Schema\ContentSchemaBuilder;
use ContentBlocks\Schema\FormSchemaMapper;
use ContentBlocks\Section\SectionStyleRegistry;
use ContentBlocks\SectionTemplate\SectionTemplateInstantiator;
use ContentBlocks\SectionTemplate\SectionTemplateSerializer;
use ContentBlocks\Security\ContentBlocksAccessDeniedException;
use ContentBlocks\Security\DenyAllAccessChecker;
use ContentBlocks\Tests\Fixtures\FixedBuilderStructureResolver;
use ContentBlocks\Tests\Fixtures\RecordingAiProvider;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Validator\ValidatorExtension;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\Forms;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Validation;

final class AiControllerTest extends ControllerTestCase
{
    private RecordingAiProvider $provider;
    private ContentArea $area;

    protected function setUp(): void
    {
        $this->provider = new RecordingAiProvider();
        $this->area = $this->makeArea(1);
    }

    // ---------- text ----------

    public function testATextFieldIsRewrittenWithItsContext(): void
    {
        $column = $this->makeColumn($this->makeSection($this->area, 10), 20);
        $block = $this->makeBlock($column, 30, type: 'ai_fixture');

        $response = $this->controller([$block])->text(1, $this->post([
            'action' => 'shorten',
            'text' => 'A long sentence.',
            'blockId' => 30,
            'field' => 'headline',
        ]));

        $this->assertSame(['text' => 'Rewritten'], self::json($response));
        $request = $this->provider->requests[0];
        $this->assertInstanceOf(TextRequest::class, $request);
        $this->assertSame('shorten', $request->action);
        $this->assertSame('ai_fixture', $request->blockType);
        $this->assertSame('headline', $request->field);
        $this->assertSame(60, $request->maxLength, 'read from the block form');
        $this->assertSame('A page about shoes.', $request->brief);
        $this->assertFalse($request->html);
    }

    public function testABlockOfAnotherAreaLendsNoContext(): void
    {
        $other = $this->makeArea(2);
        $block = $this->makeBlock($this->makeColumn($this->makeSection($other, 11), 21), 31, type: 'ai_fixture');

        $this->controller([$block])->text(1, $this->post(['action' => 'rewrite', 'text' => 'x', 'blockId' => 31]));

        $request = $this->provider->requests[0];
        $this->assertInstanceOf(TextRequest::class, $request);
        $this->assertNull($request->blockType);
    }

    public function testToneTakesOnlyAConfiguredTone(): void
    {
        $controller = $this->controller();

        $refused = $controller->text(1, $this->post(['action' => 'tone', 'text' => 'Hi', 'tone' => 'sarcastic']));
        $accepted = $controller->text(1, $this->post(['action' => 'tone', 'text' => 'Hi', 'tone' => 'friendly']));

        $this->assertSame(Response::HTTP_BAD_REQUEST, $refused->getStatusCode());
        $this->assertSame(Response::HTTP_OK, $accepted->getStatusCode());
    }

    /**
     * @param array<string, mixed> $body
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('invalidTextBodies')]
    public function testAnInvalidTextRequestIsRefused(array $body): void
    {
        $response = $this->controller()->text(1, $this->post($body));

        $this->assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
        $this->assertSame([], $this->provider->requests, 'the provider is never called');
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function invalidTextBodies(): iterable
    {
        yield 'unknown action' => [['action' => 'translate', 'text' => 'x']];
        yield 'a content action' => [['action' => 'add', 'text' => 'x']];
        yield 'blank text' => [['action' => 'rewrite', 'text' => '   ']];
        yield 'text too long' => [['action' => 'rewrite', 'text' => str_repeat('a', 20001)]];
    }

    public function testASwitchedOffActionIsNotThere(): void
    {
        $response = $this->controller(actions: [AiAction::SHORTEN => false])
            ->text(1, $this->post(['action' => 'shorten', 'text' => 'x']));

        $this->assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());
        $this->assertSame(['error' => 'disabled'], self::json($response));
    }

    public function testAnActionTheProviderRefusesIsNotThereEither(): void
    {
        $this->provider->unsupported = [AiAction::REWRITE];

        $response = $this->controller()->text(1, $this->post(['action' => 'rewrite', 'text' => 'x']));

        $this->assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());
    }

    public function testAProviderFailureIsSaidWithoutItsMessage(): void
    {
        $this->provider->failure = new \RuntimeException('401 from api.example: key sk-secret');

        $response = $this->controller()->text(1, $this->post(['action' => 'rewrite', 'text' => 'x']));

        $this->assertSame(Response::HTTP_BAD_GATEWAY, $response->getStatusCode());
        $this->assertSame(['error' => 'provider_failed'], self::json($response));
    }

    public function testAnEmptyAnswerIsNotAProposal(): void
    {
        $this->provider->text = "  \n";

        $response = $this->controller()->text(1, $this->post(['action' => 'rewrite', 'text' => 'x']));

        $this->assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode());
    }

    public function testEveryActionNeedsTheCsrfToken(): void
    {
        $controller = $this->controller(csrfValid: false);

        foreach (['text', 'altText', 'content'] as $method) {
            $this->assertSame(Response::HTTP_FORBIDDEN, $controller->{$method}(1, $this->post([]))->getStatusCode());
        }
        $this->assertSame([], $this->provider->requests);
    }

    public function testEveryActionNeedsEditAccess(): void
    {
        $this->expectException(ContentBlocksAccessDeniedException::class);

        $this->controller(allow: false)->text(1, $this->post(['action' => 'rewrite', 'text' => 'x']));
    }

    public function testAnUnknownAreaIs404(): void
    {
        $response = $this->controller()->text(99, $this->post(['action' => 'rewrite', 'text' => 'x']));

        $this->assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());
    }

    // ---------- alt text ----------

    public function testAnImageIsDescribedFromAnAbsoluteUrl(): void
    {
        $block = $this->makeBlock($this->makeColumn($this->makeSection($this->area, 10), 20), 30, type: 'ai_fixture');
        $block->setDraftData(['caption' => 'Our red trail shoe']);

        $response = $this->controller([$block])->altText(1, $this->post(
            ['src' => '/uploads/shoe.webp', 'blockId' => 30],
            'https://shop.example',
        ));

        $this->assertSame(['text' => 'Rewritten'], self::json($response));
        $request = $this->provider->requests[0];
        $this->assertInstanceOf(AltTextRequest::class, $request);
        $this->assertSame('/uploads/shoe.webp', $request->src);
        $this->assertSame('https://shop.example/uploads/shoe.webp', $request->url);
        $this->assertSame('Our red trail shoe', $request->context);
    }

    #[\PHPUnit\Framework\Attributes\TestWith(['javascript:alert(1)'])]
    #[\PHPUnit\Framework\Attributes\TestWith(['data:image/png;base64,AAAA'])]
    #[\PHPUnit\Framework\Attributes\TestWith([''])]
    #[\PHPUnit\Framework\Attributes\TestWith(['/a b.png'])]
    public function testOnlyAPathOrAnHttpUrlIsDescribed(string $src): void
    {
        $response = $this->controller()->altText(1, $this->post(['src' => $src]));

        $this->assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
    }

    public function testAltTextSwitchedOffIsNotThere(): void
    {
        $response = $this->controller(actions: [AiAction::ALT_TEXT => false])
            ->altText(1, $this->post(['src' => '/a.png']));

        $this->assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());
    }

    // ---------- content ----------

    public function testContentIsAddedAfterTheSelectedSection(): void
    {
        $first = $this->makeSection($this->area, 10, 0);
        $this->makeColumn($first, 20);
        $last = $this->makeSection($this->area, 11, 1);
        $this->makeColumn($last, 21);
        $this->provider->document = ['sections' => [self::section('Fresh'), self::section('Extra')]];

        $response = $this->controller([$first, $last])->content(1, $this->post([
            'action' => 'add',
            'prompt' => 'Benefits, then an FAQ',
            'targetSectionId' => 10,
        ]));

        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
        $sections = DraftOrder::sections($this->area);
        $this->assertCount(4, $sections, 'the model decides how many sections');
        $this->assertSame([$first, $last], [$sections[0], $sections[3]]);
        $this->assertFalse($first->isDeleted() || $last->isDeleted(), 'adding replaces nothing');
        $this->assertSame(1, $this->flushCount);

        $request = $this->provider->requests[0];
        $this->assertInstanceOf(ContentRequest::class, $request);
        $this->assertSame(8, $request->maxSections);
        $this->assertSame('Benefits, then an FAQ', $request->prompt);
        $this->assertCount(2, $request->existing, 'the page so far, for the model to fit in');
        $this->assertSame([], $request->current, 'nothing to revise');
        $this->assertArrayHasKey('block.ai_fixture', $request->schema->schema['$defs']);
    }

    public function testWithNothingSelectedContentGoesAtTheEnd(): void
    {
        $first = $this->makeSection($this->area, 10, 0);
        $this->makeColumn($first, 20);
        $this->provider->document = ['sections' => [self::section('Fresh')]];

        $this->controller([$first])->content(1, $this->post(['action' => 'add', 'prompt' => 'x']));

        $sections = DraftOrder::sections($this->area);
        $this->assertSame($first, $sections[0]);
        $this->assertCount(2, $sections);
    }

    public function testThereIsNoReplaceFlagAnyMore(): void
    {
        $old = $this->makeSection($this->area, 10);
        $this->makeColumn($old, 20);
        $this->provider->document = ['sections' => [self::section('One')]];

        $this->controller([$old])->content(1, $this->post(['action' => 'add', 'prompt' => 'x', 'replace' => true]));

        $this->assertFalse($old->isDeleted(), 'rewriting the page is edit_page');
    }

    public function testASectionIsRevisedInItsPlace(): void
    {
        $first = $this->makeSection($this->area, 10, 0);
        $this->makeColumn($first, 20);
        $target = $this->makeSection($this->area, 11, 1);
        $this->makeBlock($this->makeColumn($target, 21), 31, type: 'ai_fixture')
            ->setDraftData(['headline' => 'Old']);
        $last = $this->makeSection($this->area, 12, 2);
        $this->makeColumn($last, 22);
        $this->provider->document = ['sections' => [self::section('New'), self::section('Extra')]];

        $response = $this->controller([$first, $target, $last])->content(1, $this->post([
            'action' => 'edit_section',
            'prompt' => 'Make it punchier',
            'targetSectionId' => 11,
        ]));

        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
        $sections = DraftOrder::sections($this->area);
        $this->assertCount(3, $sections, 'one section in, one out');
        $this->assertSame([$first, $last], [$sections[0], $sections[2]]);
        $this->assertTrue($target->isDeleted());

        $request = $this->provider->requests[0];
        $this->assertInstanceOf(ContentRequest::class, $request);
        $this->assertSame('edit_section', $request->action);
        $this->assertSame(1, $request->maxSections);
        $this->assertCount(1, $request->current, 'the section to revise');
        $this->assertSame('Old', $request->current[0]['columns'][0]['blocks'][0]['data']['headline']);
        $this->assertCount(3, $request->existing, 'and the page around it');
    }

    public function testTheSelectedBlockNamesItsSection(): void
    {
        $target = $this->makeSection($this->area, 11);
        $block = $this->makeBlock($this->makeColumn($target, 21), 31, type: 'ai_fixture');
        $this->provider->document = ['sections' => [self::section('New')]];

        $response = $this->controller([$target, $block])->content(1, $this->post([
            'action' => 'edit_section',
            'prompt' => 'x',
            'targetBlockId' => 31,
        ]));

        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
        $this->assertTrue($target->isDeleted());
    }

    public function testRevisingASectionNeedsOne(): void
    {
        $other = $this->makeArea(2);
        $foreign = $this->makeSection($other, 50);

        $response = $this->controller([$foreign])->content(1, $this->post([
            'action' => 'edit_section',
            'prompt' => 'x',
            'targetSectionId' => 50,
        ]));

        $this->assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
        $this->assertSame(['error' => 'invalid', 'field' => 'targetSectionId'], self::json($response));
        $this->assertSame([], $this->provider->requests, 'a section of another area is no target');
    }

    public function testThePageIsRevisedFromWhatIsThere(): void
    {
        $old = $this->makeSection($this->area, 10);
        $this->makeBlock($this->makeColumn($old, 20), 30, type: 'ai_fixture')->setDraftData(['headline' => 'Old']);
        $this->provider->document = ['sections' => [self::section('Revised')]];

        $response = $this->controller([$old])->content(1, $this->post(['action' => 'edit_page', 'prompt' => 'x']));

        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
        $this->assertTrue($old->isDeleted());
        $this->assertCount(1, DraftOrder::sections($this->area));
        $request = $this->provider->requests[0];
        $this->assertInstanceOf(ContentRequest::class, $request);
        $this->assertSame($request->existing, $request->current, 'the whole page is the thing revised');
    }

    public function testEachEditIsSwitchedOffOnItsOwn(): void
    {
        $target = $this->makeSection($this->area, 11);
        $controller = $this->controller([$target], actions: [AiAction::EDIT_SECTION => false]);

        $response = $controller->content(1, $this->post(['action' => 'edit_section', 'prompt' => 'x', 'targetSectionId' => 11]));

        $this->assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());
        $this->assertFalse($target->isDeleted());
    }

    public function testFixedSectionsAnswer409(): void
    {
        $this->provider->document = ['sections' => [self::section('One')]];

        $response = $this->controller(structure: new BuilderStructure(BuilderStructure::SECTIONS_FIXED))
            ->content(1, $this->post(['action' => 'add', 'prompt' => 'A page']));

        $this->assertSame(Response::HTTP_CONFLICT, $response->getStatusCode());
        $this->assertSame(['error' => 'sections_locked'], self::json($response));
        $this->assertSame(0, $this->flushCount);
    }

    public function testAnUnusableAnswerIs422(): void
    {
        $this->provider->document = ['html' => '<h1>Not a document</h1>'];

        $response = $this->controller()->content(1, $this->post(['action' => 'add', 'prompt' => 'A page']));

        $this->assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode());
        $this->assertSame(['error' => 'unreadable'], self::json($response));
    }

    public function testGenerationFailureWritesNothing(): void
    {
        $this->provider->failure = new \RuntimeException('timeout');

        $response = $this->controller()->content(1, $this->post(['action' => 'add', 'prompt' => 'x']));

        $this->assertSame(Response::HTTP_BAD_GATEWAY, $response->getStatusCode());
        $this->assertSame(0, $this->flushCount);
    }

    public function testEachContentActionIsSwitchedOffOnItsOwn(): void
    {
        $controller = $this->controller(actions: [AiAction::ADD => false]);

        $add = $controller->content(1, $this->post(['action' => 'add', 'prompt' => 'x']));
        $this->assertSame(Response::HTTP_NOT_FOUND, $add->getStatusCode());

        $this->provider->document = ['sections' => [self::section('One')]];
        $edit = $controller->content(1, $this->post(['action' => 'edit_page', 'prompt' => 'x']));
        $this->assertSame(Response::HTTP_OK, $edit->getStatusCode());
    }

    public function testABlankPromptIsRefused(): void
    {
        $response = $this->controller()->content(1, $this->post(['action' => 'add', 'prompt' => ' ']));

        $this->assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
    }

    // ---------- helpers ----------

    /**
     * @param list<object>        $entities
     * @param array<string, bool> $actions
     */
    private function controller(
        array $entities = [],
        array $actions = [],
        bool $csrfValid = true,
        bool $allow = true,
        ?BuilderStructure $structure = null,
    ): AiController {
        $registry = new BlockTypeRegistry();
        $registry->register(new AiFixtureBlock());

        $factory = Forms::createFormFactoryBuilder()
            ->addExtension(new ValidatorExtension(Validation::createValidator()))
            ->addType(new BlockFormType(new BlockFormExtensionCollection()))
            ->addType(new SectionSettingsType(new SectionStyleRegistry()))
            ->addType(new PaletteColorType())
            ->getFormFactory();

        $em = $this->makeEm([$this->area, ...$entities]);
        $resolver = new FixedBuilderStructureResolver($structure ?? new BuilderStructure());
        $content = new ContentManipulator(null, $registry);
        $paster = new ClipboardPaster(
            new SectionTemplateInstantiator($registry, new BlockDataKeys($registry, $factory)),
            $registry,
            new BlockDataReplayer($registry, $factory, new BlockDataDefaults(), new CollectionItemIds()),
            $content,
        );

        $brief = new class () implements \ContentBlocks\Ai\AiBriefProviderInterface {
            public function briefFor(ContentArea $area): ?string
            {
                return 'A page about shoes.';
            }
        };

        return new AiController(
            $em,
            $allow ? $this->makeAccessChecker() : new DenyAllAccessChecker(),
            $this->makeCsrfManager($csrfValid),
            new AiFeatures([$this->provider], $actions),
            new ContentSchemaBuilder($registry, $factory, new FormSchemaMapper(), structureResolver: $resolver),
            new GeneratedContentWriter($paster, $content, $resolver, $factory),
            $this->makeJournal($em),
            new SectionTemplateSerializer(),
            $brief,
        );
    }

    /**
     * @param array<string, mixed> $body
     */
    private function post(array $body, string $host = 'http://localhost'): Request
    {
        return Request::create(
            $host . '/_content-blocks/area/1/ai',
            'POST',
            server: ['HTTP_X-CSRF-Token' => 'token'],
            content: json_encode($body, \JSON_THROW_ON_ERROR),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private static function section(string $headline): array
    {
        return [
            'format' => 'content-blocks/section-v1',
            'layout' => 'full',
            'columns' => [['preset' => 'col-12', 'blocks' => [
                ['type' => 'ai_fixture', 'data' => ['headline' => $headline]],
            ]]],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function json(JsonResponse $response): array
    {
        return json_decode((string) $response->getContent(), true, flags: \JSON_THROW_ON_ERROR);
    }
}

final class AiFixtureBlock extends AbstractBlockType
{
    public function getType(): string
    {
        return 'ai_fixture';
    }

    public function getLabel(): string
    {
        return 'AI fixture';
    }

    public function buildForm(FormBuilderInterface $builder, array $data): void
    {
        $builder->add('headline', TextType::class, [
            'required' => false,
            'constraints' => [new Assert\Length(max: 60)],
        ]);
    }

    public function getDefaultData(): array
    {
        return ['headline' => ''];
    }
}
