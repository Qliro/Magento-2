<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */

namespace Qliro\QliroOne\Model\Management;

use Qliro\QliroOne\Model\Exception\AlreadyPlacedException;
use Qliro\QliroOne\Model\Logger\Manager;
use Magento\Framework\App\Response\Http;

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
     */
    public function __construct(
        private QliroOrder $qliroOrder,
        private Manager $logManager,
        private Http $http
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
            return $this->qliroOrder->setQuote($this->getQuote())->get()->getOrderHtmlSnippet();
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
