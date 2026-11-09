<?php declare(strict_types=1);

namespace Qliro\QliroOne\Cron;

use Qliro\QliroOne\Api\RecurringInfoRepositoryInterface;
use Qliro\QliroOne\Model\ResourceModel\RecurringInfo as RecurringInfoResource;
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
    private StoreManagerInterface $storeManager;

    private Config $qliroConfig;

    private PlaceOrders $placeOrder;

    private RecurringInfoRepositoryInterface $recurringInfoRepo;

    private EmulationFactory $emulationFactory;

    private RecurringInfoResource $recurringInfoResource;

    public function __construct(
        StoreManagerInterface $storeManager,
        Config $qliroConfig,
        PlaceOrders $placeOrder,
        RecurringInfoRepositoryInterface $recurringInfoRepo,
        EmulationFactory $emulationFactory,
        RecurringInfoResource $recurringInfoResource
    ) {
        $this->storeManager = $storeManager;
        $this->qliroConfig = $qliroConfig;
        $this->placeOrder = $placeOrder;
        $this->recurringInfoRepo = $recurringInfoRepo;
        $this->emulationFactory = $emulationFactory;
        $this->recurringInfoResource = $recurringInfoResource;
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
                $this->recurringInfoResource->updateNextOrderDate(
                    (int)$recurringInfo->getId(),
                    $recurringInfo->getNextOrderDate()
                );
            }
            // End store emulation
            $emulation->stopEnvironmentEmulation();
        }
    }
}
