<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Test\Unit\Service\RecurringPayments;

use Magento\Framework\Serialize\Serializer\Json;
use PHPUnit\Framework\TestCase;
use Qliro\QliroOne\Model\Data\PaymentRecurringInfoFactory;
use Qliro\QliroOne\Model\RecurringInfoFactory;
use Qliro\QliroOne\Model\RecurringInfoRepository;
use Qliro\QliroOne\Service\RecurringPayments\Data;

/**
 * @see \Qliro\QliroOne\Service\RecurringPayments\Data::formatRecurringFrequencyOptionsJson()
 */
class DataFrequencyOptionsTest extends TestCase
{
    private Data $data;

    protected function setUp(): void
    {
        $this->data = new Data(
            $this->createMock(PaymentRecurringInfoFactory::class),
            $this->createMock(RecurringInfoFactory::class),
            $this->createMock(RecurringInfoRepository::class),
            new Json()
        );
    }

    public function testItFormatsTheSavedOptions(): void
    {
        $json = '{"__empty":"","_1":{"label":"Monthly","frequency":"1","time_unit":"month"},'
            . '"_2":{"label":"Weekly","frequency":"1","time_unit":"week"}}';

        self::assertSame(
            [
                ['label' => 'Monthly', 'value' => '1|month', 'selected' => false],
                ['label' => 'Weekly', 'value' => '1|week', 'selected' => true],
            ],
            $this->data->formatRecurringFrequencyOptionsJson($json, '1|week')
        );
    }

    /**
     * @dataProvider noOptionsProvider
     */
    public function testItGivesNoOptionsForAnEmptyOrInvalidValue(string $json): void
    {
        self::assertSame([], $this->data->formatRecurringFrequencyOptionsJson($json));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function noOptionsProvider(): array
    {
        return [
            'empty string' => [''],
            'empty list' => ['[]'],
            'only the empty row' => ['{"__empty":""}'],
            'not JSON' => ['{broken'],
            'JSON null' => ['null'],
            'a scalar' => ['"monthly"'],
            'a row without its keys' => ['{"_1":{"label":"Monthly"}}'],
            'a row that is not a list' => ['{"_1":"Monthly"}'],
        ];
    }
}
