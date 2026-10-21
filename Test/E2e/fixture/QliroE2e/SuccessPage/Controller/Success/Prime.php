<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace QliroE2e\SuccessPage\Controller\Success;

use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\App\Request\Http;
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
    private const SECRET_FILE = 'qliro-e2e-secret';

    public function __construct(
        private readonly Http $request,
        private readonly JsonFactory $jsonFactory,
        private readonly OrderFactory $orderFactory,
        private readonly Session $successSession,
        private readonly State $appState,
        private readonly Filesystem $filesystem
    ) {
    }

    public function execute()
    {
        // Any order onto anyone's session: a test store only, and only for the run that left the secret
        if ($this->appState->getMode() !== State::MODE_DEVELOPER || !$this->isTheRunsSecret()) {
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

    /**
     * Developer mode is common on a staging store anyone can reach, so the spec writes a secret of
     * its own to var/ and sends it along. A store nobody ran the spec against has no file.
     */
    private function isTheRunsSecret(): bool
    {
        $var = $this->filesystem->getDirectoryRead(DirectoryList::VAR_DIR);
        $secret = $var->isFile(self::SECRET_FILE) ? trim($var->readFile(self::SECRET_FILE)) : '';
        $given = (string)$this->request->getHeader('X-Qliro-E2e-Secret');

        return $secret !== '' && hash_equals($secret, $given);
    }
}
