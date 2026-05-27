<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Model\Api;

use Magento\Framework\Serialize\Serializer\Json;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Http\Message\ResponseInterface;
use GuzzleHttp\Client;
use GuzzleHttp\RequestOptions;
use GuzzleHttp\TransferStats;
use Qliro\QliroOne\Api\ApiServiceInterface;
use Qliro\QliroOne\Model\Config;
use Qliro\QliroOne\Model\Exception\TerminalException;
use Qliro\QliroOne\Model\Logger\Manager;

/**
 * QliroOne API Service implementation
 */
class Service implements ApiServiceInterface
{
    const string METHOD_GET = 'GET';
    const string METHOD_POST = 'POST';
    const string METHOD_PUT = 'PUT';

    const string HEADER_CONTENT_TYPE = 'Content-Type';
    const string HEADER_CONTENT_TYPE_JSON = 'application/json';
    const string AUTHENTICATION_PREFIX = 'Qliro';
    const string HEADER_AUTHENTICATION = 'Authorization';
    const string QLIRO_SANDBOX_API_URL = 'https://pago.qit.nu';
    const string QLIRO_PROD_API_URL = 'https://payments.qit.nu';

    /**
     * @var float
     */
    private float $duration;

    /**
     * Class constructor
     *
     * @param Config             $config
     * @param Client             $client
     * @param Json               $json
     * @param Manager            $logManager
     */
    public function __construct(
        private readonly Config  $config,
        private readonly Client  $client,
        private readonly Json    $json,
        private readonly Manager $logManager
    ) {
    }

    /**
     * @inheirtDoc
     */
    public function get(string $endpoint, array $data = [], int|string|null $storeId = null): array
    {
        $this->applyParams($endpoint, $data);

        return $this->call(self::METHOD_GET, $endpoint, $data, $storeId);
    }

    /**
     * @inheirtDoc
     */
    public function post(string $endpoint, array $data = [], $storeId = null): array
    {
        return $this->call(self::METHOD_POST, $endpoint, $data, $storeId);
    }

    /**
     * @inheirtDoc
     * @throws TerminalException|GuzzleException
     */
    public function put(string $endpoint, $data = [], $storeId = null): array
    {
        $this->applyParams($endpoint, $data);

        return $this->call(self::METHOD_PUT, $endpoint, $data, $storeId);
    }

    /**
     * Replace all placeholders within the endpoint from the $params array
     *
     * @param string $endpoint
     * @param array $params
     *
     * @return void
     */
    private function applyParams(string &$endpoint, array &$params): void
    {
        foreach ($params as $key => $value) {
            if (!is_scalar($value)) {
                continue;
            }
            $modifiedEndpoint = str_replace('{' . $key . '}', (string)$value, (string)$endpoint);

            if ($modifiedEndpoint !== $endpoint) {
                unset($params[$key]);
                $endpoint = $modifiedEndpoint;
            }
        }

        $endpoint = preg_replace('/\{[^}]+\}/', '*', $endpoint);
    }

    /**
     * Perform an API call
     *
     * @param string $method
     * @param string $endpoint
     * @param array $body
     * @param int|string|null $storeId
     * @return array
     * @throws GuzzleException
     * @throws TerminalException
     */
    private function call(string $method, string $endpoint, array $body = [], int|string|null $storeId = null): array
    {
        $this->logManager->setMark('REST API');

        if ($method === self::METHOD_GET) {
            $payload = '';
            $options[RequestOptions::QUERY] = $body;
        } else {
            if (empty($body['MerchantApiKey'])) {
                $body['MerchantApiKey'] = $this->config->getMerchantApiKey($storeId);
            }
            $payload = $this->json->serialize($body);
            $options[RequestOptions::BODY] = $payload;
        }

        $headers = [
            self::HEADER_CONTENT_TYPE => self::HEADER_CONTENT_TYPE_JSON,
            self::HEADER_AUTHENTICATION => $this->getAuthenticationToken($payload, $method, $storeId)
        ];

        $options[RequestOptions::HEADERS] = $headers;
        $options[RequestOptions::ON_STATS] = [$this, 'receiveStats'];

        $this->duration = 0.0;
        $endpointUri = $this->prepareEndpointUri($endpoint, $storeId);

        $this->logManager->debug(
            '>>> {method} {endpoint}',
            [
                'method' => $method,
                'endpoint' => $endpoint,
                'extra' => [
                    'uri' => $endpointUri,
                    'body' => $body,
                ],
            ]
        );

        try {
            $this->logManager->debug('Sending request to Qliro Uri: ' . $endpointUri);
            $response = $this->client->request($method, $endpointUri, $options);
            $responseData = $this->getResponseData($response);
            $this->logManager->debug('Received response from Qliro Uri: ' . $endpointUri);

            $this->logManager->debug(
                '<<< Result in {duration} seconds',
                [
                    'duration' => $this->duration,
                    'extra' => [
                        'uri' => $endpointUri,
                        'request' => $body,
                        'status_code' => $response->getStatusCode(),
                        'response' => print_r($responseData, true),
                    ]
                ]
            );
        } catch (\Exception $exception) {
            $this->logManager->debug('Error response from Qliro Uri: ' . $endpointUri . PHP_EOL . $exception->getMessage());
            $exceptionData = [
                'exception' => $exception->getMessage(),
                'uri' => $endpointUri,
                'request' => $body,
            ];

            if ($exception instanceof ClientException) {
                $response = $exception->getResponse();

                $exceptionData = array_merge($exceptionData, [
                    'status_code' => $response->getStatusCode(),
                    'error_reason' => $response->getReasonPhrase(),
                    'response' => $this->getResponseData($response),
                ]);
            }

            $this->logManager->error(
                '<<< Exception after {duration} seconds',
                [
                    'duration' => $this->duration,
                    'extra' => $exceptionData
                ]
            );

            throw new TerminalException($exception->getMessage(), $exception->getCode(), $exception);
        } finally {
            $this->logManager->setMark(null);
        }

        return $responseData;
    }

    /**
     * Receive stats
     *
     * @param TransferStats $stats
     *
     * @return void
     */
    public function receiveStats(TransferStats $stats): void
    {
        $this->duration = $stats->getTransferTime();
    }

    /**
     * Prepare a full URI to the endpoint
     *
     * @param string $endpoint
     * @param int|string|null $storeId
     *
     * @return string
     */
    private function prepareEndpointUri(string $endpoint, int|string|null $storeId = null): string
    {
        $baseUri = $this->config->getApiType($storeId) === 'prod' ? self::QLIRO_PROD_API_URL : self::QLIRO_SANDBOX_API_URL;

        return implode('/', [$baseUri, trim($endpoint, '/')]);
    }

    /**
     * Get authentification token
     *
     * @param string $body
     * @param string $method
     * @param int|string|null $storeId
     * @return string
     */
    private function getAuthenticationToken(string $body, string $method = self::METHOD_POST, int|string|null $storeId = null): string
    {
        if ($method === self::METHOD_GET) {
            $body = '';
        }

        $secret = $this->config->getMerchantApiSecret($storeId);
        $secretString = base64_encode(hash('sha256', $body . $secret, true));

        return trim(implode(' ', [self::AUTHENTICATION_PREFIX, $secretString]));
    }

    /**
     * Get and decode request data
     *
     * @param ResponseInterface $response
     *
     * @return array
     */
    private function getResponseData(ResponseInterface $response): array
    {
        $responseString = (string)$response->getBody();

        try {
            $responseData = $responseString ? (array)$this->json->unserialize($responseString) : [];
        } catch (\InvalidArgumentException $exception) {
            $responseData = [];
        }

        return $responseData;
    }
}
