<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Console;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use Magento\Backend\App\Area\FrontNameResolver;
use Magento\Framework\App\ObjectManagerFactory;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\ObjectManagerInterface;
use Magento\Framework\ObjectManager\ObjectManager;
use Magento\Store\Model\StoreManager;
use Qliro\QliroOne\Model\Logger\Redactor;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Magento\Framework\App\Area;

abstract class AbstractCommand extends Command
{
    /**
     * @var ObjectManagerFactory
     */
    protected $objectManagerFactory;

    /**
     * @var ObjectManager
     */
    protected $objectManager;

    /**
     * Inject ObjectManager
     *
     * @param ObjectManagerFactory $objectManagerFactory
     */
    public function __construct(
        ObjectManagerFactory $objectManagerFactory
    ) {
        $this->objectManagerFactory = $objectManagerFactory;
        parent::__construct();
    }

    /**
     * Execute the command
     *
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        throw new LocalizedException(__('You must override the execute() method in the concrete command class.'));
    }

    /**
     * Gets initialized object manager
     *
     * @return ObjectManagerInterface
     */
    protected function getObjectManager()
    {
        if (null == $this->objectManager) {
            $area = FrontNameResolver::AREA_CODE;
            $params = $_SERVER;
            $params[StoreManager::PARAM_RUN_CODE] = 'admin';
            $params[StoreManager::PARAM_RUN_TYPE] = 'store';
            $this->objectManager = $this->objectManagerFactory->create($params);

            /** @var \Magento\Framework\App\State $appState */
            $appState = $this->objectManager->get('Magento\Framework\App\State');

            $appState->setAreaCode($area);
            $configLoader = $this->objectManager->get('Magento\Framework\ObjectManager\ConfigLoaderInterface');
            $this->objectManager->configure($configLoader->load($area));

            /** @var \Magento\Framework\App\AreaList $areaList */
            $areaList = $this->objectManager->get('Magento\Framework\App\AreaList');

            /** @var \Magento\Framework\App\Area $area */
            $area = $areaList->getArea($appState->getAreaCode());

            $area->load(Area::PART_TRANSLATE);
        }

        return $this->objectManager;
    }

    /**
     * Writes the text as it is, a % or a <tag> in it is not read as formatting
     *
     * @param OutputInterface $output
     * @param string $text
     * @return void
     */
    protected function writeText(OutputInterface $output, string $text): void
    {
        $output->writeln($text, OutputInterface::OUTPUT_RAW);
    }

    /**
     * @param array $data
     * @return string
     */
    protected function toJson(array $data): string
    {
        $json = \json_encode(
            $data,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
                | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR
        );

        return $json === false ? \json_last_error_msg() : $json;
    }

    /**
     * @param OutputInterface $output
     * @param \Throwable $exception
     * @return void
     */
    protected function writeApiFailure(OutputInterface $output, \Throwable $exception): void
    {
        $this->writeText($output, 'Merchant API exception:');
        $this->writeFailure($output, $exception);
    }

    /**
     * Writes the messages down the chain, and the HTTP exchange with Qliro when there is one
     *
     * The client wraps Guzzle's exception in a TerminalException and that in a ClientException,
     * so the exchange is two levels down. Credentials and personal data are masked as in the log.
     *
     * @param OutputInterface $output
     * @param \Throwable $exception
     * @return void
     */
    protected function writeFailure(OutputInterface $output, \Throwable $exception): void
    {
        /** @var Redactor $redactor */
        $redactor = $this->getObjectManager()->get(Redactor::class);

        $exchange = null;
        $messages = [];
        for ($current = $exception; $current !== null && $exchange === null; $current = $current->getPrevious()) {
            if (!in_array($current->getMessage(), $messages, true)) {
                $messages[] = $current->getMessage();
                $this->writeText($output, $redactor->redactMessage($current->getMessage(), true));
            }
            if ($current instanceof RequestException || $current instanceof ConnectException) {
                $exchange = $current;
            }
        }

        if ($exchange === null) {
            return;
        }

        // A cast rewinds the body, the client has already read it to look for Qliro's error code
        $request = $exchange->getRequest();
        $dump = [
            'request.uri' => (string) $request->getUri(),
            'request.method' => $request->getMethod(),
            'request.headers' => $request->getHeaders(),
            'request.body' => (string) $request->getBody(),
        ];

        $response = $exchange instanceof RequestException ? $exchange->getResponse() : null;
        if ($response !== null) {
            $dump += [
                'response.status' => $response->getStatusCode(),
                'response.headers' => $response->getHeaders(),
                'response.body' => (string) $response->getBody(),
            ];
        }

        $this->writeText($output, $this->toJson($redactor->redactContext($dump, true)));
    }
}
