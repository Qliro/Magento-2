<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Controller\Qliro\Ajax;

use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Qliro\QliroOne\Model\Config;
use Qliro\QliroOne\Model\Success\Session;

/**
 * The success page reports here once it has loaded, which ends the purchase event
 */
class SuccessDisplayed implements HttpPostActionInterface
{
    /**
     * @var \Magento\Framework\App\RequestInterface
     */
    private $request;

    /**
     * @var \Magento\Framework\Controller\Result\JsonFactory
     */
    private $resultJsonFactory;

    /**
     * @var \Qliro\QliroOne\Model\Success\Session
     */
    private $successSession;

    /**
     * @var \Qliro\QliroOne\Model\Config
     */
    private $qliroConfig;

    /**
     * Inject dependencies
     *
     * @param \Magento\Framework\App\RequestInterface $request
     * @param \Magento\Framework\Controller\Result\JsonFactory $resultJsonFactory
     * @param \Qliro\QliroOne\Model\Success\Session $successSession
     * @param \Qliro\QliroOne\Model\Config $qliroConfig
     */
    public function __construct(
        RequestInterface $request,
        JsonFactory $resultJsonFactory,
        Session $successSession,
        Config $qliroConfig
    ) {
        $this->request = $request;
        $this->resultJsonFactory = $resultJsonFactory;
        $this->successSession = $successSession;
        $this->qliroConfig = $qliroConfig;
    }

    /**
     * Mark the success page as shown, only for the order the page was rendered for
     *
     * A tab left open on an older order must not close the event for the one placed since. By
     * entity id, an increment id is unique per store only.
     *
     * @return \Magento\Framework\Controller\Result\Json
     */
    public function execute()
    {
        $orderId = (int)$this->successSession->getSuccessOrderId();
        $reported = $this->request->getParam('order');
        $matches = $this->qliroConfig->isActive()
            && $orderId > 0
            && is_string($reported)
            && $reported === (string)$orderId;

        if ($matches) {
            $this->successSession->setSuccessDisplayed();
        }

        return $this->resultJsonFactory->create()->setData(['displayed' => $matches]);
    }
}
