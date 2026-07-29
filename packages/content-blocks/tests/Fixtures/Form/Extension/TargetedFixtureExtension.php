<?php

declare(strict_types=1);

namespace ContentBlocks\Tests\Fixtures\Form\Extension;

use ContentBlocks\Form\Extension\AsBlockFormExtension;

/** Targets a single block type, with an explicit priority. */
#[AsBlockFormExtension('button', priority: 10)]
final class TargetedFixtureExtension extends RecordingExtension
{
}
