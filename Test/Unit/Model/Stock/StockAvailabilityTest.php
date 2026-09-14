<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Test\Unit\Model\Stock;

use Magento\Framework\Module\Manager as ModuleManager;
use Magento\Framework\ObjectManagerInterface;
use PHPUnit\Framework\TestCase;
use Qliro\QliroOne\Model\Logger\Manager as LogManager;
use Qliro\QliroOne\Model\Stock\LegacyStockAvailability;
use Qliro\QliroOne\Model\Stock\MsiStockAvailability;
use Qliro\QliroOne\Model\Stock\StockAvailability;

/**
 * @see \Qliro\QliroOne\Model\Stock\StockAvailability
 */
class StockAvailabilityTest extends TestCase
{
    /**
     * With the inventory modules installed, MSI is the store's inventory and MSI answers.
     */
    public function testAsksMsiWhenTheInventoryModulesAreEnabled(): void
    {
        $msi = $this->createMock(MsiStockAvailability::class);
        $msi->method('areSalable')->willReturn(['sku-1' => false]);

        $legacy = $this->createMock(LegacyStockAvailability::class);
        $legacy->expects(self::never())->method('areSalable');

        $availability = $this->buildAvailability(true, $legacy, $msi);

        self::assertSame(['sku-1' => false], $availability->areSalable($this->line('sku-1', 1.0), 1));
    }

    /**
     * A store without the inventory modules keeps the legacy answer, and nothing reaches for a
     * class that is not installed.
     */
    public function testFallsBackToTheLegacyReaderWithoutTheInventoryModules(): void
    {
        $legacy = $this->createMock(LegacyStockAvailability::class);
        $legacy->method('areSalable')->willReturn(['sku-1' => false]);

        $objectManager = $this->createMock(ObjectManagerInterface::class);
        $objectManager->expects(self::never())->method('get');

        $moduleManager = $this->createMock(ModuleManager::class);
        $moduleManager->method('isEnabled')->willReturn(false);

        $availability = new StockAvailability(
            $objectManager,
            $moduleManager,
            $legacy,
            $this->createMock(LogManager::class)
        );

        self::assertSame(['sku-1' => false], $availability->areSalable($this->line('sku-1', 1.0), 1));
    }

    /**
     * One inventory module short of a full set is not an MSI store.
     */
    public function testTreatsAPartialInventoryInstallAsLegacy(): void
    {
        $legacy = $this->createMock(LegacyStockAvailability::class);
        $legacy->method('areSalable')->willReturn(['sku-1' => true]);

        $objectManager = $this->createMock(ObjectManagerInterface::class);
        $objectManager->expects(self::never())->method('get');

        $moduleManager = $this->createMock(ModuleManager::class);
        $moduleManager->method('isEnabled')->willReturnCallback(
            static fn(string $module): bool => $module !== 'Magento_InventoryConfiguration'
        );

        $availability = new StockAvailability(
            $objectManager,
            $moduleManager,
            $legacy,
            $this->createMock(LogManager::class)
        );

        self::assertSame(['sku-1' => true], $availability->areSalable($this->line('sku-1', 1.0), 1));
    }

    /**
     * A reader that throws must not turn into a decline. Magento checks the stock for real when it
     * places the order, this reader is only asked ahead of that.
     */
    public function testDoesNotRefuseALineWhenTheReaderThrows(): void
    {
        $msi = $this->createMock(MsiStockAvailability::class);
        $msi->method('areSalable')->willThrowException(new \RuntimeException('no such stock'));

        $logManager = $this->createMock(LogManager::class);
        $logManager->expects(self::once())->method('error');

        $availability = $this->buildAvailability(
            true,
            $this->createMock(LegacyStockAvailability::class),
            $msi,
            $logManager
        );

        self::assertSame(['sku-1' => true], $availability->areSalable($this->line('sku-1', 1.0), 1));
    }

    /**
     * A sku the reader left out of its answer is not refused either.
     */
    public function testDoesNotRefuseASkuTheReaderLeftUnanswered(): void
    {
        $msi = $this->createMock(MsiStockAvailability::class);
        $msi->method('areSalable')->willReturn(['sku-1' => false]);

        $availability = $this->buildAvailability(
            true,
            $this->createMock(LegacyStockAvailability::class),
            $msi
        );

        self::assertSame(
            ['sku-1' => false, 'sku-2' => true],
            $availability->areSalable($this->line('sku-1', 1.0) + $this->line('sku-2', 1.0), 1)
        );
    }

    /**
     * One line asked about on its own is asked about as it stands.
     */
    public function testAsksAboutOneLineOnItsOwn(): void
    {
        $msi = $this->createMock(MsiStockAvailability::class);
        $msi->expects(self::once())->method('areSalable')->willReturn(['sku-2' => false]);

        $availability = $this->buildAvailability(
            true,
            $this->createMock(LegacyStockAvailability::class),
            $msi
        );

        self::assertFalse($availability->isSalable('sku-2', 1.0, 'simple', 1));
    }

    /**
     * One validate callback asks about a cart up to four times, once for its own decision and once
     * for each handler that builds a line of it. The same question gets the same answer.
     */
    public function testAnswersAQuestionItHasAlreadyBeenAsked(): void
    {
        $msi = $this->createMock(MsiStockAvailability::class);
        $msi->expects(self::once())->method('areSalable')->willReturn(['sku-1' => false]);

        $availability = $this->buildAvailability(
            true,
            $this->createMock(LegacyStockAvailability::class),
            $msi
        );

        self::assertSame(['sku-1' => false], $availability->areSalable($this->line('sku-1', 1.0), 1));
        self::assertSame(['sku-1' => false], $availability->areSalable($this->line('sku-1', 1.0), 1));
    }

    /**
     * A cart that says something else is another question, and is asked.
     */
    public function testAsksAgainWhenTheQuestionChanged(): void
    {
        $msi = $this->createMock(MsiStockAvailability::class);
        $msi->expects(self::exactly(3))->method('areSalable')->willReturn(['sku-1' => true]);

        $availability = $this->buildAvailability(
            true,
            $this->createMock(LegacyStockAvailability::class),
            $msi
        );

        $availability->areSalable($this->line('sku-1', 1.0), 1);
        $availability->areSalable($this->line('sku-1', 2.0), 1);
        $availability->areSalable($this->line('sku-1', 2.0), 2);
    }

    /**
     * The reader is built once, whatever the store asks about afterwards.
     */
    public function testBuildsTheMsiReaderOnce(): void
    {
        $msi = $this->createMock(MsiStockAvailability::class);
        $msi->method('areSalable')->willReturn(['sku-1' => true]);

        $objectManager = $this->createMock(ObjectManagerInterface::class);
        $objectManager->expects(self::once())->method('get')->with(MsiStockAvailability::class)->willReturn($msi);

        $moduleManager = $this->createMock(ModuleManager::class);
        $moduleManager->method('isEnabled')->willReturn(true);

        $availability = new StockAvailability(
            $objectManager,
            $moduleManager,
            $this->createMock(LegacyStockAvailability::class),
            $this->createMock(LogManager::class)
        );

        $availability->isSalable('sku-1', 1.0, 'simple', 1);
        $availability->isSalable('sku-2', 1.0, 'simple', 1);
    }

    /**
     * @param string $sku
     * @param float $qty
     * @return array<string, array{qty: float, type: string}>
     */
    private function line(string $sku, float $qty): array
    {
        return [$sku => ['qty' => $qty, 'type' => 'simple']];
    }

    private function buildAvailability(
        bool $inventoryEnabled,
        LegacyStockAvailability $legacy,
        MsiStockAvailability $msi,
        ?LogManager $logManager = null
    ): StockAvailability {
        $objectManager = $this->createMock(ObjectManagerInterface::class);
        $objectManager->method('get')->with(MsiStockAvailability::class)->willReturn($msi);

        $moduleManager = $this->createMock(ModuleManager::class);
        $moduleManager->method('isEnabled')->willReturn($inventoryEnabled);

        return new StockAvailability(
            $objectManager,
            $moduleManager,
            $legacy,
            $logManager ?? $this->createMock(LogManager::class)
        );
    }
}
