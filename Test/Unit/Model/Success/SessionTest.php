<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Test\Unit\Model\Success;

use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Sales\Model\Order;
use PHPUnit\Framework\TestCase;
use Qliro\QliroOne\Model\Success\Session;

/**
 * @see \Qliro\QliroOne\Model\Success\Session::save
 *
 * PLIN-390: tracking extensions identify the order through the keys Magento's own checkout leaves
 * on the session, `getLastRealOrderId()` above all. The module used to write only its own success
 * keys, so GA4 and Google Ads purchase tracking had no order to report.
 */
class SessionTest extends TestCase
{
    private CheckoutSession $checkoutSession;
    private Session $successSession;

    protected function setUp(): void
    {
        $this->checkoutSession = $this->checkoutSession();
        $this->successSession = new Session($this->checkoutSession);
    }

    /**
     * The keys core sets in `Magento\Checkout\Model\Type\Onepage::saveOrder()`, with the meaning it
     * gives them: the real order id is the increment id, the order id is the entity id.
     */
    public function testSaveSetsTheSessionKeysCoreSets(): void
    {
        $this->successSession->save('<p>Qliro</p>', $this->order());

        self::assertSame('1001211998', $this->checkoutSession->getLastRealOrderId());
        self::assertSame(42, $this->checkoutSession->getLastOrderId());
        self::assertSame(17, $this->checkoutSession->getLastQuoteId());
        self::assertSame(17, $this->checkoutSession->getLastSuccessQuoteId());
        self::assertSame('pending', $this->checkoutSession->getLastOrderStatus());
    }

    /**
     * The module's own keys are what its success page renders from, and they stay as they were.
     */
    public function testSaveKeepsTheModulesOwnSuccessKeys(): void
    {
        $this->successSession->save('<p>Qliro</p>', $this->order());

        self::assertSame('<p>Qliro</p>', $this->successSession->getSuccessHtmlSnippet());
        self::assertSame('1001211998', $this->successSession->getSuccessIncrementId());
        self::assertSame(42, $this->successSession->getSuccessOrderId());
        self::assertFalse($this->successSession->hasSuccessDisplayed());
    }

    /**
     * Marking the success page as displayed is what stops a reload from firing a second purchase
     * event, so the flag has to survive as the only thing that changed.
     */
    public function testDisplayedFlagIsSetWithoutLosingTheOrder(): void
    {
        $this->successSession->save('<p>Qliro</p>', $this->order());
        $this->successSession->setSuccessDisplayed();

        self::assertTrue($this->successSession->hasSuccessDisplayed());
        self::assertSame('1001211998', $this->checkoutSession->getLastRealOrderId());
    }

    /**
     * Entering the checkout again clears the module's keys. The core ones are left alone, exactly
     * as a core checkout leaves them, so a tracking script that runs late still finds its order.
     */
    public function testClearLeavesTheCoreKeysAlone(): void
    {
        $this->successSession->save('<p>Qliro</p>', $this->order());

        $this->successSession->clear();

        self::assertNull($this->successSession->getSuccessIncrementId());
        self::assertNull($this->successSession->getSuccessOrderId());
        self::assertSame('1001211998', $this->checkoutSession->getLastRealOrderId());
        self::assertSame(42, $this->checkoutSession->getLastOrderId());
    }

    private function order(): Order
    {
        $order = $this->createMock(Order::class);
        $order->method('getId')->willReturn(42);
        $order->method('getIncrementId')->willReturn('1001211998');
        $order->method('getQuoteId')->willReturn(17);
        $order->method('getStatus')->willReturn('pending');

        return $order;
    }

    /**
     * A checkout session with its real data semantics. `SessionManager` keeps the values in a
     * storage object built by a constructor that a unit test cannot run, and its magic setters
     * are what this class is written in, so the magic is what gets replaced here.
     */
    private function checkoutSession(): CheckoutSession
    {
        return new class extends CheckoutSession {
            /**
             * @var array
             */
            private array $data = [];

            // phpcs:ignore Magento2.Functions.StaticFunction, Squiz.Commenting.FunctionComment
            public function __construct()
            {
            }

            /**
             * @param string $method
             * @param array $args
             * @return $this|mixed|null
             */
            public function __call($method, $args)
            {
                $key = strtolower((string)preg_replace('/(?<!^)[A-Z]/', '_$0', substr($method, 3)));

                switch (substr($method, 0, 3)) {
                    case 'set':
                        $this->data[$key] = $args[0] ?? null;
                        return $this;
                    case 'uns':
                        unset($this->data[$key]);
                        return $this;
                    case 'has':
                        return isset($this->data[$key]);
                    default:
                        return $this->data[$key] ?? null;
                }
            }
        };
    }
}
