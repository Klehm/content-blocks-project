<?php

declare(strict_types=1);

namespace ContentBlocks\Sylius\Tests\Integration;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Sylius\TestApplication\Kernel;

/** A fresh schema once per run; each test then rolls its own writes back. */
final class TestDatabase
{
    public static function create(): void
    {
        $kernel = new Kernel('test', true);
        $kernel->boot();
        $em = $kernel->getContainer()->get('doctrine.orm.entity_manager');
        \assert($em instanceof EntityManagerInterface);

        $connection = $em->getConnection();
        $params = $connection->getParams();
        $name = (string) ($params['dbname'] ?? '');
        unset($params['dbname'], $params['url']);
        $server = \Doctrine\DBAL\DriverManager::getConnection($params);
        $server->executeStatement(sprintf('DROP DATABASE IF EXISTS `%s`', $name));
        $server->executeStatement(sprintf('CREATE DATABASE `%s`', $name));
        $server->close();
        $connection->close();

        (new SchemaTool($em))->createSchema($em->getMetadataFactory()->getAllMetadata());
        $kernel->shutdown();
    }
}
