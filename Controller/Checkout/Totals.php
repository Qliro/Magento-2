<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Controller\Checkout;

use Magento\Checkout\Model\Session;
use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Controller\Result\Raw;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Json\Helper\Data;
use Magento\Quote\Api\CartRepositoryInterface as CartRepository;
use Magento\Quote\Model\Quote;

/**
 * Class Totals
 */
class Totals extends Action
{
    /**
     * Class constructor
     *
     * @param Context                     $context
     * @param Session                     $checkoutSession
     * @param Data                        $helper
     * @param JsonFactory                 $resultJson
     * @param CartRepository              $quoteRepository
     */
    public function __construct(
        Context $context,
        protected readonly Session        $checkoutSession,
        protected readonly Data           $helper,
        protected readonly JsonFactory    $resultJson,
        protected readonly CartRepository $quoteRepository
    ) {
        parent::__construct($context);
    }

    /**
     * Trigger to re-calculate the collect Totals
     *
     * @return ResultInterface
     */
    public function execute(): ResultInterface
    {
        $response = [
            'errors' => false,
            'message' => ''
        ];

        try {
            /** @var Quote $quote */
            $quote = $this->quoteRepository->get($this->checkoutSession->getQuoteId());

            /** @var array $payment */
            $payment = $this->helper->jsonDecode($this->getRequest()->getContent());
            $quote->getPayment()->setMethod($payment['payment']);
            $quote->collectTotals();
            $this->quoteRepository->save($quote);
        } catch (\Exception $e) {
            $response = [
                'errors' => true,
                'message' => $e->getMessage()
            ];
        }

        /** @var Raw $resultJson */
        $resultJson = $this->resultJson->create();

        return $resultJson->setData($response);
    }
}
