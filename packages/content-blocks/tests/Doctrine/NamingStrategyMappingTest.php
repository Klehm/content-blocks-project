<?php

declare(strict_types=1);

namespace ContentBlocks\Tests\Doctrine;

use ContentBlocks\Entity\Block;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\Configuration;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Mapping\DefaultNamingStrategy;
use Doctrine\ORM\Mapping\Driver\AttributeDriver;
use Doctrine\ORM\Mapping\NamingStrategy;
use Doctrine\ORM\Mapping\UnderscoreNamingStrategy;
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\TestCase;

/**
 * The package's schema must not depend on the host's naming strategy: RC6's
 * `cb_action_log` index broke on a host using Doctrine's default, and
 * `cb_section.content_area_id` came out as `contentArea_id` there.
 */
final class NamingStrategyMappingTest extends TestCase
{
    public function testTheSchemaIsTheSameWhateverTheHostNamingStrategy(): void
    {
        $this->assertSame(
            self::createSql(new UnderscoreNamingStrategy(\CASE_LOWER, true)),
            self::createSql(new DefaultNamingStrategy()),
        );
    }

    public function testEveryJoinColumnIsSnakeCase(): void
    {
        $sql = implode("\n", self::createSql(new DefaultNamingStrategy()));

        $this->assertStringNotContainsString('contentArea_id', $sql);
        $this->assertMatchesRegularExpression(
            '/CREATE TABLE cb_section \(.*content_area_id INT NOT NULL/s',
            $sql,
        );
        $this->assertMatchesRegularExpression(
            '/CREATE TABLE cb_action_log \(.*content_area_id INT NOT NULL/s',
            $sql,
        );
    }

    /** @return list<string> */
    private static function createSql(NamingStrategy $strategy): array
    {
        $dir = \dirname((string) (new \ReflectionClass(Block::class))->getFileName());

        $config = new Configuration();
        $config->setMetadataDriverImpl(new AttributeDriver([$dir]));
        $config->setNamingStrategy($strategy);
        $config->setProxyDir(sys_get_temp_dir());
        $config->setProxyNamespace('ContentBlocksNamingStrategyProxies');
        if (\PHP_VERSION_ID >= 80400 && method_exists($config, 'enableNativeLazyObjects')) {
            $config->enableNativeLazyObjects(true);
        }

        // Never connects: a pinned server version gives the platform offline.
        $connection = DriverManager::getConnection(
            ['driver' => 'pdo_mysql', 'serverVersion' => '8.0.0'],
            $config,
        );
        $em = new EntityManager($connection, $config);

        return array_values((new SchemaTool($em))->getCreateSchemaSql(
            $em->getMetadataFactory()->getAllMetadata(),
        ));
    }
}
