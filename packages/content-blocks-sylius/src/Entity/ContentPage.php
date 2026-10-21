<?php

declare(strict_types=1);

namespace ContentBlocks\Sylius\Entity;

use ContentBlocks\Entity\ContentArea;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Sylius\Component\Channel\Model\ChannelInterface;
use Sylius\Resource\Model\CodeAwareInterface;
use Sylius\Resource\Model\ResourceInterface;
use Sylius\Resource\Model\TimestampableInterface;
use Sylius\Resource\Model\ToggleableInterface;
use Sylius\Resource\Model\ToggleableTrait;
use Sylius\Resource\Model\TranslatableInterface;
use Sylius\Resource\Model\TranslatableTrait;
use Sylius\Resource\Model\TranslationInterface;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A shop page whose body is a builder area: title, slug and metadata are
 * translated per Sylius locale, the page is shown on the channels it lists.
 */
#[ORM\Entity]
#[ORM\Table(name: 'cb_sylius_page')]
#[ORM\HasLifecycleCallbacks]
#[UniqueEntity(fields: ['code'], groups: ['Default', 'sylius'])]
class ContentPage implements
    ResourceInterface,
    CodeAwareInterface,
    TranslatableInterface,
    ToggleableInterface,
    TimestampableInterface
{
    use ToggleableTrait;
    use TranslatableTrait {
        __construct as private initializeTranslations;
        getTranslation as private doGetTranslation;
    }

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 191, unique: true)]
    #[Assert\NotBlank(groups: ['Default', 'sylius'])]
    #[Assert\Regex(pattern: '/^[\w-]+$/', groups: ['Default', 'sylius'])]
    private ?string $code = null;

    /** @var bool */
    #[ORM\Column(type: 'boolean')]
    protected $enabled = true;

    /** @var Collection<int, ChannelInterface> */
    #[ORM\ManyToMany(targetEntity: ChannelInterface::class)]
    #[ORM\JoinTable(name: 'cb_sylius_page_channels')]
    #[ORM\JoinColumn(name: 'page_id', onDelete: 'CASCADE')]
    #[ORM\InverseJoinColumn(name: 'channel_id', onDelete: 'CASCADE')]
    private Collection $channels;

    #[ORM\OneToOne(targetEntity: ContentArea::class, cascade: ['persist', 'remove'])]
    #[ORM\JoinColumn(name: 'content_area_id', nullable: true, onDelete: 'SET NULL')]
    private ?ContentArea $contentArea = null;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;

    public function __construct()
    {
        $this->initializeTranslations();
        $this->channels = new ArrayCollection();
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCode(): ?string
    {
        return $this->code;
    }

    public function setCode(?string $code): void
    {
        $this->code = $code;
    }

    /** @return Collection<int, ChannelInterface> */
    public function getChannels(): Collection
    {
        return $this->channels;
    }

    public function hasChannel(ChannelInterface $channel): bool
    {
        return $this->channels->contains($channel);
    }

    public function addChannel(ChannelInterface $channel): void
    {
        if (!$this->hasChannel($channel)) {
            $this->channels->add($channel);
        }
    }

    public function removeChannel(ChannelInterface $channel): void
    {
        $this->channels->removeElement($channel);
    }

    public function getContentArea(): ?ContentArea
    {
        return $this->contentArea;
    }

    public function setContentArea(?ContentArea $contentArea): void
    {
        $this->contentArea = $contentArea;
    }

    public function getTitle(): ?string
    {
        return $this->getTranslation()->getTitle();
    }

    /** The title in the current locale, else in any: for a label. */
    public function getAnyTitle(): string
    {
        if ($this->currentLocale !== null) {
            return (string) $this->getTitle();
        }
        $first = $this->translations->first();

        return $first instanceof ContentPageTranslation ? (string) $first->getTitle() : (string) $this->code;
    }

    public function getSlug(): ?string
    {
        return $this->getTranslation()->getSlug();
    }

    /** The slug in that locale only: untranslated, a page has no address. */
    public function getSlugIn(string $locale): ?string
    {
        $translation = $this->translations->get($locale);

        return $translation instanceof ContentPageTranslation ? $translation->getSlug() : null;
    }

    /** @return ContentPageTranslation */
    public function getTranslation(?string $locale = null): TranslationInterface
    {
        $translation = $this->doGetTranslation($locale);
        \assert($translation instanceof ContentPageTranslation);

        return $translation;
    }

    public function getCreatedAt(): ?\DateTimeInterface
    {
        return $this->createdAt;
    }

    /** A page always has a creation date: null leaves it as it is. */
    public function setCreatedAt(?\DateTimeInterface $createdAt): void
    {
        if ($createdAt !== null) {
            $this->createdAt = \DateTimeImmutable::createFromInterface($createdAt);
        }
    }

    public function getUpdatedAt(): ?\DateTimeInterface
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(?\DateTimeInterface $updatedAt): void
    {
        $this->updatedAt = $updatedAt === null ? null : \DateTimeImmutable::createFromInterface($updatedAt);
    }

    #[ORM\PreUpdate]
    public function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    protected function createTranslation(): ContentPageTranslation
    {
        return new ContentPageTranslation();
    }
}
