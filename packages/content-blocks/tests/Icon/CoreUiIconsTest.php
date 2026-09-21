<?php

declare(strict_types=1);

namespace ContentBlocks\Tests\Icon;

use ContentBlocks\Form\Type\SectionSettingsType;
use ContentBlocks\Form\Type\Styling\StylingType;
use ContentBlocks\Icon\CoreUiIcons;
use PHPUnit\Framework\TestCase;

final class CoreUiIconsTest extends TestCase
{
    /** A missing name renders as text: the core's own fields must never. */
    public function testEveryIconTheCoreFieldsNameExists(): void
    {
        $icons = (new CoreUiIcons())->getIcons();

        foreach ($this->namesUsedBy(StylingType::class) + $this->namesUsedBy(SectionSettingsType::class) as $name) {
            $this->assertArrayHasKey($name, $icons, sprintf('"%s" is used but not drawn', $name));
        }
    }

    /** Rendered raw, so the markup is held to plain drawing elements. */
    public function testTheMarkupIsInnerDrawingOnly(): void
    {
        foreach ((new CoreUiIcons())->getIcons() as $name => $markup) {
            $this->assertMatchesRegularExpression('/^[a-z][a-z0-9-]*$/', $name);
            $this->assertStringNotContainsString('<svg', $markup, $name);
            $this->assertDoesNotMatchRegularExpression('/<script|\son[a-z]+=|href=/i', $markup, $name);
            preg_match_all('/<([a-z]+)/', $markup, $tags);
            $this->assertSame([], array_diff($tags[1], ['path', 'rect', 'circle', 'g']), $name);
        }
    }

    /**
     * @param class-string $class
     *
     * @return array<string, string>
     */
    private function namesUsedBy(string $class): array
    {
        $names = [];
        $constants = $this->constants($class);
        array_walk_recursive(
            $constants,
            static function (mixed $value) use (&$names): void {
                if (\is_string($value) && preg_match('/^(auto|pos-|valign-|halign-|fit-|display-|width-)/', $value)) {
                    $names[$value] = $value;
                }
            },
        );
        $this->assertNotSame([], $names);

        return $names;
    }

    /**
     * @param class-string $class
     *
     * @return array<string, mixed>
     */
    private function constants(string $class): array
    {
        return array_filter(
            (new \ReflectionClass($class))->getConstants(),
            static fn (string $name): bool => str_ends_with($name, 'ICONS'),
            \ARRAY_FILTER_USE_KEY,
        );
    }
}
