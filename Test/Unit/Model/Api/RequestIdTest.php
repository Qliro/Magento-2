<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Test\Unit\Model\Api;

use Magento\Framework\DataObject\IdentityService;
use PHPUnit\Framework\TestCase;
use Qliro\QliroOne\Model\Api\RequestId;

/**
 * Qliro books a repeated `RequestId` once, which is the only thing standing between a settlement
 * that timed out on an answer Qliro had already given and a merchant sending it again.
 *
 * @see \Qliro\QliroOne\Model\Api\RequestId
 */
class RequestIdTest extends TestCase
{
    private RequestId $requestId;

    protected function setUp(): void
    {
        $this->requestId = new RequestId(new IdentityService());
    }

    /**
     * The same request asked for twice is the same id, which is what makes the retry safe.
     */
    public function testTheSameRequestRepeatsItsId(): void
    {
        self::assertSame(
            $this->requestId->forRequest(['add-items-to-invoice', '000000123', 0.0, '5566', 99.5]),
            $this->requestId->forRequest(['add-items-to-invoice', '000000123', 0.0, '5566', 99.5])
        );
    }

    /**
     * A second, deliberate refund of the same amount differs in what the order had already given
     * back, so Qliro books it rather than recognising it as the first one.
     */
    public function testARequestThatDiffersAnywhereIsANewId(): void
    {
        $first = $this->requestId->forRequest(['add-items-to-invoice', '000000123', 0.0, '5566', 99.5]);

        self::assertNotSame($first, $this->requestId->forRequest(['add-items-to-invoice', '000000123', 99.5, '5566', 99.5]));
        self::assertNotSame($first, $this->requestId->forRequest(['add-items-to-invoice', '000000124', 0.0, '5566', 99.5]));
        self::assertNotSame($first, $this->requestId->forRequest(['mark-items-as-shipped', '000000123', 0.0, '5566', 99.5]));
    }

    /**
     * An amount reaches this through a float, so the id has to read the same whichever of the two
     * a caller hands over.
     */
    public function testAnAmountReadsTheSameAsAFloatAndAsAnInteger(): void
    {
        self::assertSame(
            $this->requestId->forRequest(['x', 100.0]),
            $this->requestId->forRequest(['x', 100])
        );
    }

    /**
     * The id goes on the wire as Qliro's own field, so it has to look like the one the module
     * has always sent: a uuid.
     */
    public function testTheIdIsAUuid(): void
    {
        self::assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/',
            $this->requestId->forRequest(['add-items-to-invoice', '000000123'])
        );
    }
}
