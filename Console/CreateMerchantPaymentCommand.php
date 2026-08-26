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
    private PlaceOrders $placeOrders;

    private RecurringInfoRepositoryInterface $recurringInfoRepo;

    private OrderFactory $orderFactory;

    private OrderResourceFactory $orderResourceFactory;

    private Emulation $emulation;

    private State $appState;

    public function __construct(
        PlaceOrders $placeOrders,
        RecurringInfoRepositoryInterface $recurringInfoRepo,
        OrderFactory $orderFactory,
        OrderResourceFactory $orderResourceFactory,
        Emulation $emulation,
        State $appState,
        ?string $name = null
    ) {
        $this->placeOrders = $placeOrders;
        $this->recurringInfoRepo = $recurringInfoRepo;
        $this->orderFactory = $orderFactory;
        $this->orderResourceFactory = $orderResourceFactory;
        $this->emulation = $emulation;
        $this->appState = $appState;
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

    protected function execute(InputInterface $input, OutputInterface $output): int
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
