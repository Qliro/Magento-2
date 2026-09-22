<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */

namespace Qliro\QliroOne\Model\Management;

use Magento\Framework\Escaper;
use Magento\Framework\Exception\NoSuchEntityException;
use Qliro\QliroOne\Model\Exception\AlreadyPlacedException;
use Qliro\QliroOne\Model\Exception\UnsupportedQuoteException;
use Qliro\QliroOne\Model\Logger\Manager;
use Magento\Framework\App\Response\Http;
use \Qliro\QliroOne\Api\LinkRepositoryInterface;
/**
 * QliroOne management class
 */
class HtmlSnippet extends AbstractManagement
{
    /**
     * Inject dependencies
     *
     * @param QliroOrder $qliroOrder
     * @param Manager $logManager
     * @param Http $http
     * @param LinkRepositoryInterface $linkRepository
     * @param Escaper $escaper
     */
    public function __construct(
        private readonly QliroOrder $qliroOrder,
        private readonly  Manager $logManager,
        private readonly  Http $http,
        private readonly  LinkRepositoryInterface $linkRepository,
        private readonly Escaper $escaper
    ) {
    }

    /**
     * Fetch an HTML snippet from QliroOne order
     *
     * @return string
     */
    public function get()
    {
        try {
            try {
                $this->linkRepository->unlock($this->getQuote()->getId());
            } catch (NoSuchEntityException $exception) {}

            return $this->qliroOrder->setQuote($this->getQuote())->get()->getOrderHtmlSnippet();
        } catch (AlreadyPlacedException $exception) {
            $this->logManager->debug('The order has already been placed. Redirecting to pending order page.');
            $this->http->setRedirect(
                $this->getQuote()->getStore()->getUrl('checkout/qliro/pending')
            );
            return '';
        } catch (UnsupportedQuoteException $exception) {
            /*
             * The cart, not the checkout, is what the buyer has to change, and the reload link
             * below would only bring them back here. The message names the line and says what to
             * do with it, so it is shown in place of the widget.
             */
            $this->logManager->debug('The cart cannot be paid for with Qliro: ' . $exception->getMessage());

            return $this->escaper->escapeHtml($exception->getMessage());
        } catch (\Exception $exception) {
            $this->logManager->critical(
                sprintf(
                    'QliroOne Checkout has failed to load. %s',
                    $exception->getMessage()
                ),
                ['exception' => $exception, 'extra' => $exception->getTrace()]
            );

            $openTag = '<a href="javascript:;" onclick="location.reload(true)">';
            $closeTag = '</a>';

            return __('QliroOne Checkout has failed to load. Please try to %1reload page%2.', $openTag, $closeTag);
        }
    }
}
