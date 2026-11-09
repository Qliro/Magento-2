<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Model\Method\QliroOne;

use Magento\Framework\App\Area;
use Magento\Framework\App\State;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Message\ManagerInterface as MessageManager;
use Magento\Payment\Gateway\CommandInterface;
use Qliro\QliroOne\Api\LinkRepositoryInterface;
use Qliro\QliroOne\Api\ManagementInterface;
use Qliro\QliroOne\Model\Exception\LinkInactiveException;
use Qliro\QliroOne\Model\Exception\TerminalException;
use Qliro\QliroOne\Model\Logger\Manager as LogManager;

/**
 * Class Capture for QliroOne payment method
 */
class Cancel implements CommandInterface
{
    /**
     * @var \Qliro\QliroOne\Api\LinkRepositoryInterface
     */
    private $linkRepository;

    /**
     * @var \Qliro\QliroOne\Model\Logger\Manager
     */
    private $logManager;

    /**
     * @var \Qliro\QliroOne\Api\ManagementInterface
     */
    private $qliroManagement;

    /**
     * @var MessageManager
     */
    private $messageManager;

    /**
     * @var State
     */
    private $appState;

    /**
     * Inject dependencies
     *
     * @param \Qliro\QliroOne\Api\LinkRepositoryInterface $linkRepository
     * @param \Qliro\QliroOne\Model\Logger\Manager $logManager
     * @param \Qliro\QliroOne\Api\ManagementInterface $qliroManagement
     * @param MessageManager $messageManager
     * @param State $appState
     */
    public function __construct(
        LinkRepositoryInterface $linkRepository,
        LogManager $logManager,
        ManagementInterface $qliroManagement,
        MessageManager $messageManager,
        State $appState
    ) {
        $this->linkRepository = $linkRepository;
        $this->logManager = $logManager;
        $this->qliroManagement = $qliroManagement;
        $this->messageManager = $messageManager;
        $this->appState = $appState;
    }

    /**
     * Cancel command
     *
     * @param array $commandSubject
     * @return null
     * @throws \Exception
     */
    public function execute(array $commandSubject)
    {
        if (isset($commandSubject['payment'])) {
            /** @var \Magento\Sales\Model\Order $order */
            $order = $commandSubject['payment']->getOrder();
            $orderId = $order->getId();
        } else {
            $orderId = null;
        }

        try {
            try {
                $link = $this->linkRepository->getByOrderId($orderId);
            } catch (NoSuchEntityException $exception) {
                $this->linkRepository->getByOrderId($orderId, false);
                throw new LinkInactiveException('This order has already been processed and the link deactivated.');
            }

            $this->logManager->setMerchantReference($link->getReference());

            $link->setMessage(sprintf('Order #%s marked as canceled', $orderId));
            $this->linkRepository->save($link);

            $this->qliroManagement->cancelQliroOrder($link->getQliroOrderId());
            $this->logManager->info(
                'Canceled order, requested a QliroOne order cancellation',
                [
                    'extra' => [
                        'order_id' => $orderId,
                        'qliro_order_id' => $link->getQliroOrderId(),
                    ]
                ]
            );
        } catch (LinkInactiveException $exception) {
            return null;
        } catch (\Exception $exception) {
            $logData = [
                'order_id' => $orderId,
                'qliro_order_id' => isset($link) ? $link->getQliroOrderId() : null,
                'exception' => $exception,
            ];

            if (!($exception instanceof TerminalException)) {
                $this->logManager->critical($exception, ['extra' => $logData]);

                throw $exception;
            }

            // Fail-open on purpose: the module also cancels orders Qliro refused or failed, and Qliro
            // may refuse to cancel those, but the Magento order must still close
            $this->logManager->warning('Cancellation was unsuccessful.', ['extra' => $logData]);
            // The subject's order is the gateway's adapter, the comment goes on the order itself
            $salesOrder = isset($commandSubject['payment'])
                ? $commandSubject['payment']->getPayment()->getOrder()
                : null;
            if ($salesOrder) {
                $this->reportRefusal($salesOrder, $exception);
            }
        }

        return null;
    }

    /**
     * Saved with the cancelled order, and shown to the admin who cancelled it
     *
     * @param \Magento\Sales\Model\Order $order
     * @param \Exception $exception
     * @return void
     */
    private function reportRefusal($order, \Exception $exception)
    {
        $reason = $exception->getPrevious() ? $exception->getPrevious()->getMessage() : $exception->getMessage();
        $reason = rtrim(trim($reason), '.') . '.';
        $message = __(
            'Qliro did not cancel the payment, so the amount may still be reserved: %1 '
            . 'Cancel the payment in Qliro Merchant Hub.',
            $reason
        );
        $order->addCommentToStatusHistory((string)$message);

        try {
            if ($this->appState->getAreaCode() === Area::AREA_ADMINHTML) {
                $this->messageManager->addWarningMessage($message);
            }
        } catch (\Exception $areaException) {
            // No area set: nobody to show it to, the comment is the record
            return;
        }
    }
}
