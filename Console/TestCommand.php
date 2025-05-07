<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */

namespace Qliro\QliroOne\Console;

use Magento\Framework\App\ObjectManagerFactory;
use Magento\Store\Model\Store;
use Qliro\QliroOne\Model\Api\ServiceFactory;
use Qliro\QliroOne\Api\Data\QliroOrderCreateRequestInterface;
use Qliro\QliroOne\Model\ContainerMapper;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Qliro\QliroOne\Model\Api\Service;
use Qliro\QliroOne\Model\ContainerMapperFactory;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Class TestCommand
 * Test apis.
 */
class TestCommand extends AbstractCommand
{
    const COMMAND_RUN = 'qliroone:api:test';

    /**
     * @var ServiceFactory
     */
    private ServiceFactory $serviceFactory;

    /**
     * @var ContainerMapperFactory
     */
    private ContainerMapperFactory $containerMapperFactory;

    /**
     * @var QliroOrderCreateRequestInterface
     */
    private QliroOrderCreateRequestInterface $orderCreateRequest;
    private StoreManagerInterface $storeManager;

    /**
     * @param ObjectManagerFactory $objectManagerFactory
     * @param ServiceFactory $serviceFactory
     * @param ContainerMapperFactory $containerMapperFactory
     * @param QliroOrderCreateRequestInterface $orderCreateRequest
     * @param StoreManagerInterface $storeManager
     */
    public function __construct(
        ObjectManagerFactory             $objectManagerFactory,
        ServiceFactory                   $serviceFactory,
        ContainerMapperFactory           $containerMapperFactory,
        QliroOrderCreateRequestInterface $orderCreateRequest,
        StoreManagerInterface $storeManager
    )
    {
        parent::__construct($objectManagerFactory);
        $this->serviceFactory = $serviceFactory;
        $this->containerMapperFactory = $containerMapperFactory;
        $this->orderCreateRequest = $orderCreateRequest;
        $this->storeManager = $storeManager;
    }

    /**
     * Configure the CLI command
     */
    protected function configure()
    {
        parent::configure();

        $this->setName(self::COMMAND_RUN);
        $this->setDescription('Verify QliroOne API');
    }

    /**
     * Initialize the command
     *
     * @param InputInterface $input
     * @param OutputInterface $output
     */
    protected function initialize(InputInterface $input, OutputInterface $output)
    {
        parent::initialize($input, $output);
    }

    /**
     * Execute the command
     *
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int|null
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $output->writeln('<comment>Test</comment>');

        /** @var Service $service */
        $service = $this->serviceFactory->create();

        /** @var ContainerMapper $containerMapper */
        $containerMapper = $this->containerMapperFactory->create();

        $baseUrl =  $this->storeManager->getStore(Store::DEFAULT_STORE_ID)->getBaseUrl();

        $payload = [
            "MerchantReference" => bin2hex(random_bytes(10)),
            "Currency" => "SEK",
            "Country" => "SE",
            "Language" => "sv-se",
            "MerchantConfirmationUrl" => $baseUrl . "checkout/qliro/saveOrder?XDEBUG_SESSION_START=PHPSTORM",
            "MerchantTermsUrl" => $baseUrl . "terms",
            "MerchantOrderValidationUrl" => $baseUrl . "checkout/qliro/validate?XDEBUG_SESSION_START=PHPSTORM",
            "MerchantOrderAvailableShippingMethodsUrl" => $baseUrl . "checkout/qliro/shipping?XDEBUG_SESSION_START=PHPSTORM",
            "MerchantCheckoutStatusPushUrl" => $baseUrl . "qliroapi/order/index/order_id/211300540/token/NmIwMTNmY2Q0YzYwOWE2ZjQ3MzVkMDcyNDMzNTg1ZjMwZDkyMmI1NDhlNDFhN2Q1YWJiZWI1MmVhZWNiYWQwYQ==/",
            "MerchantOrderManagementStatusPushUrl" => $baseUrl . "qliroapi/notification?XDEBUG_SESSION_START=PHPSTORM",
            "PrimaryColor" => "#000000",
            "CallToActionColor" => "#0000FF",
            "BackgroundColor" => "#FFFFFF",
            "AskForNewsletterSignup" => true,
            "OrderItems" => [
                [
                    "MerchantReference" => "S001",
                    "Description" => "Test product - Simple",
                    "Type" => "Product",
                    "Quantity" => 1,
                    "PricePerItemIncVat" => "100.00",
                    "PricePerItemExVat" => "80.00"
                ]
            ],
        ];

        $containerMapper->fromArray($payload, $this->orderCreateRequest);

        try {
            $response = $service->post('checkout/merchantapi/orders', $payload);

            print_r([
                'response' => $response,
            ]);

            $output->writeln('<comment>API connection successful</comment>');
        } catch (\GuzzleHttp\Exception\RequestException $exception) {
            print_r([
                'request.uri' => $exception->getRequest()->getUri(),
                'request.method' => $exception->getRequest()->getMethod(),
                'request.headers' => $exception->getRequest()->getHeaders(),
                'request.body' => $exception->getRequest()->getBody()->getContents(),
                'response.status' => $exception->getResponse()->getStatusCode(),
                'response.headers' => $exception->getResponse()->getHeaders(),
                'response.body' => $exception->getResponse()->getBody()->getContents(),
            ]);
        }

        return 0;
    }
}
