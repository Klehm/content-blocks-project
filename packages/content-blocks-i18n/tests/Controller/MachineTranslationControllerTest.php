<?php

declare(strict_types=1);

namespace ContentBlocks\I18n\Tests\Controller;

use ContentBlocks\Entity\Block;
use ContentBlocks\I18n\Controller\MachineTranslationController;
use ContentBlocks\I18n\Locale\TranslationLocales;
use ContentBlocks\I18n\Machine\MachineTranslator;
use ContentBlocks\I18n\Machine\TranslationJob;
use ContentBlocks\I18n\Machine\TranslationProviderInterface;
use ContentBlocks\I18n\Machine\TranslationProviderRegistry;
use ContentBlocks\I18n\Progress\TranslationInspector;
use ContentBlocks\I18n\Repository\BlockTranslationRepository;
use ContentBlocks\I18n\Storage\TranslationStore;
use ContentBlocks\I18n\Storage\TranslationWriter;
use ContentBlocks\I18n\Tests\Fixtures\CatalogFactory;
use ContentBlocks\I18n\Tests\Fixtures\Entities;
use ContentBlocks\Security\AllowAllAccessChecker;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Translation\Translator;
use Symfony\Contracts\Translation\TranslatableInterface;

final class MachineTranslationControllerTest extends TestCase
{
    // The list is for a page that rendered the builder, not for anyone.
    public function testTheProviderListNeedsTheBuilderToken(): void
    {
        $this->assertSame(403, $this->controller(csrfValid: false)
            ->providers(new Request())->getStatusCode());

        $response = $this->controller()->providers(new Request());
        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('recording', (string) $response->getContent());
    }

    // An exception here listed every registered provider back to the client.
    public function testAnUnknownProviderIsA400ThatNamesNoProvider(): void
    {
        $request = Request::create('/', 'POST', content: '{"provider":"nope"}');

        $response = $this->controller()->translateBlock(1, 'fr', $request);

        $this->assertSame(400, $response->getStatusCode());
        $this->assertStringNotContainsString('recording', (string) $response->getContent());
    }

    private function controller(bool $csrfValid = true): MachineTranslationController
    {
        $block = Entities::block(1);
        Entities::area(1, $block);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('find')->willReturnCallback(
            static fn (string $class, mixed $id): ?Block => $class === Block::class && $id === 1 ? $block : null,
        );

        $store = new TranslationStore(
            $this->createMock(BlockTranslationRepository::class),
            $this->createMock(EntityManagerInterface::class),
        );
        $locales = new TranslationLocales('en', ['fr', 'de']);
        $symfonyTranslator = new Translator('en');
        $inspector = new TranslationInspector(
            $store,
            CatalogFactory::create(),
            $locales,
            CatalogFactory::registry(),
            $symfonyTranslator,
        );
        $providers = new TranslationProviderRegistry([new class () implements TranslationProviderInterface {
            public function getName(): string
            {
                return 'recording';
            }

            public function getLabel(): string|TranslatableInterface
            {
                return 'Recording';
            }

            public function supports(string $sourceLocale, string $targetLocale): bool
            {
                return true;
            }

            public function translate(array $requests, TranslationJob $job): array
            {
                return [];
            }
        }], 'recording');

        $csrf = $this->createMock(CsrfTokenManagerInterface::class);
        $csrf->method('isTokenValid')->willReturn($csrfValid);

        return new MachineTranslationController(
            $em,
            new AllowAllAccessChecker(),
            new MachineTranslator(
                $inspector,
                new TranslationWriter($store, CatalogFactory::translatableFields(), $locales),
                $providers,
                $locales,
                $symfonyTranslator,
            ),
            $providers,
            $inspector,
            $symfonyTranslator,
            $csrf,
        );
    }
}
