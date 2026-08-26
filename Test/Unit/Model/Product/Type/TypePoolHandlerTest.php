<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Test\Unit\Model\Product\Type;

use PHPUnit\Framework\TestCase;
use Qliro\QliroOne\Api\Data\QliroOrderItemInterface;
use Qliro\QliroOne\Api\Product\TypeHandlerInterface;
use Qliro\QliroOne\Api\Product\TypeSourceProviderInterface;
use Qliro\QliroOne\Model\Product\Type\TypePoolHandler;
use Qliro\QliroOne\Model\Product\Type\TypeResolver;

/**
 * @see \Qliro\QliroOne\Model\Product\Type\TypePoolHandler
 */
class TypePoolHandlerTest extends TestCase
{
    /**
     * TypeResolver returns null for an item it cannot resolve, and the pool used to be indexed
     * with that null. PHP 8.5 deprecates a null array offset, which developer mode raises as an
     * exception, so a single unresolvable line took the whole checkout down with "Couldn't fetch
     * the QliroOne order." Reported on a Magento 2.4.9 store running PHP 8.5 (PLIN-382).
     */
    public function testAnUnresolvedTypeYieldsNoHandlerInsteadOfADeprecation(): void
    {
        $typeResolver = $this->createMock(TypeResolver::class);
        $typeResolver->method('resolve')->willReturn(null);

        $handler = new TypePoolHandler($typeResolver, ['simple' => $this->createMock(TypeHandlerInterface::class)]);

        self::assertNull($handler->resolveQuoteItem(
            $this->createMock(QliroOrderItemInterface::class),
            $this->createMock(TypeSourceProviderInterface::class)
        ));
    }

    /**
     * A type the pool does not carry is not an error either, it simply has no handler.
     */
    public function testAnUnknownTypeYieldsNoHandler(): void
    {
        $typeResolver = $this->createMock(TypeResolver::class);
        $typeResolver->method('resolve')->willReturn('giftcard');

        $handler = new TypePoolHandler($typeResolver, ['simple' => $this->createMock(TypeHandlerInterface::class)]);

        self::assertNull($handler->resolveQuoteItem(
            $this->createMock(QliroOrderItemInterface::class),
            $this->createMock(TypeSourceProviderInterface::class)
        ));
    }
}
