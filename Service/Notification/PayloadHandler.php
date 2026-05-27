<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Service\Notification;

use Magento\Framework\App\Request\Http;
use Magento\Framework\Controller\Result\Json as JsonResult;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Serialize\Serializer\Json;
use Qliro\QliroOne\Model\Logger\Manager as LogManager;

/**
 * Handles reading and sending JSON payloads for Qliro webhook callbacks.
 */
readonly class PayloadHandler
{
    /**
     * Class constructor
     *
     * @param Json            $json
     * @param LogManager      $logManager
     * @param ResultFactory   $resultFactory
     */
    public function __construct(
        private Json          $json,
        private LogManager    $logManager,
        private ResultFactory $resultFactory
    ) {
    }

    /**
     * Read and log an incoming JSON webhook payload from the request body.
     *
     * @param Http        $request
     * @param string|null $loggerMark
     * @param array|null  $removeLogging Keys whose values should be redacted in the log
     * @return array
     */
    public function readPayload(Http $request, ?string $loggerMark = null, ?array $removeLogging = null): array
    {
        $content = $request->getContent();
        $this->logManager->setMark($loggerMark);
        $payload = [];

        $data = [
            'uri'    => $request->getRequestUri(),
            'method' => $request->getMethod(),
        ];

        try {
            $payload = $content ? $this->json->unserialize($content) : [];
            if (!empty($payload['MerchantReference'])) {
                $this->logManager->setMerchantReference($payload['MerchantReference']);
            }

            if ($payload) {
                $data['body'] = $this->redactKeys($payload, $removeLogging);
            }
        } catch (\InvalidArgumentException $exception) {
            $data['raw_body'] = $content;
            $data['exception'] = $exception->getMessage();
        }

        $this->logManager->debug(
            '<<< JSON payload has been received and processed.',
            ['extra' => ['payload' => $data]]
        );

        $this->logManager->setMark(null);

        return $payload ?? [];
    }

    /**
     * Log and send a JSON response payload.
     *
     * @param string|array  $payload
     * @param int           $resultCode
     * @param JsonResult|null $resultJson
     * @param string|null   $loggerMark
     * @return JsonResult
     */
    public function sendPayload(
        string|array $payload,
        int $resultCode = 200,
        ?JsonResult $resultJson = null,
        ?string $loggerMark = null
    ): JsonResult {
        if (!($resultJson instanceof JsonResult)) {
            /** @var JsonResult $resultJson */
            $resultJson = $this->resultFactory->create(ResultFactory::TYPE_JSON);
        }

        $data = ['status_code' => $resultCode];

        $this->logManager->setMark($loggerMark);
        $resultJson->setHttpResponseCode($resultCode);

        if (is_string($payload)) {
            try {
                $payload = $this->json->unserialize($payload);
                $data['payload'] = $payload;
            } catch (\InvalidArgumentException $exception) {
                $data['exception'] = $exception->getMessage();
            }
        } else {
            $data['payload'] = $payload;
        }

        $this->logManager->debug('>>> Payload was prepared and sent in JSON response.', ['extra' => $data]);
        $this->logManager->setMark(null);

        $resultJson->setData($payload);

        return $resultJson;
    }

    /**
     * Replace specified key values with '<removed>' before logging.
     *
     * @param array      $data
     * @param array|null $keys
     * @return array
     */
    private function redactKeys(array $data, ?array $keys): array
    {
        if ($keys === null) {
            return $data;
        }

        foreach ($keys as $key) {
            if (isset($data[$key])) {
                $data[$key] = '<removed>';
            }
        }

        return $data;
    }
}
