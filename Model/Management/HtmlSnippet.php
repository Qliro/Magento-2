<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */

namespace Qliro\QliroOne\Model\Management;

use Magento\Framework\Exception\NoSuchEntityException;
use Qliro\QliroOne\Model\Exception\AlreadyPlacedException;
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
     */
    public function __construct(
        private readonly QliroOrder $qliroOrder,
        private readonly  Manager $logManager,
        private readonly  Http $http,
        private readonly  LinkRepositoryInterface $linkRepository
    ) {
    }

    /**
     * Fetch an HTML snippet from QliroOne order, leaving the caller to answer for a failure
     *
     * The checkout page turns a failure into page content, an ajax caller into a payload, so the
     * fetch itself lives here and each caller keeps its own answer.
     *
     * @return string
     * @throws AlreadyPlacedException
     */
    public function fetch()
    {
        try {
            $this->linkRepository->unlock($this->getQuote()->getId());
        } catch (NoSuchEntityException $exception) {
            // No link for this quote yet, so there is nothing to unlock
        }

        return (string)$this->qliroOrder->setQuote($this->getQuote())->get()->getOrderHtmlSnippet();
    }

    /**
     * Fetch an HTML snippet from QliroOne order
     *
     * @return string
     */
    public function get()
    {
        try {
            return $this->fetch();
        } catch (AlreadyPlacedException $exception) {
            $this->logManager->debug('The order has already been placed. Redirecting to pending order page.');
            $this->http->setRedirect(
                $this->getQuote()->getStore()->getUrl('checkout/qliro/pending')
            );
            return '';
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
