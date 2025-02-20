<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */

namespace Qliro\QliroOne\Model\Management;

use Qliro\QliroOne\Model\Logger\Manager;

/**
 * QliroOne management class
 */
class HtmlSnippet extends AbstractManagement
{
    /**
     * @var QliroOrder
     */
    private $qliroOrder;

    /**
     * @var Manager
     */
    private Manager $logManager;

    /**
     * Inject dependencies
     *
     * @param QliroOrder $qliroOrder
     * @param Manager $logManager
     */
    public function __construct(
        QliroOrder $qliroOrder,
        Manager $logManager
    ) {
        $this->qliroOrder = $qliroOrder;
        $this->logManager = $logManager;
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
