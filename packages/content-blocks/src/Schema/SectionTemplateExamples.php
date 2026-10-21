<?php

declare(strict_types=1);

namespace ContentBlocks\Schema;

use ContentBlocks\Block\BlockDataKeys;
use ContentBlocks\BlockType\BlockTypeRegistry;
use ContentBlocks\Entity\SectionTemplate;
use ContentBlocks\SectionTemplate\SectionTemplateSerializerInterface;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The host's saved sections, as examples of the output: sections an editor
 * already judged good say more about the house style than a description.
 *
 * @see docs/internals/content-schema.md#examples-from-the-section-library
 *
 * @internal
 */
final class SectionTemplateExamples
{
    /** Read past the limit, since a stale or unreadable row is skipped. */
    private const SCAN_FACTOR = 4;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly BlockTypeRegistry $registry,
        private readonly int $contentVersion = 1,
    ) {
    }

    /**
     * Latest first, skipping a template whose version, format or block types
     * would teach a shape this app refuses.
     *
     * @return list<array{name: string, section: array<string, mixed>}>
     */
    public function latest(int $limit): array
    {
        if ($limit <= 0) {
            return [];
        }

        $rows = $this->em->getRepository(SectionTemplate::class)->findBy(
            [],
            ['createdAt' => 'DESC', 'id' => 'DESC'],
            $limit * self::SCAN_FACTOR,
        );

        $examples = [];
        foreach ($rows as $template) {
            if (!$this->usable($template)) {
                continue;
            }
            // `settings: null` is how an unstyled section is saved; the
            // schema wants an object or nothing.
            $section = array_filter(self::clean($template->getPayload()), static fn (mixed $v): bool => $v !== null);
            $examples[] = ['name' => $template->getName(), 'section' => $section];
            if (\count($examples) === $limit) {
                break;
            }
        }

        return $examples;
    }

    private function usable(SectionTemplate $template): bool
    {
        if ($template->getContentVersion() !== $this->contentVersion) {
            return false;
        }
        if (($template->getPayload()['format'] ?? null) !== SectionTemplateSerializerInterface::FORMAT) {
            return false;
        }
        foreach ($template->getBlockTypes() as $type) {
            if (!$this->registry->has($type)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Without the reserved keys (`_id`, `_order`…): the schema does not offer
     * them, so an example carrying them would contradict it.
     *
     * @param array<array-key, mixed> $value
     *
     * @return array<array-key, mixed>
     */
    public static function clean(array $value): array
    {
        $out = [];
        foreach ($value as $key => $item) {
            if (\is_string($key) && str_starts_with($key, BlockDataKeys::RESERVED_PREFIX)) {
                continue;
            }
            $out[$key] = \is_array($item) ? self::clean($item) : $item;
        }

        return $out;
    }
}
