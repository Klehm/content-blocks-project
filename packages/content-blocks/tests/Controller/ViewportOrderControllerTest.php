<?php

declare(strict_types=1);

namespace ContentBlocks\Tests\Controller;

use ContentBlocks\Controller\ViewportOrderController;
use ContentBlocks\Security\ContentBlocksAccessDeniedException;
use ContentBlocks\Security\DenyAllAccessChecker;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Response;

final class ViewportOrderControllerTest extends ControllerTestCase
{
    public function testSectionsGetTheRankOfTheirNewVisualPlace(): void
    {
        $area = $this->makeArea(1);
        $a = $this->makeSection($area, 10, previewPosition: 0);
        $b = $this->makeSection($area, 11, previewPosition: 1);
        $c = $this->makeSection($area, 12, previewPosition: 2);
        $em = $this->makeEm([$area]);

        $response = $this->makeController($em)->reorder(1, $this->makeJsonRequest([
            'viewport' => 'mobile',
            'scope' => 'section',
            'ids' => [12, 10, 11],
        ]));

        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
        $this->assertSame(['_order' => ['mobile' => 1]], $a->getDraftSettings());
        $this->assertSame(['_order' => ['mobile' => 2]], $b->getDraftSettings());
        $this->assertSame(['_order' => ['mobile' => 0]], $c->getDraftSettings());
        $this->assertSame(1, $this->flushCount);

        $payload = json_decode((string) $response->getContent(), true);
        $this->assertSame(['--cb-order-m' => '0'], $payload['orders']['12']);
        $this->assertSame(['--cb-order-m' => '1'], $payload['orders']['10']);
    }

    /** Settings and data keep everything else they held. */
    public function testBlocksAreRankedWithinTheirColumnOnly(): void
    {
        $area = $this->makeArea(1);
        $column = $this->makeColumn($this->makeSection($area, 2), 3);
        $first = $this->makeBlock($column, 20, previewPosition: 0);
        $first->setPublishedData(['title' => 'Live']);
        $second = $this->makeBlock($column, 21, previewPosition: 1);
        $second->setDraftData(['title' => 'Draft', '_order' => ['tablet' => 4]]);
        $em = $this->makeEm([$area, $column]);

        $this->makeController($em)->reorder(1, $this->makeJsonRequest([
            'viewport' => 'tablet',
            'scope' => 'block',
            'columnId' => 3,
            'ids' => [21, 20],
        ]));

        $this->assertSame(['title' => 'Live', '_order' => ['tablet' => 1]], $first->getDraftData());
        $this->assertSame(['title' => 'Live'], $first->getPublishedData());
        $this->assertSame(['title' => 'Draft', '_order' => ['tablet' => 0]], $second->getDraftData());
    }

    public function testABlockFromAnotherColumnIsRefused(): void
    {
        $area = $this->makeArea(1);
        $section = $this->makeSection($area, 2);
        $column = $this->makeColumn($section, 3);
        $other = $this->makeColumn($section, 4);
        $mine = $this->makeBlock($column, 20);
        $this->makeBlock($other, 30);
        $em = $this->makeEm([$area, $column, $other]);

        $response = $this->makeController($em)->reorder(1, $this->makeJsonRequest([
            'viewport' => 'mobile',
            'scope' => 'block',
            'columnId' => 3,
            'ids' => [30, 20],
        ]));

        $this->assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
        $this->assertSame('not_siblings', json_decode((string) $response->getContent(), true)['error']);
        $this->assertNull($mine->getDraftData());
        $this->assertSame(0, $this->flushCount);
    }

    public function testAColumnOfAnotherAreaIsRefused(): void
    {
        $area = $this->makeArea(1);
        $foreign = $this->makeColumn($this->makeSection($this->makeArea(2), 5), 6);
        $em = $this->makeEm([$area, $foreign]);

        $response = $this->makeController($em)->reorder(1, $this->makeJsonRequest([
            'viewport' => 'mobile',
            'scope' => 'block',
            'columnId' => 6,
            'ids' => [],
        ]));

        $this->assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function malformedPayloads(): iterable
    {
        yield 'desktop is the DOM order' => [['viewport' => 'desktop', 'scope' => 'section', 'ids' => []]];
        yield 'unknown scope' => [['viewport' => 'mobile', 'scope' => 'column', 'ids' => []]];
        yield 'ids not integers' => [['viewport' => 'mobile', 'scope' => 'section', 'ids' => ['10']]];
        yield 'duplicate ids' => [['viewport' => 'mobile', 'scope' => 'section', 'ids' => [10, 10]]];
    }

    /** @param array<string, mixed> $payload */
    #[\PHPUnit\Framework\Attributes\DataProvider('malformedPayloads')]
    public function testAMalformedPayloadIsRefused(array $payload): void
    {
        $area = $this->makeArea(1);
        $this->makeSection($area, 10);
        $em = $this->makeEm([$area]);

        $response = $this->makeController($em)->reorder(1, $this->makeJsonRequest($payload));

        $this->assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
    }

    public function testSiblingsLeftOutLoseTheirRank(): void
    {
        $area = $this->makeArea(1);
        $kept = $this->makeSection($area, 10);
        $dropped = $this->makeSection($area, 11, previewPosition: 1);
        $dropped->setDraftSettings(['classes' => 'x', '_order' => ['mobile' => 0, 'tablet' => 1]]);
        $em = $this->makeEm([$area]);

        $this->makeController($em)->reorder(1, $this->makeJsonRequest([
            'viewport' => 'mobile',
            'scope' => 'section',
            'ids' => [10],
        ]));

        $this->assertSame(['_order' => ['mobile' => 0]], $kept->getDraftSettings());
        $this->assertSame(['classes' => 'x', '_order' => ['tablet' => 1]], $dropped->getDraftSettings());
    }

    public function testResetClearsOneViewportAcrossTheArea(): void
    {
        $area = $this->makeArea(1);
        $section = $this->makeSection($area, 10);
        $section->setDraftSettings(['_order' => ['mobile' => 0, 'tablet' => 0]]);
        $block = $this->makeBlock($this->makeColumn($section, 3), 20);
        $block->setDraftData(['title' => 'x', '_order' => ['mobile' => 3]]);
        $untouched = $this->makeBlock($this->makeColumn($section, 4), 21);
        $untouched->setPublishedData(['title' => 'y']);
        $em = $this->makeEm([$area]);

        $response = $this->makeController($em)->reset(1, $this->makeJsonRequest(['viewport' => 'mobile']));

        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
        $this->assertSame(['_order' => ['tablet' => 0]], $section->getDraftSettings());
        $this->assertSame(['title' => 'x'], $block->getDraftData());
        // Nothing to clear: the published-only block stays clean.
        $this->assertNull($untouched->getDraftData());
    }

    public function testAnInvalidCsrfTokenWritesNothing(): void
    {
        $area = $this->makeArea(1);
        $section = $this->makeSection($area, 10);
        $em = $this->makeEm([$area]);

        $response = $this->makeController($em, csrfValid: false)->reorder(1, $this->makeJsonRequest([
            'viewport' => 'mobile',
            'scope' => 'section',
            'ids' => [10],
        ]));

        $this->assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());
        $this->assertNull($section->getDraftSettings());
    }

    public function testAnAreaTheEditorCannotEditIsDenied(): void
    {
        $area = $this->makeArea(1);
        $em = $this->makeEm([$area]);

        $this->expectException(ContentBlocksAccessDeniedException::class);
        $this->makeController($em, deny: true)->reset(1, $this->makeJsonRequest(['viewport' => 'mobile']));
    }

    private function makeController(
        EntityManagerInterface $em,
        bool $csrfValid = true,
        bool $deny = false,
    ): ViewportOrderController {
        return new ViewportOrderController(
            $em,
            $deny ? new DenyAllAccessChecker() : $this->makeAccessChecker(),
            $this->makeCsrfManager($csrfValid),
            $this->makeJournal($em),
        );
    }
}
