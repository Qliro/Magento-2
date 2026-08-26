<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Test\Unit\Controller\Qliro\Callback;

use Magento\Framework\App\Action\Context;
use Magento\Framework\App\Request\Http;
use Magento\Framework\Controller\Result\Json as JsonResult;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Qliro\QliroOne\Api\Data\CheckoutStatusInterface;
use Qliro\QliroOne\Api\Data\CheckoutStatusResponseInterface;
use Qliro\QliroOne\Api\Data\MerchantNotificationInterface;
use Qliro\QliroOne\Api\Data\MerchantNotificationResponseInterface;
use Qliro\QliroOne\Api\Data\MerchantSavedCreditCardNotificationInterface;
use Qliro\QliroOne\Api\Data\MerchantSavedCreditCardResponseInterface;
use Qliro\QliroOne\Api\Data\QliroOrderManagementStatusInterface;
use Qliro\QliroOne\Api\Data\QliroOrderManagementStatusResponseInterface;
use Qliro\QliroOne\Api\Data\UpdateShippingMethodsNotificationInterface;
use Qliro\QliroOne\Api\Data\UpdateShippingMethodsResponseInterface;
use Qliro\QliroOne\Api\Data\ValidateOrderNotificationInterface;
use Qliro\QliroOne\Api\Data\ValidateOrderResponseInterface;
use Qliro\QliroOne\Api\ManagementInterface;
use Qliro\QliroOne\Controller\Qliro\Callback\CheckoutStatus;
use Qliro\QliroOne\Controller\Qliro\Callback\MerchantNotification;
use Qliro\QliroOne\Controller\Qliro\Callback\SavedCreditCard;
use Qliro\QliroOne\Controller\Qliro\Callback\ShippingMethods;
use Qliro\QliroOne\Controller\Qliro\Callback\TransactionStatus;
use Qliro\QliroOne\Controller\Qliro\Callback\Validate;
use Qliro\QliroOne\Helper\Data;
use Qliro\QliroOne\Model\Config;
use Qliro\QliroOne\Model\ContainerMapper;
use Qliro\QliroOne\Model\Logger\Manager as LogManager;
use Qliro\QliroOne\Model\Security\CallbackToken;

/**
 * @see \Qliro\QliroOne\Controller\Qliro\Callback\ShippingMethods
 * @see \Qliro\QliroOne\Controller\Qliro\Callback\Validate
 * @see \Qliro\QliroOne\Controller\Qliro\Callback\CheckoutStatus
 * @see \Qliro\QliroOne\Controller\Qliro\Callback\MerchantNotification
 * @see \Qliro\QliroOne\Controller\Qliro\Callback\SavedCreditCard
 * @see \Qliro\QliroOne\Controller\Qliro\Callback\TransactionStatus
 *
 * PLIN-378: a callback whose body was lost on the way carries no OrderId. Every callback has to
 * turn that away with a reason the merchant can grep for, instead of handing it to the management
 * layer that would look up a link with an empty value and find one belonging to someone else.
 */
class MissingOrderIdTest extends TestCase
{
    private ManagementInterface&MockObject $qliroManagement;
    private ContainerMapper&MockObject $containerMapper;
    private Data&MockObject $dataHelper;
    private LogManager&MockObject $logManager;

    protected function setUp(): void
    {
        $this->qliroManagement = $this->createMock(ManagementInterface::class);
        $this->containerMapper = $this->createMock(ContainerMapper::class);
        $this->dataHelper = $this->createMock(Data::class);
        $this->dataHelper->method('readPreparedPayload')->willReturn([]);
        $this->logManager = $this->createMock(LogManager::class);
    }

    /**
     * A payload without an OrderId is declined, and the management layer is never entered.
     *
     * @dataProvider callbackProvider
     */
    public function testDeclinesCallbackWithoutOrderId(array $callback): void
    {
        $this->givenPayloadWithOrderId($callback['notification'], $callback['emptyOrderId']);

        $this->qliroManagement->expects(self::never())->method($callback['management']);
        $this->logManager->expects(self::once())->method('warning');

        $result = $this->createMock(JsonResult::class);
        $this->dataHelper->expects(self::once())
            ->method('sendPreparedPayload')
            ->with($callback['declinePayload'], 400, null, $callback['mark'])
            ->willReturn($result);

        self::assertSame($result, $this->createController($callback['controller'])->execute());
    }

    /**
     * A payload that carries an OrderId is still handled as before.
     *
     * @dataProvider callbackProvider
     */
    public function testHandsCallbackWithOrderIdToTheManagementLayer(array $callback): void
    {
        $container = $this->givenPayloadWithOrderId($callback['notification'], $callback['orderId']);

        $this->qliroManagement->expects(self::once())
            ->method($callback['management'])
            ->with($container)
            ->willReturn($this->createMock($callback['response']));

        $this->logManager->expects(self::never())->method('warning');

        $this->createController($callback['controller'])->execute();
    }

    /**
     * The order id is given in the type the notification interface declares
     *
     * @return array<string, array<int, array<string, mixed>>>
     */
    public static function callbackProvider(): array
    {
        return [
            'shippingMethods' => [[
                'controller' => ShippingMethods::class,
                'notification' => UpdateShippingMethodsNotificationInterface::class,
                'response' => UpdateShippingMethodsResponseInterface::class,
                'management' => 'getShippingMethods',
                'declinePayload' => ['error' => UpdateShippingMethodsResponseInterface::REASON_POSTAL_CODE],
                'mark' => 'CALLBACK:SHIPPING_METHODS:ERROR_NO_ORDER_ID',
                'emptyOrderId' => null,
                'orderId' => 5531737,
            ]],
            'validate' => [[
                'controller' => Validate::class,
                'notification' => ValidateOrderNotificationInterface::class,
                'response' => ValidateOrderResponseInterface::class,
                'management' => 'validateQliroOrder',
                'declinePayload' => ['error' => ValidateOrderResponseInterface::REASON_OTHER],
                'mark' => 'CALLBACK:VALIDATE:ERROR_NO_ORDER_ID',
                'emptyOrderId' => null,
                'orderId' => 5531737,
            ]],
            'checkoutStatus' => [[
                'controller' => CheckoutStatus::class,
                'notification' => CheckoutStatusInterface::class,
                'response' => CheckoutStatusResponseInterface::class,
                'management' => 'checkoutStatus',
                'declinePayload' => [
                    CheckoutStatusResponseInterface::CALLBACK_RESPONSE =>
                        CheckoutStatusResponseInterface::RESPONSE_ORDER_NOT_FOUND,
                ],
                'mark' => 'CALLBACK:CHECKOUT_STATUS:ERROR_NO_ORDER_ID',
                'emptyOrderId' => null,
                'orderId' => 5531737,
            ]],
            'merchantNotification' => [[
                'controller' => MerchantNotification::class,
                'notification' => MerchantNotificationInterface::class,
                'response' => MerchantNotificationResponseInterface::class,
                'management' => 'merchantNotification',
                'declinePayload' => [
                    MerchantNotificationResponseInterface::CALLBACK_RESPONSE =>
                        MerchantNotificationResponseInterface::RESPONSE_ORDER_NOT_FOUND,
                ],
                'mark' => 'CALLBACK:MERCHANT_NOTIFICATION:ERROR_NO_ORDER_ID',
                'emptyOrderId' => null,
                'orderId' => 5531737,
            ]],
            'savedCreditCard' => [[
                'controller' => SavedCreditCard::class,
                'notification' => MerchantSavedCreditCardNotificationInterface::class,
                'response' => MerchantSavedCreditCardResponseInterface::class,
                'management' => 'updateOrderSavedCreditCard',
                'declinePayload' => [
                    MerchantSavedCreditCardResponseInterface::CALLBACK_RESPONSE =>
                        MerchantSavedCreditCardResponseInterface::RESPONSE_ORDER_NOT_FOUND,
                ],
                'mark' => 'CALLBACK:MERCHANT_SAVED_CREDIT_CARD:ERROR_NO_ORDER_ID',
                'emptyOrderId' => '',
                'orderId' => '5531737',
            ]],
            'transactionStatus' => [[
                'controller' => TransactionStatus::class,
                'notification' => QliroOrderManagementStatusInterface::class,
                'response' => QliroOrderManagementStatusResponseInterface::class,
                'management' => 'handleTransactionStatus',
                'declinePayload' => [
                    QliroOrderManagementStatusResponseInterface::CALLBACK_RESPONSE =>
                        QliroOrderManagementStatusResponseInterface::RESPONSE_ORDER_NOT_FOUND,
                ],
                'mark' => 'CALLBACK:MANAGEMENT_STATUS:ERROR_NO_ORDER_ID',
                'emptyOrderId' => null,
                'orderId' => 5531737,
            ]],
        ];
    }

    /**
     * Build the controller, passing whatever its first constructor argument asks for
     *
     * @param string $controllerClass
     * @return object
     */
    private function createController(string $controllerClass): object
    {
        $request = $this->createMock(Http::class);

        $constructor = new \ReflectionMethod($controllerClass, '__construct');
        $expectedType = $constructor->getParameters()[0]->getType()->getName();

        if ($expectedType === Context::class) {
            $context = $this->createMock(Context::class);
            $context->method('getRequest')->willReturn($request);
            $firstArgument = $context;
        } else {
            $firstArgument = $request;
        }

        $qliroConfig = $this->createMock(Config::class);
        $qliroConfig->method('isActive')->willReturn(true);

        $callbackToken = $this->createMock(CallbackToken::class);
        $callbackToken->method('verifyToken')->willReturn(true);

        return new $controllerClass(
            $firstArgument,
            $qliroConfig,
            $this->qliroManagement,
            $this->containerMapper,
            $this->dataHelper,
            $callbackToken,
            $this->logManager
        );
    }

    /**
     * Let the container mapper return a notification carrying the given order id
     *
     * @param string $notificationClass
     * @param string|int|null $orderId
     * @return object
     */
    private function givenPayloadWithOrderId(string $notificationClass, $orderId): object
    {
        $container = $this->createMock($notificationClass);
        $container->method('getOrderId')->willReturn($orderId);
        $this->containerMapper->method('fromArray')->willReturn($container);

        return $container;
    }
}
