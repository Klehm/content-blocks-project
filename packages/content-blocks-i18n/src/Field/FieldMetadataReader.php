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

/**
 * Reads the *presentation* of a block's fields off its edit form: label,
 * translation domain, and which widget the workbench should render.
 *
 * Deliberately **not** the authority on which fields are translatable — that is
 * {@see \ContentBlocks\Translation\TranslatableFieldsInterface} in the core,
 * where the `cb_translatable` convention is frozen. Two readers of the same tag
 * would eventually disagree; here there is one reader of the tag and one reader
 * of everything else, joined by path pattern. (`FieldCatalogMatchesCoreTest`
 * pins that the join covers every tagged field of every kit block.)
 *
 * Reading the built form also means a field a host added through
 * {@see \ContentBlocks\Form\Extension\BlockFormExtensionInterface} arrives with
 * its label already correct, without the host registering anything here.
 */
final class FieldMetadataReader
{
    /** Matches the core walker's guard against a form type that nests itself. */
    private const MAX_DEPTH = 10;

    /**
     * How a form type presents as a workbench input. Anything unlisted is a
     * single-line text field, which is the safe default: it renders every value
     * and mangles none.
     *
     * @var array<class-string, string>
     */
    private const WIDGETS = [
        TextareaType::class => 'textarea',
        UrlType::class => 'url',
        EmailType::class => 'email',
    ];

    /** @var array<string, array<string, array{label: string, labelDomain: string|null, widget: string}>> */
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
     * @return array<string, array{label: string, labelDomain: string|null, widget: string}>
     */
    public function forBlockType(string $blockType, array $data = []): array
    {
        // Two blocks of the same type on a page have the same form shape, and a
        // page can hold dozens. The cache key ignores $data because it only
        // affects conditionally-declared fields, and a miss there costs a
        // humanized label rather than a wrong one.
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

    /**
     * @param array<string, array{label: string, labelDomain: string|null, widget: string}> $out
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

            // A collection has no children until it is bound to data; its shape
            // lives in `entry_type`. Same descent the core walker makes, so the
            // patterns the two produce line up.
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

        // Symfony's own fallback when a field declares no label: humanize the
        // field name. Reproduced rather than reached for because we never build
        // a FormView here — a view would mean instantiating data mappers for
        // every block on the page.
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

        // The kit's rich-text field is a custom type wrapping a textarea that a
        // JS editor takes over, and there are as many such types as there are
        // editors a host might wire. Matching the name rather than a class keeps
        // this from depending on the kit, which this package does not require.
        if (str_contains($type->getBlockPrefix(), 'rich_text')) {
            return 'html';
        }

        return 'text';
    }
}
