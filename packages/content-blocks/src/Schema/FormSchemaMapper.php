<?php

declare(strict_types=1);

namespace ContentBlocks\Schema;

use Symfony\Component\Form\ClickableInterface;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\Extension\Core\Type\DateTimeType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\MoneyType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\PercentType;
use Symfony\Component\Form\Extension\Core\Type\RangeType;
use Symfony\Component\Form\Extension\Core\Type\UrlType;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Reads a built Symfony form into the JSON Schema of the data it accepts, so
 * the schema cannot drift from the form that validates the data.
 *
 * @see docs/internals/content-schema.md#derived-from-the-form
 */
final class FormSchemaMapper
{
    /**
     * @param iterable<FieldSchemaContributorInterface> $contributors
     */
    public function __construct(
        private readonly iterable $contributors = [],
        private readonly ?TranslatorInterface $translator = null,
    ) {
    }

    /**
     * The schema of a compound form: one property per mapped child.
     *
     * @return array<string, mixed>
     */
    public function map(FormInterface $form, ?string $locale = null): array
    {
        return $this->object($form, $locale);
    }

    /**
     * The schema of one field, or null when the field holds nothing an
     * author writes (a button, an unmapped or a hidden field).
     *
     * @return array<string, mixed>|null
     */
    public function field(FormInterface $field, ?string $locale = null): ?array
    {
        $config = $field->getConfig();
        if ($field instanceof ClickableInterface || !$config->getMapped()) {
            return null;
        }
        // Hidden from the editor, so not the author's to write either. A type
        // built on HiddenType (an upload field) is a contributor's business.
        if ($config->getType()->getInnerType()::class === HiddenType::class) {
            return null;
        }

        $schema = $this->contributed($field) ?? $this->shape($field, $locale);
        $schema = $this->constrained($schema, $field);

        return $this->annotated($schema, $field, $locale);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function contributed(FormInterface $field): ?array
    {
        foreach ($this->contributors as $contributor) {
            $schema = $contributor->schemaFor($field);
            if ($schema !== null) {
                return $schema;
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function shape(FormInterface $field, ?string $locale): array
    {
        if (self::is($field, ChoiceType::class)) {
            return $this->choice($field);
        }
        if (self::is($field, CollectionType::class)) {
            return $this->collection($field, $locale);
        }
        if ($field->getConfig()->getCompound() && \count($field) > 0) {
            return $this->object($field, $locale);
        }

        return match (true) {
            self::is($field, CheckboxType::class) => ['type' => 'boolean'],
            self::is($field, IntegerType::class) => ['type' => 'integer'],
            self::is($field, NumberType::class),
            self::is($field, RangeType::class),
            self::is($field, PercentType::class),
            self::is($field, MoneyType::class) => ['type' => 'number'],
            self::is($field, DateTimeType::class) => ['type' => 'string', 'format' => 'date-time'],
            self::is($field, DateType::class) => ['type' => 'string', 'format' => 'date'],
            // A relative link is a fine link in a page.
            self::is($field, UrlType::class) => ['type' => 'string', 'format' => 'uri-reference'],
            self::is($field, EmailType::class) => ['type' => 'string', 'format' => 'email'],
            // Anything else posts a string; the plainest honest answer.
            default => ['type' => 'string'],
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function object(FormInterface $form, ?string $locale): array
    {
        $properties = [];
        foreach ($form as $name => $child) {
            $schema = $this->field($child, $locale);
            if ($schema !== null) {
                $properties[(string) $name] = $schema;
            }
        }

        return [
            'type' => 'object',
            'properties' => $properties === [] ? new \stdClass() : $properties,
            // An undeclared key is dropped on the way in; say so up front.
            'additionalProperties' => false,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function choice(FormInterface $field): array
    {
        $config = $field->getConfig();
        $values = [];
        if ($config->hasAttribute('choice_list')) {
            $list = $config->getAttribute('choice_list');
            if (\is_object($list) && method_exists($list, 'getChoices')) {
                foreach ($list->getChoices() as $value) {
                    if (\is_scalar($value) || $value === null) {
                        $values[] = $value;
                    }
                }
            }
        }

        if ($config->getOption('multiple')) {
            $enum = ['enum' => array_values(array_unique($values, \SORT_REGULAR))];

            return ['type' => 'array', 'items' => $enum, 'uniqueItems' => true];
        }

        // An optional choice also takes its empty value, whatever it is.
        if (!$config->getRequired()) {
            $empty = $field->getData();
            $values[] = \is_scalar($empty) ? $empty : null;
        }

        return ['enum' => array_values(array_unique($values, \SORT_REGULAR))];
    }

    /**
     * @return array<string, mixed>
     */
    private function collection(FormInterface $field, ?string $locale): array
    {
        $config = $field->getConfig();
        $prototype = $config->hasAttribute('prototype') ? $config->getAttribute('prototype') : null;
        if (!$prototype instanceof FormInterface) {
            // A live collection builds no prototype: an entry built alone
            // has the same fields.
            $entryType = $config->getOption('entry_type');
            $prototype = \is_string($entryType) ? $config->getFormFactory()->createNamed(
                '__name__',
                $entryType,
                null,
                (array) $config->getOption('entry_options'),
            ) : null;
        }

        $items = $prototype instanceof FormInterface ? $this->field($prototype, $locale) : null;
        // An entry's label is "__name__label__", which tells a model nothing.
        unset($items['title']);

        $schema = ['type' => 'array', 'items' => $items ?? new \stdClass()];
        if (!$config->getOption('allow_add') && !$config->getOption('allow_delete')) {
            $schema['maxItems'] = \count($field);
        }

        return $schema;
    }

    /**
     * Folds the field's validation constraints into its schema — the part a
     * hand-written description would let drift first.
     *
     * @param array<string, mixed> $schema
     *
     * @return array<string, mixed>
     */
    private function constrained(array $schema, FormInterface $field): array
    {
        $schema = $this->bounded($schema, $field);

        $constraints = $field->getConfig()->getOption('constraints') ?? [];
        foreach (\is_array($constraints) ? $constraints : [$constraints] as $constraint) {
            if ($constraint instanceof Constraint) {
                $schema = $this->constraint($schema, $constraint);
            }
        }

        return $schema;
    }

    /**
     * The `min` / `max` a number input carries in its HTML attributes, which
     * the browser enforces for an editor.
     *
     * @param array<string, mixed> $schema
     *
     * @return array<string, mixed>
     */
    private function bounded(array $schema, FormInterface $field): array
    {
        if (!\in_array($schema['type'] ?? null, ['integer', 'number'], true)) {
            return $schema;
        }

        $attr = $field->getConfig()->getOption('attr');
        foreach (['min' => 'minimum', 'max' => 'maximum'] as $html => $keyword) {
            if (\is_array($attr) && is_numeric($attr[$html] ?? null)) {
                $schema[$keyword] = $attr[$html] + 0;
            }
        }

        return $schema;
    }

    /**
     * @param array<string, mixed> $schema
     *
     * @return array<string, mixed>
     */
    private function constraint(array $schema, Constraint $constraint): array
    {
        $isArray = ($schema['type'] ?? null) === 'array';

        switch (true) {
            case $constraint instanceof Assert\Length:
                if ($constraint->min !== null) {
                    $schema['minLength'] = $constraint->min;
                }
                if ($constraint->max !== null) {
                    $schema['maxLength'] = $constraint->max;
                }
                break;
            case $constraint instanceof Assert\Count:
                if ($constraint->min !== null) {
                    $schema['minItems'] = $constraint->min;
                }
                if ($constraint->max !== null) {
                    $schema['maxItems'] = min($constraint->max, $schema['maxItems'] ?? \PHP_INT_MAX);
                }
                break;
            case $constraint instanceof Assert\NotBlank:
                $schema[$isArray ? 'minItems' : 'minLength'] = max(1, $schema[$isArray ? 'minItems' : 'minLength'] ?? 0);
                break;
            case $constraint instanceof Assert\Range:
                if (is_numeric($constraint->min)) {
                    $schema['minimum'] = $constraint->min + 0;
                }
                if (is_numeric($constraint->max)) {
                    $schema['maximum'] = $constraint->max + 0;
                }
                break;
            case $constraint instanceof Assert\GreaterThanOrEqual && is_numeric($constraint->value):
                $schema['minimum'] = $constraint->value + 0;
                break;
            case $constraint instanceof Assert\GreaterThan && is_numeric($constraint->value):
                $schema['exclusiveMinimum'] = $constraint->value + 0;
                break;
            case $constraint instanceof Assert\LessThanOrEqual && is_numeric($constraint->value):
                $schema['maximum'] = $constraint->value + 0;
                break;
            case $constraint instanceof Assert\LessThan && is_numeric($constraint->value):
                $schema['exclusiveMaximum'] = $constraint->value + 0;
                break;
            case $constraint instanceof Assert\Url:
                $schema['format'] = 'uri';
                break;
            case $constraint instanceof Assert\Email:
                $schema['format'] = 'email';
                break;
            case $constraint instanceof Assert\Choice && \is_array($constraint->choices):
                $schema = self::withChoices($schema, array_values($constraint->choices));
                break;
        }

        return $schema;
    }

    /**
     * A choice constraint narrows what the field offers, never widens it: the
     * kit's constraint is the union of coded and offered values on purpose.
     *
     * @param array<string, mixed> $schema
     * @param list<mixed>          $choices
     *
     * @return array<string, mixed>
     */
    private static function withChoices(array $schema, array $choices): array
    {
        $target = ($schema['type'] ?? null) === 'array' ? ($schema['items'] ?? []) : $schema;
        if (!\is_array($target)) {
            return $schema;
        }

        $target['enum'] = isset($target['enum']) && \is_array($target['enum'])
            ? array_values(array_filter($target['enum'], static fn (mixed $v): bool => \in_array($v, $choices, false)))
            : $choices;

        if (($schema['type'] ?? null) === 'array') {
            $schema['items'] = $target;

            return $schema;
        }

        return $target;
    }

    /**
     * @param array<string, mixed> $schema
     *
     * @return array<string, mixed>
     */
    private function annotated(array $schema, FormInterface $field, ?string $locale): array
    {
        $config = $field->getConfig();
        $domain = $this->domainOf($field);

        $label = $config->getOption('label');
        if (\is_string($label) && $label !== '' && !isset($schema['title'])) {
            $title = $this->text($label, $domain, $locale);
            if ($title !== null) {
                $schema['title'] = $title;
            }
        }

        $help = $config->getOption('help');
        if (\is_string($help) && $help !== '') {
            $description = $this->text($help, $domain, $locale);
            if ($description !== null) {
                $schema['description'] = isset($schema['description'])
                    ? $schema['description'] . ' ' . $description
                    : $description;
            }
        }

        // A null default would contradict the type it sits next to.
        $default = $field->getData();
        if (!\array_key_exists('default', $schema) && \is_scalar($default)
            && ($schema['type'] ?? null) !== 'array' && ($schema['type'] ?? null) !== 'object') {
            $schema['default'] = $default;
        }

        return $schema;
    }

    /**
     * A translated label, or null when only the raw key came back: a
     * `cb_kit.block.title.field.text` teaches a model less than the name.
     */
    private function text(string $key, string|false|null $domain, ?string $locale): ?string
    {
        if ($domain === false) {
            return $key;
        }

        $text = $this->translator?->trans($key, [], $domain, $locale) ?? $key;
        if ($text === $key && preg_match('/^[\w-]+(\.[\w-]+)+$/', $key) === 1) {
            return null;
        }

        return $text;
    }

    /**
     * The translation domain in effect: the field's own, else the nearest
     * ancestor's, as the form view would resolve it.
     */
    private function domainOf(FormInterface $field): string|false|null
    {
        for ($form = $field; $form !== null; $form = $form->getParent()) {
            $domain = $form->getConfig()->getOption('translation_domain');
            if ($domain !== null) {
                return \is_string($domain) || $domain === false ? $domain : null;
            }
        }

        return null;
    }

    /**
     * Whether the field's type is $class or built on it.
     *
     * @param class-string $class
     */
    public static function is(FormInterface $field, string $class): bool
    {
        for ($type = $field->getConfig()->getType(); $type !== null; $type = $type->getParent()) {
            if ($type->getInnerType() instanceof $class) {
                return true;
            }
        }

        return false;
    }
}
