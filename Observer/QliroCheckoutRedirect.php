<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Observer;

use Magento\Checkout\Model\Session;
use Magento\Framework\DataObject;
use Magento\Framework\Event\Manager as EventManager;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Url;
use Qliro\QliroOne\Model\Config;
use Qliro\QliroOne\Model\Success\Session as SuccessSession;

/**
 * Class QliroCheckoutRedirect
 */
class QliroCheckoutRedirect implements ObserverInterface
{
    /**
     * Class constructor
     *
     * @param EventManager $manager
     * @param Url $url
     * @param Session $session
     * @param Config $qliroConfig
     * @param SuccessSession $successSession
     */
    public function __construct(
        private readonly EventManager $manager,
        private readonly Url $url,
        private readonly Session $session,
        private readonly Config $qliroConfig,
        private readonly SuccessSession $successSession
    ) {
    }

    /**
     * Override the redirect to checkout but make it possible to control this override in custom extensions
     *
     * @param \Magento\Framework\Event\Observer $observer
     * @return void
     */
    public function execute(Observer $observer): void
    {
        $this->successSession->clear();

        $state = new DataObject();

        $state->setData([
            'redirect_url' => $this->url->getRouteUrl('checkout/qliro'),
        ]);

        if ($this->qliroConfig->getShowAsPaymentMethod()) {
            $state->setMustDisable(true);
        }

        $this->manager->dispatch(
            'qliroone_override_load_checkout',
            [
                'state' => $state,
                'checkout_observer' => $observer,
            ]
        );

        $mustEnable = $state->getMustEnable();
        $mustDisable = $state->getMustDisable();
        $qliroOverride = $this->session->getQliroOverride();


        if ($mustEnable || (!$mustDisable&& !$qliroOverride && $this->qliroConfig->isActive())) {
            $observer->getControllerAction()
                ->getResponse()
                ->setRedirect($state->getRedirectUrl())
                ->sendResponse();
        }
    }
}
