<?php

declare(strict_types=1);

namespace ContentBlocks\I18n\Field;

use ContentBlocks\BlockType\BlockTypeRegistry;
use ContentBlocks\Form\Type\BlockFormType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\UrlType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Reads the *presentation* of a block's fields — label, domain, widget. Not the
 * authority on which are translatable; the core keeps that.
 *
 * @see docs/internals/i18n.md#two-readers-of-the-form-one-reader-of-the-tag
 */
final class FieldMetadataReader implements ResetInterface
{
    /** Matches the core walker's guard against a self-nesting type. */
    private const MAX_DEPTH = 10;

    /**
     * Anything unlisted is a single-line text field — the safe default, since
     * it renders every value and mangles none.
     *
     * @var array<class-string, string>
     */
    private const WIDGETS = [
        TextareaType::class => 'textarea',
        UrlType::class => 'url',
        EmailType::class => 'email',
    ];

    /**
     * @var array<string, array<string, array{
     *     label: string,
     *     labelDomain: string|null,
     *     widget: string,
     * }>>
     */
    private array $cache = [];

    public function __construct(
        private readonly BlockTypeRegistry $registry,
        private readonly FormFactoryInterface $formFactory,
    ) {
    }

    /**
     * Metadata for every leaf field of $blockType, keyed by path pattern.
     *
     * @param array<string, mixed> $data
     *
     * @return array<string, array{
     *     label: string,
     *     labelDomain: string|null,
     *     widget: string,
     * }>
     */
    public function forBlockType(string $blockType, array $data = []): array
    {
        // The key ignores $data: it only affects conditionally-declared
        // fields, where a miss costs a humanized label, not a wrong one.
        if (isset($this->cache[$blockType])) {
            return $this->cache[$blockType];
        }

        if (!$this->registry->has($blockType)) {
            return [];
        }

        $builder = $this->formFactory->createBuilder(BlockFormType::class, null, [
            'block_type' => $this->registry->get($blockType),
            'block_data' => $data,
        ]);

        $out = [];
        $this->collect($builder, '', $out, 0);

        return $this->cache[$blockType] = $out;
    }

    public function reset(): void
    {
        $this->cache = [];
    }

    /**
     * @param array<string, array{
     *     label: string,
     *     labelDomain: string|null,
     *     widget: string,
     * }> $out
     */
    private function collect(FormBuilderInterface $builder, string $prefix, array &$out, int $depth): void
    {
        if ($depth > self::MAX_DEPTH) {
            return;
        }

        foreach ($builder->all() as $name => $child) {
            // Form child names are array keys, so a numeric one (a collection
            // entry bound to data) arrives as an int.
            $name = (string) $name;
            $path = $prefix === '' ? $name : $prefix . '.' . $name;

            // Same descent the core walker makes, so the patterns line up.
            $entryType = $child->hasOption('entry_type') ? $child->getOption('entry_type') : null;
            if (\is_string($entryType) && $entryType !== '') {
                $entryOptions = $child->hasOption('entry_options') ? $child->getOption('entry_options') : [];
                $entry = $this->formFactory->createBuilder($entryType, null, \is_array($entryOptions) ? $entryOptions : []);
                $this->collect($entry, $path . '[]', $out, $depth + 1);

                continue;
            }

            $out[$path] = [
                'label' => $this->labelOf($child, $name),
                'labelDomain' => $this->domainOf($child),
                'widget' => $this->widgetOf($child),
            ];

            if (\count($child->all()) > 0) {
                $this->collect($child, $path, $out, $depth + 1);
            }
        }
    }

    private function labelOf(FormBuilderInterface $builder, string $name): string
    {
        $label = $builder->hasOption('label') ? $builder->getOption('label') : null;

        if (\is_string($label) && $label !== '') {
            return $label;
        }

        // Symfony's humanize fallback, reproduced because building a FormView
        // would instantiate data mappers for every block on the page.
        return ucfirst(trim(strtolower((string) preg_replace('/(?<!^)[A-Z]|_/', ' $0', $name))));
    }

    private function domainOf(FormBuilderInterface $builder): ?string
    {
        $domain = $builder->hasOption('translation_domain') ? $builder->getOption('translation_domain') : null;

        return \is_string($domain) && $domain !== '' ? $domain : null;
    }

    private function widgetOf(FormBuilderInterface $builder): string
    {
        $type = $builder->getType()->getInnerType();

        foreach (self::WIDGETS as $class => $widget) {
            if ($type instanceof $class) {
                return $widget;
            }
        }

        // Matched by prefix, not class: there are as many rich-text types as
        // editors a host might wire, and the kit is not a dependency here.
        if (str_contains($type->getBlockPrefix(), 'rich_text')) {
            return 'html';
        }

        return 'text';
    }
}
