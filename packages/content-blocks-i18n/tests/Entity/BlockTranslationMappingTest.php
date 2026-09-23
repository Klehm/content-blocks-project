<?php

declare(strict_types=1);

namespace ContentBlocks\I18n\Tests\Entity;

use ContentBlocks\Entity\Block;
use ContentBlocks\I18n\Entity\BlockTranslation;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\ConnectionException;
use Doctrine\ORM\Configuration;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Mapping\DefaultNamingStrategy;
use Doctrine\ORM\Mapping\Driver\AttributeDriver;
use Doctrine\ORM\Mapping\NamingStrategy;
use Doctrine\ORM\Mapping\UnderscoreNamingStrategy;
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\TestCase;

/**
 * `cb_block_translation` must come out identical whatever naming strategy the
 * host configured — its unique constraint spells `block_id`.
 */
final class BlockTranslationMappingTest extends TestCase
{
    public function testTheSchemaIsTheSameWhateverTheHostNamingStrategy(): void
    {
        $default = self::createSql(new DefaultNamingStrategy());

        $this->assertSame(
            self::createSql(new UnderscoreNamingStrategy(\CASE_LOWER, true)),
            $default,
        );
        $this->assertMatchesRegularExpression(
            '/CREATE TABLE cb_block_translation \(.*block_id INT NOT NULL/s',
            implode("\n", $default),
        );
        // Its sibling spells `column_id` in its unique constraint the same way.
        $this->assertMatchesRegularExpression(
            '/CREATE TABLE cb_column_translation \(.*column_id INT NOT NULL.*UNIQUE INDEX cb_column_translation_unique \(column_id, locale\)/s',
            implode("\n", $default),
        );
    }

    /** @return list<string> */
    private static function createSql(NamingStrategy $strategy): array
    {
        $dirs = array_map(
            static fn (string $class): string => \dirname(
                (string) (new \ReflectionClass($class))->getFileName(),
            ),
            [Block::class, BlockTranslation::class],
        );

        $config = new Configuration();
        $config->setMetadataDriverImpl(new AttributeDriver($dirs));
        $config->setNamingStrategy($strategy);
        $config->setProxyDir(sys_get_temp_dir());
        $config->setProxyNamespace('ContentBlocksI18nNamingStrategyProxies');
        if (\PHP_VERSION_ID >= 80400 && method_exists($config, 'enableNativeLazyObjects')) {
            $config->enableNativeLazyObjects(true);
        }

        // Never connects: a pinned server version gives the platform offline.
        $connection = DriverManager::getConnection(
            ['driver' => 'pdo_mysql', 'serverVersion' => '8.0.0'],
            $config,
        );
        $em = new EntityManager($connection, $config);

        try {
            $sql = (new SchemaTool($em))->getCreateSchemaSql(
                $em->getMetadataFactory()->getAllMetadata(),
            );
        } catch (ConnectionException) {
            // ORM 2.15 on DBAL 3.6 asks the server for the schema name.
            self::markTestSkipped('This Doctrine version needs a database here.');
        }

        return array_values($sql);
    }
}
