<?php declare(strict_types=1);

namespace Qliro\QliroOne\Model\Management;

use Qliro\QliroOne\Api\Data\LinkInterfaceFactory;
use Magento\Quote\Api\CartManagementInterface;
use Qliro\QliroOne\Api\LinkRepositoryInterface;
use Qliro\QliroOne\Model\MerchantPayment\Builder\CreateRequestBuilder;
use Qliro\QliroOne\Model\Logger\Manager;
use Qliro\QliroOne\Model\Api\Client\OrderManagement;
use Qliro\QliroOne\Service\General\LinkService;
use Qliro\QliroOne\Model\Management\PlaceRecurringOrder;

class CreateMerchantPayment extends AbstractManagement
{
    const DEFAULT_QLIRO_STATUS = 'MerchantPaymentCreated';

    /**
     * @var \Qliro\QliroOne\Service\General\LinkService
     */
    private LinkService $linkService;

    /**
     * @var \Qliro\QliroOne\Model\MerchantPayment\Builder\CreateRequestBuilder
     */
    private CreateRequestBuilder $createRequestBuilder;

    /**
     * @var \Qliro\QliroOne\Api\Data\LinkInterfaceFactory
     */
    private LinkInterfaceFactory $linkFactory;

    /**
     * @var \Qliro\QliroOne\Api\LinkRepositoryInterface
     */
    private LinkRepositoryInterface $linkRepository;

    /**
     * @var \Magento\Quote\Api\CartManagementInterface
     */
    private CartManagementInterface $quoteManagement;

    /**
     * @var \Qliro\QliroOne\Model\Api\Client\OrderManagement
     */
    private OrderManagement $qliroOrderManagement;

    /**
     * @var \Qliro\QliroOne\Model\Logger\Manager
     */
    private Manager $logManager;

    /**
     * @var \Qliro\QliroOne\Model\Management\PlaceRecurringOrder
     */
    private $placeOrder;

    private $order;

    public function __construct(
        LinkService $linkService,
        CreateRequestBuilder $createRequestBuilder,
        LinkInterfaceFactory $linkFactory,
        LinkRepositoryInterface $linkRepository,
        CartManagementInterface $quoteManagement,
        OrderManagement $qliroOrderManagement,
        Manager $logManager,
        PlaceRecurringOrder $placeOrder
    ) {
        $this->linkService = $linkService;
        $this->createRequestBuilder = $createRequestBuilder;
        $this->linkFactory = $linkFactory;
        $this->linkRepository = $linkRepository;
        $this->logManager = $logManager;
        $this->quoteManagement = $quoteManagement;
        $this->qliroOrderManagement = $qliroOrderManagement;
        $this->placeOrder = $placeOrder;
    }

    /**
     * Creates Magento Order and Merchant Payment associated with each other
     *
     * @return void
     * @throws \Magento\Framework\Exception\AlreadyExistsException
     */
    public function execute(): void
    {
        $order = $this->getOrder();
        $quote = $this->getQuote();
        $quoteId = $quote->getEntityId();

        $orderReference = $this->linkService->generateOrderReference($quote);
        $this->logManager->setMerchantReference($orderReference);
        $this->logManager->setMark('CREATE MERCHANT PAYMENT');

        $request = $this->createRequestBuilder->setQuote($quote)->setOrder($order)->create();
        $request->setMerchantReference($orderReference);

        $merchantPaymentResponse = null;
        try {
            // First try creating the Merchant Payment, then the Magento order
            $merchantPaymentResponse = $this->qliroOrderManagement->createMerchantPayment(
                $request,
                (int)$quote->getStoreId()
            );
            $qliroOrderId = $merchantPaymentResponse->getOrderId();
            $paymentTransactions = $merchantPaymentResponse->getPaymentTransactions();
            $state = $paymentTransactions[0]->getStatus();
            $qliroOrder = $this->qliroOrderManagement->getOrder($qliroOrderId, (int)$quote->getStoreId());
            
            $link = $this->linkFactory->create();
            $link->setQuoteSnapshot('merchantPayment');// A real Quote Snapshot is not needed here but the value is required
            $link->setQuoteId($quoteId);
            $link->setReference($qliroOrder->getMerchantReference());
            $link->setQliroOrderId($qliroOrderId);
            $link->setQliroOrderStatus(self::DEFAULT_QLIRO_STATUS);
            $link->setIsActive(1); // The convention is setting the link as Active if the order is placed without errors
            $orderItems = $qliroOrder->getOrderItemActions();
            foreach ($orderItems as $orderItem) {
                if($orderItem->getType() == 'Shipping')
                    $link->setUnifaunShippingAmount($orderItem->getPricePerItemIncVat());
            }
            $this->linkRepository->save($link);


            $this->placeOrder->setQuote($quote);
            $magentoOrder = $this->placeOrder->execute($qliroOrder, $state);
        } catch (\Exception $exception) {
            $this->logManager->critical($exception->getMessage());
            return;
        }
    }

    /**
     * Get the order from the management class
     *
     * @return \Magento\Sales\Model\Order
     */
    public function getOrder()
    {
        if (!($this->order instanceof \Magento\Sales\Model\Order)) {
            throw new \LogicException('Order must be set before it is fetched.');
        }

        return $this->order;
    }

    /**
     * Set the order in the management class
     *
     * @param \Magento\Sales\Model\Order $order
     * @return $this
     */
    public function setOrder($order)
    {
        $order->setFirstCaptureFlag(true);
        $this->order = $order;

        return $this;
    }
}
