<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */

// @codingStandardsIgnoreFile
// phpcs:ignoreFile

namespace Qliro\QliroOne\Console\Callback;

use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Qliro\QliroOne\Console\AbstractCommand;

/**
 * Base class for all qliroone:callback:* simulate commands.
 *
 * Each subclass handles one specific callback type. The common flow is:
 *   1. Fetch the real Qliro order via the Merchant API.
 *   2. Build the exact payload shape Qliro would POST to the callback URL.
 *   3. Generate a fresh signed JWT token.
 *   4. POST to the local store URL (self-signed SSL accepted).
 *
 * Pass --dry-run to print the URL + payload without sending.
 */
abstract class AbstractCallbackCommand extends AbstractCommand
{
    protected string $qliroOrderId;
    protected bool   $dry;

    // ── Abstract interface ────────────────────────────────────────────────────

    /** bin/magento command name, e.g. qliroone:callback:checkout-status */
    abstract protected function getCommandName(): string;

    /** One-line description shown in bin/magento list */
    abstract protected function getCommandDescription(): string;

    /** URL path, e.g. checkout/qliro_callback/checkoutStatus */
    abstract protected function getCallbackPath(): string;

    /**
     * Build the POST payload from the full Qliro order array.
     * Return null to abort (subclass should print its own error before returning null).
     */
    abstract protected function buildPayload(array $qliroOrder, InputInterface $input): ?array;

    // ── Symfony Console wiring ────────────────────────────────────────────────

    protected function configure(): void
    {
        parent::configure();

        $this->setName($this->getCommandName());
        $this->setDescription($this->getCommandDescription());

        $this->addArgument(
            'qliro_order_id',
            InputArgument::REQUIRED,
            'Qliro order ID to simulate the callback for'
        );
        $this->addOption(
            'dry-run',
            null,
            InputOption::VALUE_NONE,
            'Print the payload and target URL without actually POSTing'
        );

        $this->configureExtra();
    }

    /** Subclasses may override to register additional options/arguments. */
    protected function configureExtra(): void {}

    protected function initialize(InputInterface $input, OutputInterface $output): void
    {
        parent::initialize($input, $output);
        $this->qliroOrderId = (string) $input->getArgument('qliro_order_id');
        $this->dry          = (bool)   $input->getOption('dry-run');
    }

    // ── Shared execute() ─────────────────────────────────────────────────────

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $om = $this->getObjectManager();

        /** @var \Qliro\QliroOne\Api\Client\MerchantInterface $merchantApi */
        $merchantApi = $om->get(\Qliro\QliroOne\Api\Client\MerchantInterface::class);

        /** @var \Qliro\QliroOne\Model\Security\CallbackToken $callbackToken */
        $callbackToken = $om->get(\Qliro\QliroOne\Model\Security\CallbackToken::class);

        /** @var \Magento\Store\Model\StoreManagerInterface $storeManager */
        $storeManager = $om->get(\Magento\Store\Model\StoreManagerInterface::class);

        $output->writeln(sprintf(
            '<comment>%s → Qliro order <info>%s</info></comment>',
            $this->getCommandName(),
            $this->qliroOrderId
        ));

        // 1. Fetch real order from Qliro ──────────────────────────────────────
        $output->writeln('  Fetching order from Qliro Merchant API…');
        try {
            $qliroOrder = $merchantApi->getOrder($this->qliroOrderId);
        } catch (\Exception $e) {
            $output->writeln('<error>Failed to fetch Qliro order: ' . $e->getMessage() . '</error>');
            return 1;
        }

        if (empty($qliroOrder)) {
            $output->writeln('<error>Qliro returned empty order data.</error>');
            return 1;
        }

        $output->writeln(sprintf(
            '  Qliro order status: <info>%s</info>',
            $qliroOrder['Status'] ?? 'unknown'
        ));

        // 2. Build callback-specific payload ──────────────────────────────────
        $payload = $this->buildPayload($qliroOrder, $input);
        if ($payload === null) {
            return 1;
        }

        // 3. Build target URL with fresh signed token ─────────────────────────
        $token = $callbackToken->getToken();
        $store = $storeManager->getStore();
        $base  = rtrim($store->getBaseUrl(\Magento\Framework\UrlInterface::URL_TYPE_WEB), '/');
        $url   = $base . '/' . ltrim($this->getCallbackPath(), '/') . '?token=' . urlencode($token);

        $output->writeln('  Target URL : <info>' . $url . '</info>');
        $output->writeln('  Payload    :');
        $output->writeln('<comment>' . json_encode($payload, JSON_PRETTY_PRINT) . '</comment>');

        if ($this->dry) {
            $output->writeln('<comment>[dry-run] Skipping POST.</comment>');
            return 0;
        }

        // 4. POST to local callback URL ────────────────────────────────────────
        $output->writeln('  POSTing…');
        try {
            [$httpStatus, $responseBody] = $this->post($url, $payload);
        } catch (\Exception $e) {
            $output->writeln('<error>HTTP request failed: ' . $e->getMessage() . '</error>');
            return 1;
        }

        if ($httpStatus >= 200 && $httpStatus < 300) {
            $output->writeln(sprintf('  Response HTTP <info>%s</info>: %s', $httpStatus, $responseBody));
            $output->writeln('<info>✓ Callback delivered successfully.</info>');
            return 0;
        }

        $output->writeln(sprintf('  Response HTTP <error>%s</error>: %s', $httpStatus, $responseBody));
        $output->writeln('<error>✗ Non-2xx response. Check var/log/qliroone_*.log</error>');
        return 1;
    }

    // ── HTTP helper ───────────────────────────────────────────────────────────

    /**
     * POST JSON to a URL; returns [httpStatusCode, responseBodyString].
     * Uses cURL directly so it works on local without any Guzzle proxy config.
     *
     * @return array{int, string}
     */
    protected function post(string $url, array $payload): array
    {
        $body = json_encode($payload);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Content-Length: ' . strlen($body),
            ],
            CURLOPT_SSL_VERIFYPEER => false, // accept self-signed certs on local
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
