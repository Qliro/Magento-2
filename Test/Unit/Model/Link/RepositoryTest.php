<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Test\Unit\Model\Link;

use Magento\Framework\Exception\NoSuchEntityException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Qliro\QliroOne\Api\Data\LinkInterface;
use Qliro\QliroOne\Api\Data\LinkInterfaceFactory;
use Qliro\QliroOne\Api\LinkSearchResultInterfaceFactory;
use Qliro\QliroOne\Model\Link;
use Qliro\QliroOne\Model\Link\Repository;
use Qliro\QliroOne\Model\ResourceModel\Link as LinkResourceModel;
use Qliro\QliroOne\Model\ResourceModel\Link\Collection;
use Qliro\QliroOne\Model\ResourceModel\Link\CollectionFactory;

/**
 * @see \Qliro\QliroOne\Model\Link\Repository
 *
 * PLIN-378: qliro_order_id, quote_id and order_id are integer columns, so MySQL casts an empty
 * lookup value to 0 and the query matches links that have no Qliro order yet. A callback that
 * arrives without a body would then load, mutate and save a completely unrelated customer's
 * quote, so an empty value must never reach the database at all.
 */
class RepositoryTest extends TestCase
{
    private LinkResourceModel&MockObject $linkResourceModel;
    private LinkInterfaceFactory&MockObject $linkFactory;
    private CollectionFactory&MockObject $collectionFactory;
    private Repository $repository;

    /**
     * @var array<string, mixed>
     */
    private array $appliedFilters = [];

    protected function setUp(): void
    {
        $this->linkResourceModel = $this->createMock(LinkResourceModel::class);
        $this->linkFactory = $this->createMock(LinkInterfaceFactory::class);
        $this->collectionFactory = $this->createMock(CollectionFactory::class);

        $this->repository = new Repository(
            $this->linkResourceModel,
            $this->linkFactory,
            $this->createMock(LinkSearchResultInterfaceFactory::class),
            $this->collectionFactory
        );
    }

    /**
     * Every lookup method rejects a value that cannot identify a link, and does so before the
     * collection is even created.
     *
     * @dataProvider emptyLookupProvider
     */
    public function testRejectsEmptyLookupValueWithoutQuerying(string $method, mixed $value): void
    {
        $this->collectionFactory->expects(self::never())->method('create');
        $this->linkResourceModel->expects(self::never())->method('load');

        $this->expectException(NoSuchEntityException::class);

        $this->repository->{$method}($value);
    }

    /**
     * The same holds for the lookups that are allowed to return an inactive link: they must not
     * reach the resource model either.
     *
     * @dataProvider emptyLookupProvider
     */
    public function testRejectsEmptyLookupValueWhenInactiveLinksAreAllowed(string $method, mixed $value): void
    {
        $this->collectionFactory->expects(self::never())->method('create');
        $this->linkResourceModel->expects(self::never())->method('load');

        $this->expectException(NoSuchEntityException::class);

        $this->repository->{$method}($value, false);
    }

    /**
     * @return array<string, array{string, mixed}>
     */
    public static function emptyLookupProvider(): array
    {
        $methods = ['get', 'getByQliroOrderId', 'getByQuoteId', 'getByOrderId', 'getByReference'];
        $values = [
            'null' => null,
            'empty string' => '',
            'zero integer' => 0,
            'zero string' => '0',
            'blank string' => '   ',
            'false' => false,
        ];

        $cases = [];

        foreach ($methods as $method) {
            foreach ($values as $label => $value) {
                $cases[$method . ' with ' . $label] = [$method, $value];
            }
        }

        return $cases;
    }

    /**
     * The integer columns read anything non numeric as 0, so a garbled id would match a link
     * that has no Qliro order yet instead of finding nothing. That is the same cross customer
     * quote mutation the empty check above prevents, reached through a different value.
     *
     * @dataProvider nonNumericIntegerLookupProvider
     */
    public function testRejectsNonNumericValueOnTheIntegerColumns(string $method, mixed $value): void
    {
        $this->collectionFactory->expects(self::never())->method('create');
        $this->linkResourceModel->expects(self::never())->method('load');

        $this->expectException(NoSuchEntityException::class);

        $this->repository->{$method}($value);
    }

    /**
     * @return array<string, array{string, mixed}>
     */
    public static function nonNumericIntegerLookupProvider(): array
    {
        $methods = ['get', 'getByQliroOrderId', 'getByQuoteId', 'getByOrderId'];
        $values = [
            'letters' => 'abc',
            'id with a suffix' => '7abc',
            'negative' => '-7',
            'decimal' => '7.5',
        ];

        $cases = [];

        foreach ($methods as $method) {
            foreach ($values as $label => $value) {
                $cases[$method . ' with ' . $label] = [$method, $value];
            }
        }

        return $cases;
    }

    /**
     * A reference is a varchar column, so it takes any non empty string.
     */
    public function testAcceptsANonNumericReference(): void
    {
        $this->givenCollectionReturnsLink(7);

        self::assertSame(7, (int)$this->repository->getByReference('QLO-123-ABC')->getId());
    }

    /**
     * A real Qliro order id is looked up among active links only.
     */
    public function testFindsActiveLinkByQliroOrderId(): void
    {
        $link = $this->givenCollectionReturnsLink(7);

        self::assertSame($link, $this->repository->getByQliroOrderId(5531737));
        self::assertSame(
            [Link::FIELD_QLIRO_ORDER_ID => 5531737, Link::FIELD_IS_ACTIVE => 1],
            $this->appliedFilters
        );
    }

    /**
     * A link id lookup has to filter on the link_id column: the field name used to be null,
     * which made the active branch of the lookup unusable.
     */
    public function testFindsActiveLinkByLinkId(): void
    {
        $link = $this->givenCollectionReturnsLink(7);

        self::assertSame($link, $this->repository->get(7));
        self::assertSame(
            [Link::FIELD_ID => 7, Link::FIELD_IS_ACTIVE => 1],
            $this->appliedFilters
        );
    }

    /**
     * Lookups that accept an inactive link go through the resource model, by the same column.
     */
    public function testLoadsLinkByLinkIdWhenInactiveLinksAreAllowed(): void
    {
        $link = $this->createMock(Link::class);
        $link->method('getId')->willReturn(7);
        $this->linkFactory->method('create')->willReturn($link);

        $this->linkResourceModel->expects(self::once())
            ->method('load')
            ->with($link, 7, Link::FIELD_ID);

        self::assertSame($link, $this->repository->get(7, false));
    }

    /**
     * A value that matches nothing is still a missing link, not an empty link object.
     */
    public function testThrowsWhenNoActiveLinkMatches(): void
    {
        $this->givenCollectionReturnsLink(null);

        $this->expectException(NoSuchEntityException::class);
        $this->expectExceptionMessage('Cannot find a link with qliro_order_id = "5531737"');

        $this->repository->getByQliroOrderId(5531737);
    }

    /**
     * Let the collection report the given link id, recording the filters it was asked for
     *
     * @param int|null $linkId
     * @return LinkInterface&MockObject
     */
    private function givenCollectionReturnsLink(?int $linkId): LinkInterface&MockObject
    {
        $link = $this->createMock(Link::class);
        $link->method('getId')->willReturn($linkId);

        $collection = $this->createMock(Collection::class);
        $collection->method('addFieldToFilter')
            ->willReturnCallback(function ($field, $condition) use ($collection) {
                $this->appliedFilters[$field] = $condition;

                return $collection;
            });
        $collection->method('getFirstItem')->willReturn($link);

        $this->collectionFactory->method('create')->willReturn($collection);

        return $link;
    }
}
