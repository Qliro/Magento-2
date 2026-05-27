<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Model\Newsletter;

/*
 * derived from Magento\Newsletter\Controller\Subscriber\NewAction.php
 */

use Magento\Customer\Model\Session;
use Magento\Customer\Model\Url as CustomerUrl;
use Magento\Framework\Exception\LocalizedException;
use Magento\Newsletter\Model\Subscriber;
use Magento\Newsletter\Model\SubscriberFactory;
use Magento\Framework\App\Config\ScopeConfigInterface as ScopeConfig;
use Magento\Framework\Message\ManagerInterface as MessageManager;
use Magento\Store\Model\ScopeInterface;
use Qliro\QliroOne\Api\SubscriptionInterface;
use Qliro\QliroOne\Model\Logger\Manager as LogManager;

/**
 * Class Subscription
 */
readonly class Subscription implements SubscriptionInterface
{
    /**
     * Class constructor
     *
     * @param SubscriberFactory   $subscriberFactory
     * @param Session             $customerSession
     * @param CustomerUrl         $customerUrl
     * @param ScopeConfig         $scopeConfig
     * @param MessageManager      $messageManager
     * @param LogManager          $logManager
     */
    public function __construct(
        private SubscriberFactory $subscriberFactory,
        private Session           $customerSession,
        private CustomerUrl       $customerUrl,
        private ScopeConfig       $scopeConfig,
        private MessageManager    $messageManager,
        private LogManager        $logManager
    ) {
    }

    /**
     * Validates that if the current user is a guest, that they can subscribe to a newsletter.
     *
     * @param int $storeId
     * @throws LocalizedException
     * @return void
     */
    private function validateGuestSubscription(int $storeId): void
    {
        if ($this->scopeConfig->getValue(
                Subscriber::XML_PATH_ALLOW_GUEST_SUBSCRIBE_FLAG,
                ScopeInterface::SCOPE_STORE, $storeId
            ) != 1
            && !$this->customerSession->isLoggedIn()
        ) {
            throw new LocalizedException(
                __(
                    'Sorry, but the administrator denied subscription for guests. Please <a href="%1">register</a>.',
                    $this->customerUrl->getRegisterUrl()
                )
            );
        }
    }

    /**
     * Validates the format of the email address
     *
     * @param string $email
     * @throws LocalizedException
     * @return void
     */
    private function validateEmailFormat(string $email): void
    {
        if (!\filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new LocalizedException(__('Please enter a valid email address.'));
        }
    }

    /**
     * @inheritdoc
     */
    public function addSubscription(string $email, int $storeId): void
    {
        try {
            $this->validateEmailFormat($email);
            $this->validateGuestSubscription($storeId);

            $subscriber = $this->subscriberFactory->create()->loadByEmail($email);
            if ($subscriber->getId() || $subscriber->getSubscriberStatus() != Subscriber::STATUS_SUBSCRIBED) {
                $status = $this->subscriberFactory->create()->subscribe($email);
                $this->logManager->info('Added {email} as subscriber', ['email' => $email]);
                if ($status == Subscriber::STATUS_NOT_ACTIVE) {
                    $this->messageManager->addSuccessMessage(__('The confirmation request has been sent.'));
                } else {
                    $this->messageManager->addSuccessMessage(__('Thank you for your subscription.'));
                }
            }
        } catch (LocalizedException $exception) {
            $this->logManager->critical(
                $exception,
                [
                    'extra' => [
                        'email' => $email,
                        'storeId' => $storeId
                    ],
                ]
            );
            $this->messageManager->addExceptionMessage(
                $exception,
                __('There was a problem with the subscription: %1', $exception->getMessage())
            );
        } catch (\Exception $exception) {
            $this->logManager->critical(
                $exception,
                [
                    'extra' => [
                        'email' => $email,
                        'storeId' => $storeId
                    ],
                ]
            );
            $this->messageManager->addExceptionMessage($exception, __('Something went wrong with the subscription.'));
        }
    }
}
