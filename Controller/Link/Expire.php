<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Controller\Link;

use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Magento\Framework\Controller\Result\JsonFactory;
use Qliro\QliroOne\Api\LinkRepositoryInterface as LinkRepository;
use Qliro\QliroOne\Model\Logger\Manager as LoggerManager;
use Qliro\QliroOne\Model\Management\Quote as QuoteManagement;
use Qliro\QliroOne\Service\Checkout\LinkManager;

/**
 * Class Expire
 */
class Expire extends Action
{
    /**
     * Class constructor
     *
     * @param Context                    $context
     * @param JsonFactory                $resultJsonFactory
     * @param CheckoutSession            $checkoutSession
     * @param LinkRepository             $linkRepository
     * @param LinkManager                $linkManager
     * @param LoggerManager              $loggerManager
     */
    public function __construct(
                         Context         $context,
        private readonly JsonFactory     $resultJsonFactory,
        private readonly CheckoutSession $checkoutSession,
        private readonly LinkRepository  $linkRepository,
        private readonly LinkManager     $linkManager,
        private readonly LoggerManager   $loggerManager,
        private readonly QuoteManagement $quoteManagement
    ) {
        parent::__construct($context);
    }

    /**
     * @inheirtDoc
     */
    public function execute()
    {
        $result = $this->resultJsonFactory->create();
        $quote = $this->checkoutSession->getQuote();
        if (!$quote || !$quote->getId()) {
            return $result->setHttpResponseCode(400)
                ->setData(['ok' => false, 'msg' => 'No active quote']);
        }

        try {
            $link = $this->linkRepository->getByQuoteId((int)$quote->getId());
            $this->linkManager->deactivate($link);
            $this->quoteManagement->setQuote($quote)->getLinkFromQuote();
            return $result->setData(['ok' => true]);
        } catch (\Throwable $e) {
            $this->loggerManager->warning('Could not deactivate link on FE expiry', [
                'quoteId' => $quote->getId(),
                'err' => $e->getMessage(),
            ]);
            return $result->setHttpResponseCode(500)->setData(['ok' => false]);
        }
    }
}
