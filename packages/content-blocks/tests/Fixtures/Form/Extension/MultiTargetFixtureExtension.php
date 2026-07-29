<?php

declare(strict_types=1);

namespace ContentBlocks\Tests\Fixtures\Form\Extension;

use ContentBlocks\Form\Extension\AsBlockFormExtension;

/** Targets several block types at the default priority. */
#[AsBlockFormExtension(['button', 'card'])]
final class MultiTargetFixtureExtension extends RecordingExtension
{
}
