<?php

declare(strict_types=1);

namespace ContentBlocks\Kit\Tests\Block;

use ContentBlocks\Kit\Block\AbstractKitBlock;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Translation\TranslatableMessage;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Contracts\Translation\TranslatableInterface;

/**
 * Unit tests for the host-override machinery on {@see AbstractKitBlock}: choice
 * restriction/reordering, the permissive constraint superset, and default-value
 * merging. Driven through a small probe block so the logic is exercised in
 * isolation from any concrete kit block's schema.
 */
final class KitBlockOverridesTest extends TestCase
{
    private function block(array $choiceOverrides = [], array $defaultOverrides = []): ProbeKitBlock
    {
        return new ProbeKitBlock([], $choiceOverrides, $defaultOverrides);
    }

    public function testNoOverrideReturnsFullCodedMap(): void
    {
        $this->assertSame(
            ['Red' => 'red', 'Green' => 'green', 'Blue' => 'blue'],
            $this->block()->exposeChoices('color'),
        );
    }

    public function testOverrideRestrictsAndReorders(): void
    {
        // Host asks for blue then red, dropping green — labels are preserved.
        $this->assertSame(
            ['Blue' => 'blue', 'Red' => 'red'],
            $this->block(['color' => ['blue', 'red']])->exposeChoices('color'),
        );
    }

    public function testUnknownValuesInOverrideAreIgnored(): void
    {
        $this->assertSame(
            ['Red' => 'red'],
            $this->block(['color' => ['red', 'purple']])->exposeChoices('color'),
        );
    }

    public function testAllInvalidOverrideFallsBackToFullMap(): void
    {
        // Never render an empty <select>.
        $this->assertSame(
            ['Red' => 'red', 'Green' => 'green', 'Blue' => 'blue'],
            $this->block(['color' => ['purple']])->exposeChoices('color'),
        );
    }

    public function testEmptyOverrideFallsBackToFullMap(): void
    {
        $this->assertSame(
            ['Red' => 'red', 'Green' => 'green', 'Blue' => 'blue'],
            $this->block(['color' => []])->exposeChoices('color'),
        );
    }

    public function testConstraintUsesFullSupersetRegardlessOfRestriction(): void
    {
        // A restricted picker must not invalidate data already stored with a
        // now-hidden value, so the constraint spans the full coded set.
        $constraint = $this->block(['color' => ['red']])->exposeConstraint('color');

        $this->assertInstanceOf(Assert\Choice::class, $constraint);
        $this->assertSame(['red', 'green', 'blue'], $constraint->choices);
    }

    public function testDefaultOverrideWinsOverCodedDefault(): void
    {
        $data = $this->block([], ['color' => 'green'])->getDefaultData();

        $this->assertSame('green', $data['color']);
        $this->assertSame('hi', $data['label']); // untouched coded default
    }

    public function testUnknownDefaultKeyIsIgnored(): void
    {
        $data = $this->block([], ['nope' => 'x'])->getDefaultData();

        $this->assertArrayNotHasKey('nope', $data);
        $this->assertSame(['color' => 'red', 'label' => 'hi'], $data);
    }

    public function testDescribeExposesTheCodedSchema(): void
    {
        $desc = $this->block()->describe();

        $this->assertSame(['color' => ['Red' => 'red', 'Green' => 'green', 'Blue' => 'blue']], $desc['choices']);
        $this->assertSame(['color' => 'red', 'label' => 'hi'], $desc['defaults']);
    }
}

/**
 * Minimal concrete kit block used only by {@see KitBlockOverridesTest}; exposes
 * the protected resolution helpers so they can be asserted directly.
 */
final class ProbeKitBlock extends AbstractKitBlock
{
    public static function getType(): string
    {
        return 'probe';
    }

    public static function getLabel(): TranslatableInterface
    {
        return new TranslatableMessage('probe');
    }

    public function buildForm(FormBuilderInterface $builder, array $data): void
    {
    }

    protected function choiceFields(): array
    {
        return ['color' => ['Red' => 'red', 'Green' => 'green', 'Blue' => 'blue']];
    }

    protected function defaults(): array
    {
        return ['color' => 'red', 'label' => 'hi'];
    }

    public function exposeChoices(string $field): array
    {
        return $this->choices($field);
    }

    public function exposeConstraint(string $field): Assert\Choice
    {
        return $this->choiceConstraint($field);
    }
}
