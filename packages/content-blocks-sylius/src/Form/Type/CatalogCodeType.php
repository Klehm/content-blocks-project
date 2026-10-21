<?php

declare(strict_types=1);

namespace ContentBlocks\Sylius\Form\Type;

use ContentBlocks\Sylius\Form\ChoiceList\CatalogCodeLoader;
use Doctrine\Persistence\ObjectRepository;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\Options;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Products or taxons picked by code, searched through the admin's own
 * autocomplete endpoint: only the codes in play are ever loaded.
 *
 * @see docs/internals/sylius.md#catalog-blocks
 */
final class CatalogCodeType extends AbstractType
{
    private const ALIASES = ['product' => 'sylius_admin_product', 'taxon' => 'sylius_admin_taxon'];

    /**
     * @param ObjectRepository<object> $products
     * @param ObjectRepository<object> $taxons
     */
    public function __construct(
        #[Autowire(service: 'sylius.repository.product')]
        private readonly ObjectRepository $products,
        #[Autowire(service: 'sylius.repository.taxon')]
        private readonly ObjectRepository $taxons,
        private readonly UrlGeneratorInterface $router,
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $loader = $options['choice_loader'];
        \assert($loader instanceof CatalogCodeLoader);

        $builder
            ->addEventListener(FormEvents::PRE_SET_DATA, static function (FormEvent $event) use ($loader): void {
                $loader->offer($event->getData());
            })
            ->addEventListener(FormEvents::PRE_SUBMIT, static function (FormEvent $event) use ($loader): void {
                $loader->offer($event->getData());
            }, 10);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver
            ->setRequired('resource')
            ->setAllowedValues('resource', array_keys(self::ALIASES))
            ->setDefaults([
                'required' => false,
                'autocomplete' => true,
                'choice_translation_domain' => false,
                // Signed into the URL, so the endpoint answers with codes too.
                'extra_options' => ['choice_value' => 'code'],
                'choice_loader' => fn (Options $options): CatalogCodeLoader => new CatalogCodeLoader(
                    $options['resource'] === 'product' ? $this->products : $this->taxons,
                ),
                'autocomplete_url' => fn (Options $options): string => $this->router->generate(
                    'sylius_admin_entity_autocomplete',
                    ['alias' => self::ALIASES[$options['resource']]],
                ),
            ]);
    }

    public function getParent(): string
    {
        return ChoiceType::class;
    }

    public function getBlockPrefix(): string
    {
        return 'content_blocks_sylius_catalog_code';
    }
}
