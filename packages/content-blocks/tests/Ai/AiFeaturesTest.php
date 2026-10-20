<?php

declare(strict_types=1);

namespace ContentBlocks\Tests\Ai;

use ContentBlocks\Ai\AiAction;
use ContentBlocks\Ai\AiFeatures;
use ContentBlocks\Tests\Fixtures\RecordingAiProvider;
use ContentBlocks\Twig\AiExtension;
use PHPUnit\Framework\TestCase;

/**
 * Each action is on only when the configuration allows it and the provider
 * supports it: the two halves of "switched off on its own".
 */
final class AiFeaturesTest extends TestCase
{
    public function testWithoutAProviderNothingIsOfferedButApply(): void
    {
        $features = new AiFeatures();

        $this->assertNull($features->provider());
        $this->assertSame([], $features->enabledActions());
        $this->assertTrue($features->isEnabled(AiAction::APPLY), 'apply calls no model');
    }

    public function testWithAProviderEveryActionIsOnByDefault(): void
    {
        $features = new AiFeatures([new RecordingAiProvider()]);

        $this->assertSame(AiAction::PROVIDED, $features->enabledActions());
    }

    public function testAnActionSwitchedOffInTheConfigurationIsOff(): void
    {
        $features = new AiFeatures([new RecordingAiProvider()], [AiAction::SHORTEN => false, AiAction::APPLY => false]);

        $this->assertFalse($features->isEnabled(AiAction::SHORTEN));
        $this->assertFalse($features->isEnabled(AiAction::APPLY));
        $this->assertTrue($features->isEnabled(AiAction::REWRITE), 'the others are untouched');
    }

    public function testAnActionTheProviderDoesNotSupportIsOff(): void
    {
        $features = new AiFeatures([new RecordingAiProvider([AiAction::ALT_TEXT])]);

        $this->assertFalse($features->isEnabled(AiAction::ALT_TEXT));
        $this->assertNotContains(AiAction::ALT_TEXT, $features->enabledActions());
    }

    public function testToneNeedsAtLeastOneTone(): void
    {
        $features = new AiFeatures([new RecordingAiProvider()], aiTones: []);

        $this->assertFalse($features->isEnabled(AiAction::TONE));
    }

    public function testAnUnknownActionIsOff(): void
    {
        $this->assertFalse((new AiFeatures([new RecordingAiProvider()]))->isEnabled('translate'));
    }

    public function testTheFirstProviderAnswers(): void
    {
        $first = new RecordingAiProvider();

        $this->assertSame($first, (new AiFeatures([$first, new RecordingAiProvider()]))->provider());
    }

    public function testTheSectionCapIsAtLeastOne(): void
    {
        $this->assertSame(1, (new AiFeatures(aiMaxSections: 0))->maxSections());
    }

    public function testTwigListsTonesOnlyWhenSomethingIsOn(): void
    {
        $functions = static function (AiFeatures $features): array {
            $out = [];
            foreach ((new AiExtension($features))->getFunctions() as $function) {
                $callable = $function->getCallable();
                \assert(\is_callable($callable));
                $out[$function->getName()] = $callable();
            }

            return $out;
        };

        $this->assertSame(['cb_ai_actions' => [], 'cb_ai_tones' => []], $functions(new AiFeatures()));
        $this->assertSame(
            AiFeatures::DEFAULT_TONES,
            $functions(new AiFeatures([new RecordingAiProvider()]))['cb_ai_tones'],
        );
    }
}
