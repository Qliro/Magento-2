<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Test\Unit\Model\Api\Client;

use Magento\Framework\DataObject\IdentityGeneratorInterface;
use Magento\Framework\Serialize\Serializer\Json;
use PHPUnit\Framework\TestCase;
use Qliro\QliroOne\Model\Api\Client\OrderManagement;
use Qliro\QliroOne\Model\Api\Service;
use Qliro\QliroOne\Model\ContainerMapper;
use Qliro\QliroOne\Model\Logger\Manager as LogManager;
use Qliro\QliroOne\Model\QliroOrder\Admin\AddItemsToInvoiceRequest;
use Qliro\QliroOne\Model\QliroOrder\Admin\MarkItemsAsShippedRequest;

/**
 * PLIN-363: the builders send a capture and a refund under an id that repeats when the merchant
 * sends the same document again, which is what stops Qliro booking it twice after a call timed
 * out on an answer it had already given. The client must not stamp over it.
 *
 * @see \Qliro\QliroOne\Model\Api\Client\OrderManagement
 */
class OrderManagementRequestIdTest extends TestCase
{
    public function testKeepsTheRequestIdTheCaptureWasBuiltWith(): void
    {
        $request = new MarkItemsAsShippedRequest();
        $request->setRequestId('11111111-2222-3333-4444-555555555555');

        $this->client()->markItemsAsShipped($request);

        self::assertSame('11111111-2222-3333-4444-555555555555', $request->getRequestId());
    }

    public function testKeepsTheRequestIdTheRefundWasBuiltWith(): void
    {
        $request = new AddItemsToInvoiceRequest();
        $request->setRequestId('11111111-2222-3333-4444-555555555555');

        $this->client()->addItemsToInvoice($request);

        self::assertSame('11111111-2222-3333-4444-555555555555', $request->getRequestId());
    }

    /**
     * Anything that reaches the client without one still gets a fresh id, so a caller building a
     * request by hand is unaffected.
     */
    public function testStampsARequestThatArrivesWithoutAnId(): void
    {
        $request = new MarkItemsAsShippedRequest();

        $this->client()->markItemsAsShipped($request);

        self::assertSame('generated-id', $request->getRequestId());
    }

    private function client(): OrderManagement
    {
        $service = $this->createMock(Service::class);
        $service->method('post')->willReturn(['PaymentTransactions' => []]);

        $idGenerator = $this->createMock(IdentityGeneratorInterface::class);
        $idGenerator->method('generateId')->willReturn('generated-id');

        $containerMapper = $this->createMock(ContainerMapper::class);
        $containerMapper->method('toArray')->willReturn([]);

        return new OrderManagement(
            $service,
            $this->createMock(Json::class),
            $containerMapper,
            $this->createMock(LogManager::class),
            $idGenerator
        );
    }
}
