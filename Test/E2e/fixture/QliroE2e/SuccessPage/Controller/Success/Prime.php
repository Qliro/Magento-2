<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace QliroE2e\SuccessPage\Controller\Success;

use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\State;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Filesystem;
use Magento\Sales\Model\OrderFactory;
use Qliro\QliroOne\Model\Success\Session;

/**
 * The last step of PollPending, for a seeded order: puts it on the success session of the caller
 */
class Prime implements HttpGetActionInterface
{
    public function __construct(
        private readonly RequestInterface $request,
        private readonly JsonFactory $jsonFactory,
        private readonly OrderFactory $orderFactory,
        private readonly Session $successSession,
        private readonly State $appState,
        private readonly Filesystem $filesystem
    ) {
    }

    public function execute()
    {
        // Any order onto anyone's session: a test store only
        if ($this->appState->getMode() !== State::MODE_DEVELOPER) {
            return $this->jsonFactory->create()->setHttpResponseCode(404)->setData(['primed' => false]);
        }

        $order = $this->orderFactory->create()->loadByIncrementId((string)$this->request->getParam('order'));

        if ($order->getId()) {
            $this->successSession->save('<p>qliro-e2e-snippet</p>', $order);
            // A test starts its order's record of success events afresh
            $this->filesystem->getDirectoryWrite(DirectoryList::VAR_DIR)
                ->delete('qliro-e2e/success-events-' . (int)$order->getId() . '.log');
        }

        return $this->jsonFactory->create()->setData(['primed' => (bool)$order->getId()]);
    }
}
