<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Service\Callback;

use Magento\Store\Model\StoreManagerInterface;
use Qliro\QliroOne\Model\Config;
use Qliro\QliroOne\Model\Security\CallbackToken;

/**
 * Builds the callback URLs that Qliro calls back on
 */
class UrlBuilder
{
    /**
     * @var array<int, string> Token per store, the token is signed with the store's credentials
     */
    private array $generatedTokens = [];

    /**
     * Class constructor
     *
     * @param Config $qliroConfig
     * @param StoreManagerInterface $storeManager
     * @param CallbackToken $callbackToken
     */
    public function __construct(
        private readonly Config $qliroConfig,
        private readonly StoreManagerInterface $storeManager,
        private readonly CallbackToken $callbackToken
    ) {
    }

    /**
     * Get a callback URL with provided path and generated token
     *
     * @param string $path
     * @return string
     */
    public function getCallbackUrl(string $path): string
    {
        $query = ['token' => $this->generateCallbackToken()];

        if ($this->qliroConfig->isDebugMode()) {
            $query['XDEBUG_SESSION_START'] = $this->qliroConfig->getCallbackXdebugSessionFlagName();
        }

        if ($this->qliroConfig->redirectCallbacks() && ($baseUri = $this->qliroConfig->getCallbackUri())) {
            $url = \implode('/', [\rtrim($baseUri, '/'), \ltrim($path, '/')]);

            // Built here instead of through the shared query params resolver: that one is a
            // singleton whose params leak into every other URL generated in the same request
            $url .= '?' . \http_build_query($query);

            return $this->applyHttpAuth($url);
        }

        /** @var \Magento\Store\Model\Store $store */
        $store = $this->storeManager->getStore();

        return $this->applyHttpAuth($this->removePathTrailingSlash($store->getUrl($path, ['_query' => $query])));
    }

    /**
     * Drop the slash Magento appends after the action name
     *
     * A trailing slash makes setups that strip it answer the callback with a redirect, and a
     * client that does not resend the body on a redirect turns the callback into an empty POST.
     *
     * @param string $url
     * @return string
     */
    private function removePathTrailingSlash(string $url): string
    {
        $queryPosition = \strpos($url, '?');

        if ($queryPosition === false) {
            return \rtrim($url, '/');
        }

        return \rtrim(\substr($url, 0, $queryPosition), '/') . \substr($url, $queryPosition);
    }

    /**
     * Apply HTTP authentication credentials if specified
     *
     * @param string $url
     * @return string
     */
    private function applyHttpAuth(string $url): string
    {
        if ($this->qliroConfig->isHttpAuthEnabled() && \preg_match('#^(https?://)(.+)$#', $url, $match)) {
            $authUsername = $this->qliroConfig->getCallbackHttpAuthUsername();
            $authPassword = $this->qliroConfig->getCallbackHttpAuthPassword();

            $url = \sprintf('%s%s:%s@%s', $match[1], \urlencode($authUsername), \urlencode($authPassword), $match[2]);
        }

        return $url;
    }

    /**
     * Generate the token once per store and reuse it for every callback URL of that store
     *
     * Keyed by store because the token is signed with the store's API credentials, and this
     * service is a singleton the recurring orders cron reuses while emulating every store.
     *
     * @return string
     */
    private function generateCallbackToken(): string
    {
        $storeId = (int)$this->storeManager->getStore()->getId();

        if (empty($this->generatedTokens[$storeId])) {
            $this->generatedTokens[$storeId] = $this->callbackToken->getToken();
        }

        return $this->generatedTokens[$storeId];
    }
}
