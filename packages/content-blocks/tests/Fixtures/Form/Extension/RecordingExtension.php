<?php

declare(strict_types=1);

namespace ContentBlocks\Tests\Fixtures\Form\Extension;

use ContentBlocks\Form\Extension\BlockFormExtensionInterface;
use Symfony\Component\Form\FormBuilderInterface;

/**
 * Base for the attribute fixtures below: records the block types it was called
 * for, so a container-level test can assert *which* extensions ran and in what
 * order without touching a real form.
 *
 * Named (not anonymous) classes are required here — autoconfiguration reads the
 * attribute off the service's class, which the container resolves by name.
 */
abstract class RecordingExtension implements BlockFormExtensionInterface
{
    /** @var list<string> */
    public array $seen = [];

    /** Optional probe, set by a test that needs to observe invocation order. */
    public ?\Closure $onBuild = null;

    public function buildForm(FormBuilderInterface $builder, array $data, string $blockType): void
    {
        $this->seen[] = $blockType;

        if (null !== $this->onBuild) {
            ($this->onBuild)($blockType);
        }
    }
}
