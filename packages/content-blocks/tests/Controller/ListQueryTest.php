<?php

declare(strict_types=1);

namespace ContentBlocks\Tests\Controller;

use ContentBlocks\Controller\ListQuery;
use PHPUnit\Framework\TestCase;

final class ListQueryTest extends TestCase
{
    // `?page=99999999999999999999` made the offset a float: a TypeError.
    public function testThePageIsClampedToAUsableRange(): void
    {
        $this->assertSame(0, ListQuery::page(null));
        $this->assertSame(0, ListQuery::page('-3'));
        $this->assertSame(0, ListQuery::page('abc'));
        $this->assertSame(4, ListQuery::page('4'));
        $this->assertSame(ListQuery::MAX_PAGE, ListQuery::page('99999999999999999999'));
        $this->assertIsInt(ListQuery::page('99999999999999999999') * 11);
    }

    public function testWildcardsInTheSearchAreText(): void
    {
        $this->assertSame('%hero%', ListQuery::contains('hero'));
        $this->assertSame('%100\\%\\_off\\\\%', ListQuery::contains('100%_off\\'));
    }
}
