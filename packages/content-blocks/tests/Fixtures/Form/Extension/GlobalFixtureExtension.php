<?php

declare(strict_types=1);

namespace ContentBlocks\Tests\Fixtures\Form\Extension;

use ContentBlocks\Form\Extension\AsBlockFormExtension;

/** No arguments = global: runs for every block type. */
#[AsBlockFormExtension(priority: 100)]
final class GlobalFixtureExtension extends RecordingExtension
{
}
