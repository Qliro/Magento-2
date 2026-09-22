<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Model\Api;

use Magento\Framework\DataObject\IdentityGeneratorInterface;

/**
 * The id a settlement call is sent under, the same one when the merchant sends it again
 *
 * Qliro recognises a repeated `RequestId` and books the request once. A fresh id per call is
 * exactly what must not happen now that a call can time out: a capture or a refund Qliro has
 * already booked answers nothing, Magento rolls its document back, and the merchant does it
 * again. Under a new id that is a second capture of the buyer's money.
 *
 * The id is built from what the merchant is asking for rather than from the document, because
 * the document has no id yet: Magento saves the invoice and the credit memo after the payment
 * method has spoken to the gateway, and rolls them back when it throws. What stays the same
 * across the retry is the order, the transaction being settled, the amount, and what the order
 * had already settled before the attempt. A second, deliberate settlement of the same amount
 * moves that last part, so it is a different id and Qliro books it.
 */
class RequestId
{
    /**
     * @var IdentityGeneratorInterface
     */
    private $idGenerator;

    /**
     * Inject dependencies
     *
     * @param IdentityGeneratorInterface $idGenerator
     */
    public function __construct(IdentityGeneratorInterface $idGenerator)
    {
        $this->idGenerator = $idGenerator;
    }

    /**
     * A uuid that repeats for the same request and differs for every other one
     *
     * @param array $parts What the request is asking for, in a fixed order
     * @return string
     */
    public function forRequest(array $parts): string
    {
        $key = implode(
            '|',
            array_map(
                static function ($part) {
                    // An amount reaches this as a float here and as an int there, and 100 and
                    // 100.0 are the same request
                    return is_int($part) || is_float($part)
                        ? number_format((float)$part, 4, '.', '')
                        : (string)$part;
                },
                $parts
            )
        );

        return $this->idGenerator->generateIdForData($key);
    }
}
