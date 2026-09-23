<?php

declare(strict_types=1);

namespace ContentBlocks\Kit\Tests\Security;

use ContentBlocks\Kit\Block\ButtonBlock;
use ContentBlocks\Kit\Security\SafeLink;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\Extension\Validator\ValidatorExtension;
use Symfony\Component\Form\Forms;
use Symfony\Component\Validator\Validation;

final class SafeLinkTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function safeLinks(): iterable
    {
        yield 'https' => ['https://example.com/a?b=c'];
        yield 'http' => ['http://example.com'];
        yield 'mailto' => ['mailto:hello@example.com'];
        yield 'tel' => ['tel:+33100000000'];
        yield 'relative' => ['/shop'];
        yield 'anchor' => ['#top'];
        yield 'protocol-relative' => ['//cdn.example.com/x'];
        yield 'bare path with a colon later' => ['page?at=12:30'];
        yield 'empty' => [''];
    }

    /** @return iterable<string, array{string}> */
    public static function unsafeLinks(): iterable
    {
        yield 'javascript' => ['javascript:alert(1)'];
        yield 'upper case' => ['JaVaScRiPt:alert(1)'];
        yield 'tab inside the scheme' => ["java\tscript:alert(1)"];
        yield 'newline inside the scheme' => ["java\nscript:alert(1)"];
        yield 'leading spaces' => ['   javascript:alert(1)'];
        yield 'data' => ['data:text/html,<script>alert(1)</script>'];
        yield 'vbscript' => ['vbscript:msgbox(1)'];
    }

    #[DataProvider('safeLinks')]
    public function testASafeLinkIsKept(string $url): void
    {
        $this->assertTrue(SafeLink::isSafe($url));
        $this->assertSame($url, SafeLink::filter($url));
    }

    #[DataProvider('unsafeLinks')]
    public function testAnUnsafeLinkIsEmptied(string $url): void
    {
        $this->assertFalse(SafeLink::isSafe($url));
        $this->assertSame('', SafeLink::filter($url));
    }

    public function testANonStringIsEmptied(): void
    {
        $this->assertSame('', SafeLink::filter(['javascript:alert(1)']));
    }

    // The form refuses what the render would have emptied anyway.
    public function testTheButtonFormRefusesAJavascriptLink(): void
    {
        $this->assertTrue($this->submitButton('/contact'));
        $this->assertFalse($this->submitButton('javascript:alert(1)'));
    }

    private function submitButton(string $url): bool
    {
        $block = new ButtonBlock();
        $factory = Forms::createFormFactoryBuilder()
            ->addExtension(new ValidatorExtension(Validation::createValidator()))
            ->addTypeExtension(new \ContentBlocks\Form\Extension\TranslatableFieldTypeExtension())
            ->getFormFactory();
        $builder = $factory->createBuilder(FormType::class, $block->getDefaultData());
        $block->buildForm($builder, $block->getDefaultData());
        $form = $builder->getForm();
        $form->submit(['url' => $url] + $block->getDefaultData(), false);

        return $form->get('url')->isValid();
    }
}
