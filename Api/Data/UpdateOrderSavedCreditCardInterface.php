<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Api\Data;

/**
 * Update Order Saved Credit Card notification interface
 *
 * @api
 */
interface UpdateOrderSavedCreditCardInterface
{
    /**
     * Get Qliro order ID
     *
     * @return string
     */
    public function getQliroOrderId(): string;

    /**
     * Set Qliro order ID
     *
     * @param string $qliroOrderId
     * @return void
     */
    public function setQliroOrderId(string $qliroOrderId): void;

    /**
     * Get saved credit card ID
     *
     * @param string $id
     * @return string
     */
    public function getId(string $id): string;

    /**
     * Set saved credit card ID
     *
     * @param string $id
     * @return void
     */
    public function setId(string $id): void;

    /**
     * Get card brand name
     *
     * @return string
     */
    public function getCardBrandName(): string;

    /**
     * Set card brand name
     *
     * @param string $cardBrandName
     * @return void
     */
    public function setCardBrandName(string $cardBrandName): void;

    /**
     * Get card BIN (first six digits)
     *
     * @return string
     */
    public function getCardBin(): string;

    /**
     * Set card BIN
     *
     * @param string $cardBin
     * @return void
     */
    public function setCardBin(string $cardBin): void;

    /**
     * Get the last 4 digits of the card
     *
     * @return string
     */
    public function getCardLast4Digits(): string;

    /**
     * Set the last 4 digits of the card
     *
     * @param string $cardLast4Digits
     * @return void
     */
    public function setCardLast4Digits(string $cardLast4Digits): void;

    /**
     * Get a card expiry year
     *
     * @return string
     */
    public function getExpiryYear(): string;

    /**
     * Set card expiry year
     *
     * @param string $expiryYear
     * @return void
     */
    public function setExpiryYear(string $expiryYear): void;

    /**
     * Get a card expiry month
     *
     * @return string
     */
    public function getExpiryMonth(): string;

    /**
     * Set card expiry month
     *
     * @param string $expiryMonth
     * @return void
     */
    public function setExpiryMonth(string $expiryMonth): void;

    /**
     * Gets saved credit card token
     *
     * @return string
     */
    public function getSavedCreditCardToken(): string;

    /**
     * Get notification timestamp
     *
     * @return string
     */
    public function getTimeStamp(): string;

    /**
     * Set the notification timestamp
     *
     * @param string $timeStamp
     * @return void
     */
    public function setTimeStamp(string $timeStamp): void;
}
