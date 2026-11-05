<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Model\Security;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use Qliro\QliroOne\Model\Config;

/**
 * API key and secret pairs the store used before they were replaced
 *
 * Callback urls of older orders carry tokens signed with the pair in place when the order was
 * created, so they are accepted with it for a configurable number of days after the change.
 * Each pair is kept for every website that used it when it was replaced, and vouches only there.
 */
class PreviousCredentials
{
    public const PATH = 'payment/qliroone/qliro_api/previous_credentials';
    public const KEY_PATH = 'payment/qliroone/qliro_api/merchant_api_key';
    public const SECRET_PATH = 'payment/qliroone/qliro_api/merchant_api_secret';
    public const TYPE_PATH = 'payment/qliroone/qliro_api/type';

    /**
     * Per website: a typo corrected right after a change must not push out the pair the orders
     * were signed with
     */
    private const KEEP = 5;

    /**
     * How long one save lasts: what it wrote and which types it changed are kept for that long
     * only, since the scope config sees the writes after a reload and a process can live on
     */
    private const SAME_SAVE_SECONDS = 60;

    /**
     * Website id to the time its API type changed, -1 for the default scope
     */
    private const ALL_WEBSITES = -1;

    /**
     * The list as read or last written, and when
     *
     * @var array|null
     */
    private ?array $pairs = null;
    private int $pairsAt = 0;

    /**
     * @var array<int, int>
     */
    private array $typeChanged = [];

    /**
     * @param ScopeConfigInterface $scopeConfig
     * @param WriterInterface $writer
     * @param EncryptorInterface $encryptor
     * @param Config $config
     * @param StoreManagerInterface $storeManager
     */
    public function __construct(
        private ScopeConfigInterface $scopeConfig,
        private WriterInterface $writer,
        private EncryptorInterface $encryptor,
        private Config $config,
        private StoreManagerInterface $storeManager
    ) {
    }

    /**
     * Pairs the current store used, replaced within the configured window, newest first
     *
     * @return array<int, array{key: string, secret: string, replaced_at: int, website: int}>
     */
    public function getActive(): array
    {
        $days = $this->config->getPreviousCredentialsDays();

        if ($days < 1) {
            return [];
        }

        try {
            $websiteId = (int)$this->storeManager->getStore()->getWebsiteId();
        } catch (\Throwable $exception) {
            return [];
        }

        $since = time() - $days * 86400;

        return array_values(array_filter(
            $this->read(),
            fn (array $pair): bool => $pair['replaced_at'] >= $since && $pair['website'] === $websiteId
        ));
    }

    /**
     * Whether a key was ever replaced here, in or out of the window
     *
     * @param string $key
     * @return bool
     */
    public function isKnownKey(string $key): bool
    {
        foreach ($this->read() as $pair) {
            if (hash_equals($pair['key'], $key)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether a scope holds a value of its own for the path, other than the default one
     *
     * @param string $path
     * @param string $scope
     * @param int|string|null $scopeCode
     * @return bool
     */
    public function overridesDefault(string $path, string $scope, $scopeCode): bool
    {
        return $scope !== ScopeConfigInterface::SCOPE_TYPE_DEFAULT
            && (string)$this->scopeConfig->getValue($path, $scope, $scopeCode)
                !== (string)$this->scopeConfig->getValue($path);
    }

    /**
     * Drop the kept pairs of a scope, every pair for the default scope
     *
     * Turning the window off asks for it, and so does a change of API type: a sandbox pair
     * replaced when going live must vouch for nothing in production.
     *
     * @param string|null $scope
     * @param int|string|null $scopeCode
     * @param bool $typeChange Keep nothing more for it in this process: the key and secret of the
     *                         same save are written after the type, still read as the old ones
     * @return void
     */
    public function forget(?string $scope = null, $scopeCode = null, bool $typeChange = false): void
    {
        $websiteId = $this->websiteOf((string)$scope, $scopeCode);

        if ($typeChange) {
            $this->typeChanged[$websiteId === null || $websiteId === 0 ? self::ALL_WEBSITES : $websiteId] = time();
        }

        $this->write($websiteId === null || $websiteId === 0
            ? []
            : array_filter($this->read(), fn (array $pair): bool => $pair['website'] !== $websiteId));
    }

    /**
     * Keep the pair a scope uses now, before its key or secret is replaced
     *
     * Called while the new value is being saved, when the scope config still holds the old one.
     * A default value that changes also changes it on websites that inherit it while holding the
     * other half of the pair themselves, so their pairs are kept too.
     *
     * @param string $scope
     * @param int|string|null $scopeCode
     * @param string|null $path The setting being changed
     * @return void
     */
    public function rememberCurrent(string $scope, $scopeCode, ?string $path = null): void
    {
        if ($this->config->getPreviousCredentialsDays() < 1) {
            return;
        }

        $websiteId = $this->websiteOf($scope, $scopeCode);

        if ($websiteId === null) {
            return;
        }

        $websites = [];

        if ($websiteId === 0) {
            // The default pair goes to each website that used it, and a change of one half to each
            // website inheriting that half while holding the other
            foreach ($this->storeManager->getWebsites() as $website) {
                $id = (int)$website->getId();
                $inheritsChanged = $path === null
                    || !$this->overridesDefault($path, ScopeInterface::SCOPE_WEBSITES, $id);

                if ($inheritsChanged) {
                    $websites[] = $id;
                }
            }
        } else {
            $websites[] = $websiteId;
        }

        $recent = fn (int $id): bool => ($this->typeChanged[$id] ?? 0) > time() - self::SAME_SAVE_SECONDS;

        if ($recent(self::ALL_WEBSITES)) {
            return;
        }

        $websites = array_filter($websites, fn (int $id): bool => !$recent($id));

        $pairs = $this->read();
        $changed = false;

        foreach ($websites as $id) {
            [$key, $secret] = $this->pairOf($id);

            if ($key === '' || $secret === '') {
                continue;
            }

            $same = fn (array $kept): bool => $kept['key'] === $key
                && $kept['secret'] === $secret
                && $kept['website'] === $id;

            foreach ($pairs as $kept) {
                if ($same($kept) && $kept['replaced_at'] > time() - self::SAME_SAVE_SECONDS) {
                    continue 2;
                }
            }

            $pairs = array_values(array_filter($pairs, fn (array $kept): bool => !$same($kept)));
            array_unshift($pairs, ['key' => $key, 'secret' => $secret, 'replaced_at' => time(), 'website' => $id]);
            $changed = true;
        }

        if ($changed) {
            $this->write($pairs);
        }
    }

    /**
     * The website a scope belongs to, 0 for the default scope, null when there is none
     *
     * @param string $scope
     * @param int|string|null $scopeCode
     * @return int|null
     */
    private function websiteOf(string $scope, $scopeCode): ?int
    {
        try {
            return match ($scope) {
                '', ScopeConfigInterface::SCOPE_TYPE_DEFAULT => 0,
                ScopeInterface::SCOPE_WEBSITES, ScopeInterface::SCOPE_WEBSITE
                    => (int)$this->storeManager->getWebsite($scopeCode)->getId(),
                ScopeInterface::SCOPE_STORES, ScopeInterface::SCOPE_STORE
                    => (int)$this->storeManager->getStore($scopeCode)->getWebsiteId(),
                default => null,
            };
        } catch (\Throwable $exception) {
            return null;
        }
    }

    /**
     * @param int $websiteId 0 for the default scope
     * @return array{0: string, 1: string}
     */
    private function pairOf(int $websiteId): array
    {
        $scope = $websiteId === 0 ? ScopeConfigInterface::SCOPE_TYPE_DEFAULT : ScopeInterface::SCOPE_WEBSITES;
        $scopeCode = $websiteId === 0 ? null : $websiteId;

        return [
            (string)$this->scopeConfig->getValue(self::KEY_PATH, $scope, $scopeCode),
            (string)$this->scopeConfig->getValue(self::SECRET_PATH, $scope, $scopeCode),
        ];
    }

    /**
     * @param array $pairs Newest first
     * @return void
     */
    private function write(array $pairs): void
    {
        $count = [];
        $pairs = array_values(array_filter($pairs, function (array $pair) use (&$count): bool {
            $count[$pair['website']] = ($count[$pair['website']] ?? 0) + 1;

            return $count[$pair['website']] <= self::KEEP;
        }));

        try {
            $json = \json_encode($pairs, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            // A value that is not UTF-8 must not wipe the pairs already kept
            return;
        }

        $this->writer->save(
            self::PATH,
            $this->encryptor->encrypt($json),
            ScopeConfigInterface::SCOPE_TYPE_DEFAULT,
            0
        );
        $this->pairs = $pairs;
        $this->pairsAt = time();
    }

    /**
     * @return array<int, array{key: string, secret: string, replaced_at: int, website: int}>
     */
    private function read(): array
    {
        if ($this->pairs !== null && $this->pairsAt > time() - self::SAME_SAVE_SECONDS) {
            return $this->pairs;
        }

        $this->pairs = [];
        $this->pairsAt = time();
        $stored = (string)$this->scopeConfig->getValue(self::PATH);

        if ($stored === '') {
            return $this->pairs;
        }

        try {
            // Empty after the encryption key it was written with is dropped from env.php
            $pairs = \json_decode($this->encryptor->decrypt($stored), true);
        } catch (\Throwable $exception) {
            return $this->pairs;
        }

        foreach (is_array($pairs) ? $pairs : [] as $pair) {
            if (is_array($pair)
                && is_string($pair['key'] ?? null) && $pair['key'] !== ''
                && is_string($pair['secret'] ?? null) && $pair['secret'] !== ''
                && is_int($pair['replaced_at'] ?? null)
                && is_int($pair['website'] ?? null)
            ) {
                $this->pairs[] = [
                    'key' => $pair['key'],
                    'secret' => $pair['secret'],
                    'replaced_at' => $pair['replaced_at'],
                    'website' => $pair['website'],
                ];
            }
        }

        return $this->pairs;
    }
}
