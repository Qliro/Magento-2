<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */

// @codingStandardsIgnoreFile
// phpcs:ignoreFile

namespace Qliro\QliroOne\Console;

use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\UrlInterface;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Store\Model\StoreManagerInterface;
use Qliro\QliroOne\Api\Client\MerchantInterface;
use Qliro\QliroOne\Api\LinkRepositoryInterface;
use Qliro\QliroOne\Api\OrderManagementStatusRepositoryInterface;
use Qliro\QliroOne\Model\Security\CallbackToken;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Simulate Qliro callback POSTs to the local store for development purposes.
 *
 * Usage:
 *   bin/magento qliroone:callback:simulate <qliro_order_id>
 *   bin/magento qliroone:callback:simulate <qliro_order_id> Error
 *   bin/magento qliroone:callback:simulate <qliro_order_id> --type=merchant_notification
 *   bin/magento qliroone:callback:simulate <qliro_order_id> --type=shipping_methods
 *   bin/magento qliroone:callback:simulate <qliro_order_id> --status=Completed
 *   bin/magento qliroone:callback:simulate <qliro_order_id> --dry-run
 */
class SimulateCallbackCommand extends AbstractCommand
{
    const COMMAND_RUN               = 'qliroone:callback:simulate';
    const TYPE_CHECKOUT_STATUS       = 'checkout_status';
    const TYPE_TRANSACTION_STATUS    = 'transaction_status';
    const TYPE_MERCHANT_NOTIFICATION = 'merchant_notification';
    const TYPE_SHIPPING_METHODS      = 'shipping_methods';

    private string  $qliroOrderId;
    private string  $type;
    private ?string $statusOverride;
    private bool    $dry;

    protected function configure(): void
    {
        parent::configure();

        $this->setName(self::COMMAND_RUN);
        $this->setDescription(
            'Simulate a Qliro callback POST to the local store. ' .
            'Builds payload from local DB — works even when Qliro has expired the order.'
        );

        $this->addArgument(
            'qliro_order_id',
            InputArgument::REQUIRED,
            'The Qliro order ID to simulate a callback for'
        );
        $this->addOption(
            'type',
            't',
            InputOption::VALUE_OPTIONAL,
            implode('|', [
                self::TYPE_CHECKOUT_STATUS,
                self::TYPE_TRANSACTION_STATUS,
                self::TYPE_MERCHANT_NOTIFICATION,
                self::TYPE_SHIPPING_METHODS,
            ]),
            self::TYPE_CHECKOUT_STATUS
        );
        $this->addOption(
            'status',
            's',
            InputOption::VALUE_OPTIONAL,
            'Override the Status field in the payload ' .
            '(e.g. Completed, InProcess, Cancelled). ' .
            'Defaults to the value stored in the link table.'
        );
        $this->addOption(
            'dry-run',
            null,
            InputOption::VALUE_NONE,
            'Print the payload and URL without actually POSTing'
        );
    }

    protected function initialize(InputInterface $input, OutputInterface $output): void
    {
        parent::initialize($input, $output);
        $this->qliroOrderId   = (string)  $input->getArgument('qliro_order_id');
        $this->type           = (string)  $input->getOption('type');
        $this->statusOverride = $input->getOption('status') !== null
            ? (string) $input->getOption('status')
            : null;
        $this->dry = (bool) $input->getOption('dry-run');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        //used with purpose
        $om = $this->getObjectManager();

        /** @var LinkRepositoryInterface $linkRepo */
        $linkRepo = $om->get(LinkRepositoryInterface::class);

        /** @var CallbackToken $callbackToken */
        $callbackToken = $om->get(CallbackToken::class);

        /** @var StoreManagerInterface $storeManager */
        $storeManager = $om->get(StoreManagerInterface::class);

        $output->writeln(sprintf(
            '<comment>Simulating <info>%s</info> callback for Qliro order <info>%s</info></comment>',
            $this->type,
            $this->qliroOrderId
        ));

        try {
            $link = $linkRepo->getByQliroOrderId((int) $this->qliroOrderId, false);
        } catch (\Exception $e) {
            $output->writeln(
                '<error>Link not found in local DB for Qliro order ' . $this->qliroOrderId . '</error>'
            );
            $output->writeln('<error>' . $e->getMessage() . '</error>');
            return 1;
        }

        $storedStatus = $link->getQliroOrderStatus() ?: '(none)';
        $output->writeln(sprintf(
            '  Link found — reference: <info>%s</info>  order_id: <info>%s</info>  stored status: <info>%s</info>',
            $link->getReference(),
            $link->getOrderId() ?: '(none)',
            $storedStatus
        ));

        $qliroOrder = null;
        try {
            /** @var MerchantInterface $merchantApi */
            $merchantApi = $om->get(MerchantInterface::class);
            $live = $merchantApi->getOrder((int) $this->qliroOrderId);
            if (!empty($live['Status'])) {
                $qliroOrder = $live;
                $output->writeln(
                    '  Qliro API: live status <info>' . $live['Status'] . '</info>'
                );
            } else {
                $output->writeln(
                    '  <comment>Qliro API returned no status (order data expired) — using local DB data.</comment>'
                );
            }
        } catch (\Exception $e) {
            $output->writeln(
                '  <comment>Qliro API unavailable (' . $e->getMessage() . ') — using local DB data.</comment>'
            );
        }

        if ($this->statusOverride !== null) {
            $effectiveStatus = $this->statusOverride;
            $output->writeln('  Using CLI --status override: <info>' . $effectiveStatus . '</info>');
        } elseif ($qliroOrder !== null) {
            $effectiveStatus = $qliroOrder['Status'];
        } else {
            $effectiveStatus = $link->getQliroOrderStatus() ?: 'Completed';
            $output->writeln(
                '  Falling back to stored link status: <info>' . $effectiveStatus . '</info>'
            );
        }

        $payload = $this->buildPayload($om, $link, $qliroOrder, $effectiveStatus, $output);
        if ($payload === null) {
            $output->writeln('<error>Unknown callback type: ' . $this->type . '</error>');
            return 1;
        }

        $token       = $callbackToken->getToken();
        $callbackUrl = $this->buildLocalUrl($storeManager, $token);

        $output->writeln('  Target URL : <info>' . $callbackUrl . '</info>');
        $output->writeln('  Payload:');
        $output->writeln('<comment>' . json_encode($payload, JSON_PRETTY_PRINT) . '</comment>');

        if ($this->dry) {
            $output->writeln('<comment>[dry-run] Not POSTing.</comment>');
            return 0;
        }

        $output->writeln('  POSTing…');
        try {
            [$httpStatus, $responseBody] = $this->httpPost($callbackUrl, $payload);
        } catch (\Exception $e) {
            $output->writeln('<error>HTTP request failed: ' . $e->getMessage() . '</error>');
            return 1;
        }

        $output->writeln(sprintf(
            '  Response HTTP <info>%s</info>: %s',
            $httpStatus,
            $responseBody
        ));

        if ($httpStatus >= 200 && $httpStatus < 300) {
            $output->writeln('<info>Callback delivered successfully.</info>');
            return 0;
        }

        $output->writeln('<error>Callback returned non-2xx. Check var/log/qliroone* for details.</error>');
        return 1;
    }

    /**
     * Dispatch to the correct payload builder for $this->type
     * Returns null for unknown types
     */
    private function buildPayload(
        $om,
        $link,
        ?array $qliroOrder,
        string $effectiveStatus,
        OutputInterface $output
    ): ?array {
        return match ($this->type) {
            self::TYPE_CHECKOUT_STATUS => $this->buildCheckoutStatusPayload($link, $effectiveStatus),
            self::TYPE_TRANSACTION_STATUS => $this->buildTransactionStatusPayload($om, $link, $qliroOrder, $effectiveStatus, $output),
            self::TYPE_MERCHANT_NOTIFICATION => $this->buildMerchantNotificationPayload($link, $qliroOrder),
            self::TYPE_SHIPPING_METHODS => $this->buildShippingMethodsPayload($om, $link, $qliroOrder, $output),
            default => null,
        };
    }

    /**
     * Build payload for MerchantCheckoutStatusPushUrl
     *
     */
    private function buildCheckoutStatusPayload($link, string $status): array
    {
        return [
            'OrderId'           => (int) $link->getQliroOrderId(),
            'MerchantReference' => $link->getReference(),
            'Status'            => $status,
            'Timestamp'         => date('Y-m-d\TH:i:s\Z'),
        ];
    }

    /**
     * Build payload for MerchantOrderManagementStatusPushUrl
     *
     */
    private function buildTransactionStatusPayload(
        $om,
        $link,
        ?array $qliroOrder,
        string $effectiveStatus,
        OutputInterface $output
    ): array {
        if ($qliroOrder !== null) {
            $transactions = $qliroOrder['PaymentTransactions'] ?? [];
            if (!empty($transactions)) {
                $tx = reset($transactions);
                return [
                    'OrderId'              => (int) $link->getQliroOrderId(),
                    'MerchantReference'    => $link->getReference(),
                    'PaymentTransactionId' => $tx['PaymentTransactionId'] ?? null,
                    'Status'               => $effectiveStatus,
                    'Amount'               => $tx['Amount'] ?? null,
                ];
            }
        }

        $txId   = null;
        $amount = null;
        try {
            /** @var SearchCriteriaBuilder $scb */
            $scb = $om->create(SearchCriteriaBuilder::class);
            $scb->addFilter('qliro_order_id', (int) $this->qliroOrderId);
            $scb->setPageSize(1);

            /** @var OrderManagementStatusRepositoryInterface $omsRepo */
            $omsRepo = $om->get(OrderManagementStatusRepositoryInterface::class);
            $results = $omsRepo->getList($scb->create());
            $items   = $results->getItems();

            if (!empty($items)) {
                $oms  = reset($items);
                $txId = $oms->getTransactionId();
                $output->writeln(
                    '  Found local OMS record — transaction_id: <info>' . $txId . '</info>'
                );
            } else {
                $output->writeln(
                    '  <comment>No OMS record found locally. PaymentTransactionId will be null.</comment>'
                );
            }
        } catch (\Exception $e) {
            $output->writeln(
                '  <comment>Could not load OMS record: ' . $e->getMessage() . '</comment>'
            );
        }

        return [
            'OrderId'              => (int) $link->getQliroOrderId(),
            'MerchantReference'    => $link->getReference(),
            'PaymentTransactionId' => $txId,
            'Status'               => $effectiveStatus,
            'Amount'               => $amount,
        ];
    }

    /**
     * Build payload for MerchantNotificationUrl
     *
     */
    private function buildMerchantNotificationPayload($link, ?array $qliroOrder): array
    {
        $shippingProvider = null;
        $shippingPayload  = null;

        if ($qliroOrder !== null) {
            foreach ($qliroOrder['OrderItems'] ?? [] as $item) {
                $props = $item['Metadata']['AdditionalShippingProperties'] ?? null;
                if ($props) {
                    $shippingProvider = $item['Metadata']['ShippingProvider'] ?? 'Unifaun';
                    $shippingPayload  = $props;
                    break;
                }
            }
        }

        return [
            'OrderId'           => (int) $link->getQliroOrderId(),
            'MerchantReference' => $link->getReference(),
            'EventType'         => 'ShippingProviderUpdate',
            'Provider'          => $shippingProvider ?? 'Unifaun',
            'Payload'           => $shippingPayload ?? ['simulated' => true],
        ];
    }

    /**
     * Build payload for MerchantOrderAvailableShippingMethodsUrl.
     *
     */
    private function buildShippingMethodsPayload(
        $om,
        $link,
        ?array $qliroOrder,
        OutputInterface $output
    ): array {
        if ($qliroOrder !== null) {
            return [
                'OrderId'           => (int) $link->getQliroOrderId(),
                'MerchantReference' => $link->getReference(),
                'CountryCode'       => $qliroOrder['Country'] ?? null,
                'PostalCode'        => $qliroOrder['ShippingAddress']['PostalCode'] ??
                                       $qliroOrder['BillingAddress']['PostalCode'] ??
                                       null,
                'Customer'          => $qliroOrder['Customer'] ?? null,
                'ShippingAddress'   => $qliroOrder['ShippingAddress'] ?? null,
            ];
        }

        $postalCode  = null;
        $countryCode = null;
        try {
            /** @var CartRepositoryInterface $quoteRepo */
            $quoteRepo  = $om->get(CartRepositoryInterface::class);
            $quote      = $quoteRepo->get($link->getQuoteId());
            $address    = $quote->isVirtual()
                ? $quote->getBillingAddress()
                : $quote->getShippingAddress();

            $postalCode  = $address->getPostcode();
            $countryCode = $address->getCountryId();
            $output->writeln(
                '  Reconstructed from quote address — postcode: <info>' . $postalCode .
                '</info>  country: <info>' . $countryCode . '</info>'
            );
        } catch (\Exception $e) {
            $output->writeln(
                '  <comment>Could not load quote address: ' . $e->getMessage() . '</comment>'
            );
        }

        return [
            'OrderId'           => (int) $link->getQliroOrderId(),
            'MerchantReference' => $link->getReference(),
            'CountryCode'       => $countryCode,
            'PostalCode'        => $postalCode,
            'Customer'          => null,
            'ShippingAddress'   => null,
        ];
    }

    /**
     * Build the local store callback URL for $this->type with a fresh signed token
     */
    private function buildLocalUrl(
        StoreManagerInterface $storeManager,
        string $token
    ): string {
        $pathMap = [
            self::TYPE_CHECKOUT_STATUS       => 'checkout/qliro_callback/checkoutStatus',
            self::TYPE_TRANSACTION_STATUS    => 'checkout/qliro_callback/transactionStatus',
            self::TYPE_MERCHANT_NOTIFICATION => 'checkout/qliro_callback/merchantNotification',
            self::TYPE_SHIPPING_METHODS      => 'checkout/qliro_callback/shippingMethods',
        ];

        $path = $pathMap[$this->type];
        $base = rtrim(
            $storeManager->getStore()->getBaseUrl(UrlInterface::URL_TYPE_WEB),
            '/'
        );

        return $base . '/' . ltrim($path, '/') . '?token=' . urlencode($token);
    }

    /**
     * POST a JSON payload to $url via cURL and return [httpStatusCode, responseBody]
     *
     *
     * @return array{int, string}
     */
    private function httpPost(string $url, array $payload): array
    {
        $body = json_encode($payload);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     =>
                [
                    'Content-Type: application/json',
                    'Content-Length: ' . strlen($body),
                ],
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_CONNECTTIMEOUT => 10,
        ]);

        $response   = curl_exec($ch);
        $httpStatus = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError  = curl_error($ch);
        curl_close($ch);

        if ($curlError) {
            throw new \RuntimeException('cURL error: ' . $curlError);
        }

        return [$httpStatus, (string) $response];
    }
}
