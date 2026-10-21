<?php

declare(strict_types=1);

namespace ContentBlocks\Media\Tests\Fixtures;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;

/**
 * A DBAL connection double holding `cb_media` rows in an array: enough for
 * MediaRows, which only reads by path, inserts, deletes and lists paths.
 */
final class InMemoryConnection
{
    /** @var array<string, array<string, mixed>> path => row */
    public array $rows = [];

    public ?\Throwable $failWith = null;

    public function __construct(private readonly TestCase $test)
    {
    }

    public function connection(): Connection
    {
        $mock = (new \PHPUnit\Framework\MockObject\MockBuilder($this->test, Connection::class))
            ->disableOriginalConstructor()
            ->onlyMethods(['fetchOne', 'insert', 'delete', 'iterateColumn'])
            ->getMock();

        $mock->method('fetchOne')->willReturnCallback(function (string $sql, array $params) {
            $this->maybeFail();

            return isset($this->rows[$params[0]]) ? 1 : false;
        });
        $mock->method('insert')->willReturnCallback(function (string $table, array $row) {
            $this->maybeFail();
            $this->rows[$row['path']] = $row;

            return 1;
        });
        $mock->method('delete')->willReturnCallback(function (string $table, array $criteria) {
            $this->maybeFail();
            unset($this->rows[$criteria['path']]);

            return 1;
        });
        $mock->method('iterateColumn')->willReturnCallback(
            fn () => (fn () => yield from array_keys($this->rows))(),
        );

        return $mock;
    }

    private function maybeFail(): void
    {
        if ($this->failWith !== null) {
            throw $this->failWith;
        }
    }
}
