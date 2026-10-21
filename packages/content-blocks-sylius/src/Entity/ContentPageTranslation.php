<?php

declare(strict_types=1);

namespace ContentBlocks\Sylius\Entity;

use Doctrine\ORM\Mapping as ORM;
use Sylius\Resource\Model\AbstractTranslation;
use Sylius\Resource\Model\ResourceInterface;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A {@see ContentPage}'s title, slug and metadata in one locale. `locale` and
 * `translatable` are mapped by the resource bundle's translatable listener.
 */
#[ORM\Entity]
#[ORM\Table(name: 'cb_sylius_page_translation')]
#[ORM\UniqueConstraint(name: 'cb_sylius_page_translation_slug', columns: ['locale', 'slug'])]
#[UniqueEntity(fields: ['locale', 'slug'], errorPath: 'slug', groups: ['Default', 'sylius'])]
class ContentPageTranslation extends AbstractTranslation implements ResourceInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank(groups: ['Default', 'sylius'])]
    #[Assert\Length(max: 255, groups: ['Default', 'sylius'])]
    private string $title = '';

    #[ORM\Column(length: 191)]
    #[Assert\NotBlank(groups: ['Default', 'sylius'])]
    #[Assert\Regex(pattern: '#^[a-z0-9]+(?:[-/][a-z0-9]+)*$#', groups: ['Default', 'sylius'])]
    private string $slug = '';

    #[ORM\Column(name: 'meta_title', length: 255, nullable: true)]
    private ?string $metaTitle = null;

    #[ORM\Column(name: 'meta_description', type: 'text', nullable: true)]
    private ?string $metaDescription = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function setTitle(?string $title): void
    {
        $this->title = (string) $title;
    }

    public function getSlug(): string
    {
        return $this->slug;
    }

    public function setSlug(?string $slug): void
    {
        $this->slug = (string) $slug;
    }

    public function getMetaTitle(): ?string
    {
        return $this->metaTitle;
    }

    public function setMetaTitle(?string $metaTitle): void
    {
        $this->metaTitle = $metaTitle;
    }

    public function getMetaDescription(): ?string
    {
        return $this->metaDescription;
    }

    public function setMetaDescription(?string $metaDescription): void
    {
        $this->metaDescription = $metaDescription;
    }
}
