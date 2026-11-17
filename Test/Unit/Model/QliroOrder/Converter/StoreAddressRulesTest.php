<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Test\Unit\Model\QliroOrder\Converter;

use Magento\Framework\DataObject;
use Magento\Framework\ObjectManagerInterface;
use Magento\Framework\Validator\ValidatorInterface;
use PHPUnit\Framework\TestCase;
use Qliro\QliroOne\Model\QliroOrder\Converter\StoreAddressRules;

/**
 * @see \Qliro\QliroOne\Model\QliroOrder\Converter\StoreAddressRules
 */
class StoreAddressRulesTest extends TestCase
{
    /**
     * A store before 2.4.8 has no address validators and accepts every value
     */
    public function testAcceptsWhenTheStoreHasNoValidator(): void
    {
        $objectManager = $this->createMock(ObjectManagerInterface::class);
        $objectManager->expects(self::never())->method('create');

        $rules = new StoreAddressRules($objectManager, ['city' => 'Magento\Customer\Model\Validator\NotOnThisVersion']);

        self::assertTrue($rules->accepts('city', 'St. Gallen'));
        self::assertTrue($rules->accepts('street', 'Storgatan 1'));
    }

    /**
     * The store's validator decides, asked with the value under the field it reads
     */
    public function testAsksTheStoreValidatorWithAFreshInstance(): void
    {
        $validator = static function (bool $valid) {
            return new class ($valid) {
                public function __construct(private bool $valid)
                {
                }

                public function isValid($value): bool
                {
                    return $value instanceof DataObject && $value->getData('city') !== null && $this->valid;
                }
            };
        };

        $objectManager = $this->createMock(ObjectManagerInterface::class);
        $objectManager->expects(self::exactly(2))
            ->method('create')
            ->with(DataObject::class)
            ->willReturnOnConsecutiveCalls($validator(false), $validator(true));

        $rules = new StoreAddressRules($objectManager, ['city' => DataObject::class]);

        self::assertFalse($rules->accepts('city', 'St. Gallen'));
        self::assertTrue($rules->accepts('city', 'St Gallen'));
    }

    /**
     * Against the Magento installed for the tests: every version with a name check refuses `/`
     */
    public function testTheInstalledNameValidatorRefusesASlash(): void
    {
        if (!class_exists('Magento\Customer\Model\Validator\Name')) {
            self::markTestSkipped('The installed Magento has no name validator');
        }

        $objectManager = $this->createMock(ObjectManagerInterface::class);
        $objectManager->method('create')->willReturnCallback(fn (string $class) => new $class());

        $rules = new StoreAddressRules($objectManager);

        self::assertFalse($rules->accepts('firstname', 'Anna/Maria'));
        self::assertTrue($rules->accepts('firstname', 'Anna Maria'));
        self::assertTrue($rules->accepts('company', 'A/S'));
    }
}
