<?php

declare(strict_types=1);

namespace ContentBlocks\Sylius\Tests\Integration;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * A booted Sylius test application, one browser per test, every write rolled
 * back at the end so tests share the schema and nothing else.
 */
abstract class IntegrationTestCase extends WebTestCase
{
    protected KernelBrowser $client;
    protected EntityManagerInterface $em;
    protected ShopFixtures $fixtures;

    protected function setUp(): void
    {
        $this->client = static::createClient([], ['HTTP_HOST' => 'localhost']);
        $this->client->disableReboot();
        $em = static::getContainer()->get('doctrine.orm.entity_manager');
        \assert($em instanceof EntityManagerInterface);
        $this->em = $em;
        $this->em->getConnection()->beginTransaction();
        $this->fixtures = new ShopFixtures(static::getContainer(), $this->em);
    }

    protected function tearDown(): void
    {
        $connection = $this->em->getConnection();
        while ($connection->isTransactionActive()) {
            $connection->rollBack();
        }
        parent::tearDown();
    }

    /** The entity manager forgets everything, as the next request would. */
    protected function clear(): void
    {
        $this->em->flush();
        $this->em->clear();
    }
}
