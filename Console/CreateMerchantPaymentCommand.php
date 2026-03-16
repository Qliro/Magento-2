<?php declare(strict_types=1);

namespace Qliro\QliroOne\Console;

use Magento\Framework\App\State;
use Magento\Framework\App\Area;
use Magento\Sales\Model\OrderFactory;
use Magento\Sales\Model\ResourceModel\OrderFactory as OrderResourceFactory;
use Magento\Store\Model\App\Emulation;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Qliro\QliroOne\Service\RecurringPayments\PlaceOrders;
use Qliro\QliroOne\Api\RecurringInfoRepositoryInterface;

/**
 * Create Merchant Payment based on a placed Subscripton Order
 */
class CreateMerchantPaymentCommand extends Command
{
    /**
     * Class constructor
     *
     * @param PlaceOrders $placeOrders
     * @param RecurringInfoRepositoryInterface $recurringInfoRepo
     * @param OrderFactory $orderFactory
     * @param OrderResourceFactory $orderResourceFactory
     * @param Emulation $emulation
     * @param State $appState
     * @param string|null $name
     */
    public function __construct(
        private readonly PlaceOrders $placeOrders,
        private readonly RecurringInfoRepositoryInterface $recurringInfoRepo,
        private readonly OrderFactory $orderFactory,
        private readonly OrderResourceFactory $orderResourceFactory,
        private readonly Emulation $emulation,
        private readonly State $appState,
        ?string $name = null
    ) {
        parent::__construct($name);
    }

    /**
     * @inheritDoc
     */
    protected function configure()
    {
        $this->setName('qliroone:merchantpayment:create');
        $this->setDescription('Create a Merchant Payment based on a placed Subscription Order');
        $this->addArgument('order_id', InputArgument::REQUIRED, 'Id of Original Order of Subscription');

        parent::configure();
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $orderId = (int)$input->getArgument('order_id');
        $recurringInfo = $this->recurringInfoRepo->getByOriginalOrderId($orderId);
        $order = $this->orderFactory->create();
        $this->orderResourceFactory->create()->load($order, $recurringInfo->getOriginalOrderId());

        $this->appState->setAreaCode(Area::AREA_FRONTEND);
        $this->emulation->startEnvironmentEmulation($order->getStoreId(), Area::AREA_FRONTEND, true);
        $this->placeOrders->placeRecurringOrders([$recurringInfo]);
        $this->emulation->stopEnvironmentEmulation();
        return 0;
    }
}
