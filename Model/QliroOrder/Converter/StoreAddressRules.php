<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Model\QliroOrder\Converter;

use Magento\Framework\DataObject;
use Magento\Framework\ObjectManagerInterface;

/**
 * Whether this store's own Magento accepts an address value as Qliro sends it
 *
 * Asks the address validator the store runs, so a value is only reduced by `AddressText` where it
 * would actually be refused: a store before 2.4.8 has no such validator and keeps every value,
 * and 2.4.9 keeps `St. Gallen` and a long phone that only 2.4.8 refuses. The validators are
 * resolved by name because they do not exist on every version this module supports.
 */
class StoreAddressRules
{
    /**
     * Address field => the Magento validator that checks it
     */
    private const VALIDATORS = [
        'firstname' => 'Magento\Customer\Model\Validator\Name',
        'lastname' => 'Magento\Customer\Model\Validator\Name',
        'city' => 'Magento\Customer\Model\Validator\City',
        'telephone' => 'Magento\Customer\Model\Validator\Telephone',
    ];

    /**
     * @var \Magento\Framework\ObjectManagerInterface
     */
    private $objectManager;

    /**
     * @var array<string, string>
     */
    private $validators;

    /**
     * Inject dependencies
     *
     * @param ObjectManagerInterface $objectManager
     * @param array<string, string> $validators
     */
    public function __construct(ObjectManagerInterface $objectManager, array $validators = self::VALIDATORS)
    {
        $this->objectManager = $objectManager;
        $this->validators = $validators;
    }

    /**
     * Whether the store's validator for the field accepts the value, true when it has none
     *
     * @param string $field
     * @param string $value
     * @return bool
     */
    public function accepts(string $field, string $value): bool
    {
        $class = $this->validators[$field] ?? null;
        if ($class === null || !class_exists($class)) {
            return true;
        }

        // A new instance each time: a validator keeps the messages of every value it refused
        $validator = $this->objectManager->create($class);

        return (bool)$validator->isValid(new DataObject([$field => $value]));
    }
}
