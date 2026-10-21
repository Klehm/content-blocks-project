<?php

declare(strict_types=1);

namespace ContentBlocks\Sylius\Form\ChoiceList;

use Doctrine\Persistence\ObjectRepository;
use Symfony\Component\Form\ChoiceList\ArrayChoiceList;
use Symfony\Component\Form\ChoiceList\ChoiceListInterface;
use Symfony\Component\Form\ChoiceList\Loader\ChoiceLoaderInterface;

/**
 * The choices of one field: the codes it holds or was sent, those that exist.
 * A catalog of any size is never listed in full.
 */
final class CatalogCodeLoader implements ChoiceLoaderInterface
{
    /** @var array<string, string> code => label */
    private array $known = [];

    /** @param ObjectRepository<object> $repository */
    public function __construct(private readonly ObjectRepository $repository)
    {
    }

    /** Codes the field holds or was sent; unknown ones are dropped. */
    public function offer(mixed $codes): void
    {
        foreach (\is_array($codes) ? $codes : [$codes] as $code) {
            if (!\is_string($code) || $code === '' || isset($this->known[$code])) {
                continue;
            }
            $entity = $this->repository->findOneBy(['code' => $code]);
            if ($entity !== null) {
                $this->known[$code] = self::label($entity, $code);
            }
        }
    }

    public function loadChoiceList(?callable $value = null): ChoiceListInterface
    {
        return new ArrayChoiceList(array_flip($this->known), $value);
    }

    /**
     * @param array<mixed> $values
     *
     * @return list<string>
     */
    public function loadChoicesForValues(array $values, ?callable $value = null): array
    {
        $this->offer($values);

        return array_values(array_filter($values, fn ($code): bool => \is_string($code) && isset($this->known[$code])));
    }

    /**
     * @param array<mixed> $choices
     *
     * @return list<string>
     */
    public function loadValuesForChoices(array $choices, ?callable $value = null): array
    {
        $this->offer($choices);

        return array_values(array_filter($choices, fn ($code): bool => \is_string($code) && isset($this->known[$code])));
    }

    private static function label(object $entity, string $code): string
    {
        try {
            $name = method_exists($entity, 'getName') ? $entity->getName() : null;
        } catch (\RuntimeException) {
            $name = null;
        }

        return \is_string($name) && $name !== '' ? sprintf('%s (%s)', $name, $code) : $code;
    }
}
