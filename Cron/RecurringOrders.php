<?php declare(strict_types=1);

namespace Qliro\QliroOne\Cron;

use Qliro\QliroOne\Api\RecurringInfoRepositoryInterface;
use Qliro\QliroOne\Service\RecurringPayments\PlaceOrders;
use Qliro\QliroOne\Model\Config;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Store\Model\App\EmulationFactory;
use Magento\Framework\App\Area;

/**
 * Cron service for placing recurring orders
 */
class RecurringOrders
{
    /**
     * Class constructor
     *
     * @param \Magento\Store\Model\StoreManagerInterface $storeManager
     * @param \Qliro\QliroOne\Model\Config $qliroConfig
     * @param \Qliro\QliroOne\Service\RecurringPayments\PlaceOrders $placeOrder
     * @param \Qliro\QliroOne\Api\RecurringInfoRepositoryInterface $recurringInfoRepo
     * @param \Magento\Store\Model\App\EmulationFactory $emulationFactory
     */
    public function __construct(
        private readonly StoreManagerInterface $storeManager,
        private readonly Config $qliroConfig,
        private readonly PlaceOrders $placeOrder,
        private readonly RecurringInfoRepositoryInterface $recurringInfoRepo,
        private readonly EmulationFactory $emulationFactory
    ) {
    }

    /**
     * Places recurring orders for today
     *
     * @return void
     */
    public function placeOrders(): void
    {
        $stores = $this->storeManager->getStores();

        foreach ($stores as $store) {
            $storeId = (int)$store->getId();

            if (!$this->qliroConfig->isUseRecurring($storeId)) {
                continue;
            }

            $recurringInfos = $this->recurringInfoRepo->getByTodaysDate($storeId);
            if (count($recurringInfos) < 1) {
                continue;
            }

            // Start store emulation, then place orders for that store
            $emulation = $this->emulationFactory->create();
            $emulation->startEnvironmentEmulation($storeId, Area::AREA_FRONTEND, true);
            $this->placeOrder->placeRecurringOrders($recurringInfos);
            foreach ($recurringInfos as $recurringInfo) {
                $this->recurringInfoRepo->save($recurringInfo);
            }
            // End store emulation
            $emulation->stopEnvironmentEmulation();
        }
    }
}
