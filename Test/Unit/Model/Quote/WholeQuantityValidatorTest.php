<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Test\Unit\Model\Quote;

use Magento\Framework\Exception\LocalizedException;
use Qliro\QliroOne\Model\Exception\UnsupportedQuoteException;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Item as QuoteItem;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Qliro\QliroOne\Model\QliroOrder\LineQuantity;
use Qliro\QliroOne\Model\Quote\WholeQuantityValidator;

/**
 * A cart Qliro cannot carry the quantity of is refused at the cart, not halfway through a
 * checkout that then fails on a totals mismatch.
 *
 * @see \Qliro\QliroOne\Model\Quote\WholeQuantityValidator
 */
class WholeQuantityValidatorTest extends TestCase
{
    private WholeQuantityValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new WholeQuantityValidator(new LineQuantity());
    }

    public function testACartOfWholeQuantitiesPasses(): void
    {
        $this->expectNotToPerformAssertions();

        $this->validator->validateWholeQuantities($this->quote([
            'SKU-1' => 1.0,
            'SKU-2' => 12.0,
        ]));
    }

    /**
     * Half a metre of cable cannot be sent, and the buyer hears it instead of the checkout.
     */
    public function testACartHoldingAPartOfAnItemIsRefused(): void
    {
        $this->expectException(UnsupportedQuoteException::class);

        $this->validator->validateWholeQuantities($this->quote(['CABLE-5MM' => 0.5]));
    }

    /**
     * The validate callback declines on the same reading, with the line and its quantity for the
     * merchant's log, so the two cannot disagree about what a cart holds.
     */
    public function testTheFractionalLinesAreListedForTheCaller(): void
    {
        self::assertSame(
            [['sku' => 'CABLE-5MM', 'qty' => 0.5], ['sku' => 'ROPE-8MM', 'qty' => 2.25]],
            $this->validator->fractionalLines($this->quote([
                'SKU-1' => 3.0,
                'CABLE-5MM' => 0.5,
                'ROPE-8MM' => 2.25,
            ]))
        );
    }

    /**
     * A cart of whole quantities has no lines to list, which is what lets the callers skip.
     */
    public function testACartOfWholeQuantitiesListsNoLines(): void
    {
        self::assertSame([], $this->validator->fractionalLines($this->quote(['SKU-1' => 3.0])));
    }

    /**
     * The message names the lines the buyer has to change, and names each one once.
     */
    public function testTheRefusalNamesEveryLineTheBuyerHasToChange(): void
    {
        try {
            $this->validator->validateWholeQuantities($this->quote([
                'SKU-1' => 2.0,
                'CABLE-5MM' => 1.5,
                'SAND-KG' => 0.25,
            ]));
            self::fail('A cart holding a part of an item has to be refused.');
        } catch (LocalizedException $exception) {
            self::assertStringContainsString('CABLE-5MM', $exception->getMessage());
            self::assertStringContainsString('SAND-KG', $exception->getMessage());
            self::assertStringNotContainsString('SKU-1', $exception->getMessage());
        }
    }

    /**
     * A child line is a line of its own in the payload, so its quantity is asked too. A bundle
     * of one holding half a kilo of coffee is the case.
     */
    public function testAChildLineIsAskedAsWellAsTheLineItHangsUnder(): void
    {
        $this->expectException(LocalizedException::class);

        $this->validator->validateWholeQuantities($this->quote([
            'GIFT-BOX' => 1.0,
            'COFFEE-KG' => 0.5,
        ]));
    }

    /**
     * A cart that was never saved has no lines to refuse, and the checkout page asks about one
     * on every render.
     */
    public function testACartWithNoIdIsNotRefused(): void
    {
        $this->expectNotToPerformAssertions();

        $this->validator->validateWholeQuantities($this->quote(['CABLE-5MM' => 0.5], null));
    }

    /**
     * @param array<string, float> $quantities Quantity per sku
     */
    private function quote(array $quantities, ?int $id = 1): Quote&MockObject
    {
        $items = [];

        foreach ($quantities as $sku => $qty) {
            $item = $this->createMock(QuoteItem::class);
            $item->method('getSku')->willReturn($sku);
            $item->method('getQty')->willReturn($qty);
            $items[] = $item;
        }

        $quote = $this->createMock(Quote::class);
        $quote->method('getId')->willReturn($id);
        $quote->method('getAllItems')->willReturn($items);

        return $quote;
    }
}
