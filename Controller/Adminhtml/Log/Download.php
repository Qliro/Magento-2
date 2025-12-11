<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Controller\Adminhtml\Log;

use Magento\Framework\App\ActionInterface;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\App\RequestInterface as Request;
use Magento\Framework\App\Response\Http\FileFactory;
use Magento\Framework\App\ResponseInterface as Response;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Exception\FileSystemException;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Message\ManagerInterface as MessageManager;
use Qliro\QliroOne\Model\Config\Exporter;

/**
 * Class Download
 */
class Download implements ActionInterface
{
    /**
     * Class constructor
     *
     * @param Request                   $request
     * @param FileFactory               $fileFactory
     * @param MessageManager            $messageManager
     * @param Exporter                  $exporter
     */
    public function __construct(
        private readonly Request        $request,
        private readonly FileFactory    $fileFactory,
        private readonly MessageManager $messageManager,
        private readonly Exporter       $exporter
    ) {
    }

    /**
     * @return Response|Redirect
     * @throws FileSystemException
     */
    public function execute(): Response|Redirect
    {
        $param = $this->request->getParam('type');

        if ($param == 'logs') {
            try {
                $fileName = $this->exporter->getLogFiles();
            } catch (LocalizedException $e) {
                $this->messageManager->addErrorMessage($e->getMessage());
            } catch (\Exception $e) {
                $this->messageManager->addErrorMessage(
                    __('An error occurred while trying to download the logs. Please try again later.'))
                ;
            }
        } elseif ($param == 'configs') {
            $fileName = $this->exporter->getConfigs();
        }

        return $this->fileFactory->create(
            $fileName,
            [
                'type' => 'filename',
                'value' => sprintf('%s/%s', DirectoryList::LOG, $fileName),
                'rm' => true
            ],
            DirectoryList::VAR_DIR
        );
    }
}
