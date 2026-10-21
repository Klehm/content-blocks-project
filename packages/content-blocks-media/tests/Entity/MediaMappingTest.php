<?php

declare(strict_types=1);

namespace ContentBlocks\Media\Tests\Entity;

use ContentBlocks\Media\Entity\Media;
use ContentBlocks\Media\Storage\MediaRecord;
use ContentBlocks\Media\Storage\MediaRows;
use ContentBlocks\Media\Tests\Fixtures\InMemoryConnection;
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
 * MediaRows writes `cb_media` by column name, past the ORM: the mapping must
 * spell those names whatever naming strategy the host configured.
 */
final class MediaMappingTest extends TestCase
{
    public function testTheSchemaIsTheSameWhateverTheHostNamingStrategy(): void
    {
        $this->assertSame(
            self::createSql(new UnderscoreNamingStrategy(\CASE_LOWER, true)),
            self::createSql(new DefaultNamingStrategy()),
        );
    }

    public function testEveryColumnMediaRowsWritesExists(): void
    {
        $db = new InMemoryConnection($this);
        (new MediaRows($db->connection()))->add(MediaRecord::describe('/u/a.png', '', null, 1));
        $table = self::table('cb_media', self::createSql(new UnderscoreNamingStrategy()));

        foreach (array_keys($db->rows['/u/a.png']) as $column) {
            $this->assertMatchesRegularExpression('/[(,]\s*' . $column . ' /', $table, $column);
        }
        $this->assertStringContainsString('folder_id INT', $table);
    }

    public function testAPathIsInTheLibraryOnce(): void
    {
        $sql = implode("\n", self::createSql(new DefaultNamingStrategy()));

        $this->assertStringContainsString('UNIQUE INDEX cb_media_path (path)', $sql);
        $this->assertMatchesRegularExpression('/FOREIGN KEY \(folder_id\) REFERENCES cb_media_folder \(id\) ON DELETE SET NULL/', $sql);
        $this->assertMatchesRegularExpression('/FOREIGN KEY \(parent_id\) REFERENCES cb_media_folder \(id\) ON DELETE CASCADE/', $sql);
    }

    /** @param list<string> $sql */
    private static function table(string $name, array $sql): string
    {
        foreach ($sql as $statement) {
            if (str_starts_with($statement, 'CREATE TABLE ' . $name . ' (')) {
                return $statement;
            }
        }
        self::fail('No CREATE TABLE ' . $name);
    }

    /** @return list<string> */
    private static function createSql(NamingStrategy $strategy): array
    {
        $dir = \dirname((string) (new \ReflectionClass(Media::class))->getFileName());

        $config = new Configuration();
        $config->setMetadataDriverImpl(new AttributeDriver([$dir]));
        $config->setNamingStrategy($strategy);
        $config->setProxyDir(sys_get_temp_dir());
        $config->setProxyNamespace('ContentBlocksMediaMappingProxies');
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
            self::markTestSkipped('This Doctrine version needs a database here.');
        }

        return array_values($sql);
    }
}
