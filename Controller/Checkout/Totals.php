<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */

namespace Qliro\QliroOne\Controller\Checkout;

use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\Request\Http;
use Magento\Checkout\Model\Session;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Json\Helper\Data;
use Magento\Quote\Api\CartRepositoryInterface;

class Totals implements HttpPostActionInterface
{
    /**
     * @var \Magento\Framework\App\Request\Http
     */
    private Http $request;

    /**
     * @var Session
     */
    protected $checkoutSession;

    /**
     * @var JsonFactory
     */
    protected $resultJson;

    /**
     * @var Data
     */
    protected $helper;

    /**
     * @var CartRepositoryInterface
     */
    protected $quoteRepository;

    /**
     * Checkout Totals Ajax Controller constructor.
     *
     * @param \Magento\Framework\App\Request\Http $request
     * @param Session $checkoutSession
     * @param Data $helper
     * @param JsonFactory $resultJson
     * @param CartRepositoryInterface $quoteRepository
     */
    public function __construct(
        Http $request,
        Session $checkoutSession,
        Data $helper,
        JsonFactory $resultJson,
        CartRepositoryInterface $quoteRepository
    ) {
        $this->request = $request;
        $this->checkoutSession = $checkoutSession;
        $this->helper = $helper;
        $this->resultJson = $resultJson;
        $this->quoteRepository = $quoteRepository;
    }

    /**
     * Trigger to re-calculate the collect Totals
     *
     * @return bool
     */
    public function execute()
    {
        $response = [
            'errors' => false,
            'message' => ''
        ];

        try {
            /** @var \Magento\Quote\Model\Quote $quote */
            $quote = $this->quoteRepository->get($this->checkoutSession->getQuoteId());

            /** @var array $payment */
            $payment = $this->helper->jsonDecode($this->request->getContent());
            $quote->getPayment()->setMethod($payment['payment']);
            $quote->collectTotals();
            $this->quoteRepository->save($quote);
        } catch (\Exception $e) {
            $response = [
                'errors' => true,
                'message' => $e->getMessage()
            ];
        }

        /** @var \Magento\Framework\Controller\Result\Raw $resultJson */
        $resultJson = $this->resultJson->create();

        return $resultJson->setData($response);
    }
}
