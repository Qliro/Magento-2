<?php declare(strict_types=1);

namespace Qliro\QliroOne\Model\Management;

use Magento\Framework\Exception\AlreadyExistsException;
use Qliro\QliroOne\Api\Data\LinkInterfaceFactory;
use Magento\Quote\Api\CartManagementInterface;
use Qliro\QliroOne\Api\LinkRepositoryInterface;
use Qliro\QliroOne\Model\MerchantPayment\Builder\CreateRequestBuilder;
use Qliro\QliroOne\Model\Logger\Manager;
use Qliro\QliroOne\Model\Api\Client\OrderManagement;
use Qliro\QliroOne\Service\General\LinkService;
use Qliro\QliroOne\Model\Management\PlaceRecurringOrder;

class CreateMerchantPayment
{
    const DEFAULT_QLIRO_STATUS = 'MerchantPaymentCreated';

    private $order;

    /**
     * @var \Magento\Quote\Model\Quote|null
     */
    private ?\Magento\Quote\Model\Quote $quote = null;

    /**
     * Class constructor
     *
     * @param LinkService $linkService
     * @param CreateRequestBuilder $createRequestBuilder
     * @param LinkInterfaceFactory $linkFactory
     * @param LinkRepositoryInterface $linkRepository
     * @param CartManagementInterface $quoteManagement
     * @param OrderManagement $qliroOrderManagement
     * @param Manager $logManager
     * @param PlaceRecurringOrder $placeOrder
     */
    public function __construct(
        private readonly LinkService $linkService,
        private readonly CreateRequestBuilder $createRequestBuilder,
        private readonly LinkInterfaceFactory $linkFactory,
        private readonly LinkRepositoryInterface $linkRepository,
        private readonly CartManagementInterface $quoteManagement,
        private readonly OrderManagement $qliroOrderManagement,
        private readonly Manager $logManager,
        private readonly PlaceRecurringOrder $placeOrder
    ) {
    }


    /**
     * Set quote
     *
     * @param \Magento\Quote\Model\Quote $quote
     * @return $this
     */
    public function setQuote(\Magento\Quote\Model\Quote $quote): self
    {
        $this->quote = $quote;
        return $this;
    }

    /**
     * Creates Magento Order and Merchant Payment associated with each other
     *
     * @return void
     * @throws AlreadyExistsException|\Exception
     */
    public function execute(): void
    {
        $order = $this->getOrder();
        $quote = $this->quote;
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
            $qliroOrder = $this->qliroOrderManagement->getOrder($qliroOrderId);

            $link = $this->linkFactory->create();
            $link->setQuoteSnapshot('merchantPayment');
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



            $this->placeOrder->setCurrentQuote($quote);
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
