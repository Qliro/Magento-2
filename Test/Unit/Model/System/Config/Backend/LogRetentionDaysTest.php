<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Test\Unit\Model\System\Config\Backend;

use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Event\ManagerInterface as EventManager;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Model\Context;
use Magento\Framework\Registry;
use PHPUnit\Framework\TestCase;
use Qliro\QliroOne\Model\Config;
use Qliro\QliroOne\Model\System\Config\Backend\LogRetentionDays;

/**
 * @see \Qliro\QliroOne\Model\System\Config\Backend\LogRetentionDays
 */
class LogRetentionDaysTest extends TestCase
{
    /**
     * The admin form validates in JavaScript only, and `config:set` validates through the backend
     * model alone, so the refusal has to live here to reach both.
     *
     * @dataProvider refusedValueProvider
     */
    public function testRefusesAnythingButAWholeNumberOfDays(string $value): void
    {
        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('whole number of days');

        $this->buildBackend()->setValue($value)->beforeSave();
    }

    /**
     * @return array<string, array{string}>
     */
    public static function refusedValueProvider(): array
    {
        return [
            'blank' => [''],
            'negative' => ['-1'],
            'fraction' => ['1.5'],
            'word' => ['week'],
            'a value no date can hold' => [(string)(Config::MAX_LOG_RETENTION_DAYS + 1)],
        ];
    }

    /**
     * @dataProvider acceptedValueProvider
     */
    public function testAcceptsAWholeNumberOfDaysAndStoresItTrimmed(string $value, string $stored): void
    {
        $backend = $this->buildBackend()->setValue($value);

        $backend->beforeSave();

        self::assertSame($stored, $backend->getValue());
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function acceptedValueProvider(): array
    {
        return [
            'zero keeps everything' => ['0', '0'],
            'the default' => ['30', '30'],
            'stray whitespace' => [' 90 ', '90'],
            'the longest window' => [(string)Config::MAX_LOG_RETENTION_DAYS, (string)Config::MAX_LOG_RETENTION_DAYS],
        ];
    }

    /**
     * The pruner reads the default scope, so a value saved on a website or a store view would be
     * accepted and then never read. `config:set` reaches those scopes whatever the field declares.
     */
    public function testRefusesAScopeThePrunerWouldNeverRead(): void
    {
        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('default scope');

        $this->buildBackend()->setScope('websites')->setValue('7')->beforeSave();
    }

    /**
     * @dataProvider readableScopeProvider
     */
    public function testAcceptsTheScopeThePrunerReads(?string $scope): void
    {
        $backend = $this->buildBackend()->setValue('7');

        if ($scope !== null) {
            $backend->setScope($scope);
        }

        $backend->beforeSave();

        self::assertSame('7', $backend->getValue());
    }

    /**
     * @return array<string, array{string|null}>
     */
    public static function readableScopeProvider(): array
    {
        return [
            'the default scope' => [ScopeConfigInterface::SCOPE_TYPE_DEFAULT],
            'no scope at all, a programmatic save' => [null],
        ];
    }

    private function buildBackend(): LogRetentionDays
    {
        $context = $this->createMock(Context::class);
        $context->method('getEventDispatcher')->willReturn($this->createMock(EventManager::class));

        return new LogRetentionDays(
            $context,
            $this->createMock(Registry::class),
            $this->createMock(ScopeConfigInterface::class),
            $this->createMock(TypeListInterface::class),
            null,
            null,
            [],
            new Config(
                $this->createMock(\Magento\Payment\Model\Method\Adapter::class),
                $this->createMock(ScopeConfigInterface::class),
                $this->createMock(\Magento\Framework\Serialize\Serializer\Json::class),
                $this->createMock(\Magento\Directory\Helper\Data::class),
                $this->createMock(\Magento\Directory\Model\ResourceModel\Country\CollectionFactory::class)
            )
        );
    }
}
