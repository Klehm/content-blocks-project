<?php

declare(strict_types=1);

namespace ContentBlocks\Sylius\Locale;

use Doctrine\Persistence\ObjectRepository;
use Sylius\Component\Channel\Model\ChannelInterface as BaseChannelInterface;
use Sylius\Component\Core\Model\ChannelInterface;

/**
 * The locales the shop serves: those of its enabled channels, the default
 * locale first. Not the current channel's, which the admin does not have.
 */
final class ShopLocales
{
    /** @param ObjectRepository<BaseChannelInterface> $channels */
    public function __construct(
        private readonly ObjectRepository $channels,
        private readonly string $defaultLocale,
    ) {
    }

    /** @return list<string> */
    public function codes(): array
    {
        $codes = [$this->defaultLocale];
        foreach ($this->channels->findBy(['enabled' => true], ['code' => 'ASC']) as $channel) {
            if (!$channel instanceof ChannelInterface) {
                continue;
            }
            foreach ($channel->getLocales() as $locale) {
                $codes[] = (string) $locale->getCode();
            }
        }

        return array_values(array_unique($codes));
    }
}
