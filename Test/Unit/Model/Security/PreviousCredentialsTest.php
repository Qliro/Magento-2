<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Test\Unit\Model\Security;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Api\Data\WebsiteInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;
use Qliro\QliroOne\Model\Config;
use Qliro\QliroOne\Model\Security\PreviousCredentials;

/**
 * @see \Qliro\QliroOne\Model\Security\PreviousCredentials
 */
class PreviousCredentialsTest extends TestCase
{
    /**
     * Config values by "path|scope|scopeId"
     *
     * @var array<string, string|null>
     */
    private array $stored = [];

    /**
     * @var array<int, array{path: string, value: string, scope: string, scopeId: int}>
     */
    private array $written = [];

    private int $days = 90;

    /**
     * @var int[]
     */
    private array $websites = [];

    /**
     * The pair a website used before its change is kept, encrypted, in the one list at the
     * default scope, which every store reads.
     */
    public function testRemembersThePairBeingReplaced(): void
    {
        $this->setCurrent('websites', 2, 'old-key', 'old-secret');

        $this->build()->rememberCurrent('websites', 2);

        self::assertCount(1, $this->written);
        self::assertSame(PreviousCredentials::PATH, $this->written[0]['path']);
        self::assertSame('default', $this->written[0]['scope']);
        self::assertSame(0, $this->written[0]['scopeId']);
        self::assertStringStartsWith('enc:', $this->written[0]['value']);

        $pairs = $this->decode($this->written[0]['value']);
        self::assertSame('old-key', $pairs[0]['key']);
        self::assertSame('old-secret', $pairs[0]['secret']);
        self::assertEqualsWithDelta(time(), $pairs[0]['replaced_at'], 5);
    }

    /**
     * A first setup has nothing to replace.
     */
    public function testAnIncompletePairIsNotKept(): void
    {
        $this->setCurrent('default', null, 'old-key', '');

        $this->build()->rememberCurrent('default', 0);

        self::assertSame([], $this->written);
    }

    /**
     * A pair replaced again later, on another save, moves to the top with the new date: its
     * window starts over.
     */
    public function testAPairReplacedAgainStartsItsWindowOver(): void
    {
        $this->setCurrent('websites', 1, 'old-key', 's');
        $this->setStored('default', null, [$this->pair('old-key', 1, 1000)]);

        $this->build()->rememberCurrent('websites', 1);

        $pairs = $this->decode($this->written[0]['value']);
        self::assertCount(1, $pairs);
        self::assertEqualsWithDelta(time(), $pairs[0]['replaced_at'], 5);
    }

    /**
     * Newest first, and only the last few: a typo corrected right away must not push out the pair
     * the orders were signed with.
     */
    public function testKeepsTheLastFiveNewestFirst(): void
    {
        $this->setCurrent('websites', 1, 'key-6', 'secret-6');
        $this->setStored('default', null, array_map(fn (int $n) => $this->pair('key-' . $n, 1, $n * 1000), [5, 4, 3, 2, 1]));

        $this->build()->rememberCurrent('websites', 1);

        self::assertSame(
            ['key-6', 'key-5', 'key-4', 'key-3', 'key-2'],
            array_column($this->decode($this->written[0]['value']), 'key')
        );
    }

    /**
     * Key and secret saved together in one request: the second save sees what the first one
     * wrote, though the scope config has not reloaded, and keeps the pair once.
     */
    public function testOneSaveOfKeyAndSecretKeepsThePairOnce(): void
    {
        $this->setCurrent('websites', 1, 'old-key', 'old-secret');
        $previous = $this->build();

        $previous->rememberCurrent('websites', 1, PreviousCredentials::KEY_PATH);
        $previous->rememberCurrent('websites', 1, PreviousCredentials::SECRET_PATH);

        self::assertCount(1, $this->written);
    }

    /**
     * A website ticking "Use Default" drops its own value; with none of its own it drops nothing.
     */
    public function testTellsAWebsiteValueOfItsOwnFromTheDefault(): void
    {
        $this->stored[PreviousCredentials::KEY_PATH . '|default|'] = 'default-key';
        $this->stored[PreviousCredentials::KEY_PATH . '|websites|2'] = 'own-key';
        $this->stored[PreviousCredentials::KEY_PATH . '|websites|3'] = 'default-key';

        self::assertTrue($this->build()->overridesDefault(PreviousCredentials::KEY_PATH, 'websites', 2));
        self::assertFalse($this->build()->overridesDefault(PreviousCredentials::KEY_PATH, 'websites', 3));
        self::assertFalse($this->build()->overridesDefault(PreviousCredentials::KEY_PATH, 'default', null));
    }

    /**
     * Only pairs replaced within the window count, and none when the window is off.
     */
    public function testActivePairsAreThoseReplacedWithinTheWindow(): void
    {
        $this->setStored('default', null, [
            $this->pair('recent', 1, time() - 10 * 86400),
            $this->pair('old', 1, time() - 100 * 86400),
        ]);

        self::assertSame(['recent'], array_column($this->build()->getActive(), 'key'));

        $this->days = 0;
        self::assertSame([], $this->build()->getActive());
    }

    /**
     * A pair vouches only on the website it was kept for.
     */
    public function testAPairIsActiveOnlyWhereItWasInUse(): void
    {
        $this->setStored('default', null, [$this->pair('this-website', 1, time()), $this->pair('other-website', 2, time())]);

        self::assertSame(['this-website'], array_column($this->build()->getActive(), 'key'));
    }

    /**
     * A default pair is kept for each website that used it then, so a website that gets a key of
     * its own later still accepts its older orders.
     */
    public function testADefaultPairIsKeptForTheWebsitesThatUsedIt(): void
    {
        $this->setCurrent('default', null, 'default-key', 'default-secret');
        $this->stored[PreviousCredentials::KEY_PATH . '|websites|1'] = 'default-key';
        $this->stored[PreviousCredentials::SECRET_PATH . '|websites|1'] = 'default-secret';
        $this->stored[PreviousCredentials::KEY_PATH . '|websites|2'] = 'own-key';
        $this->stored[PreviousCredentials::SECRET_PATH . '|websites|2'] = 'own-secret';
        $this->websites = [1, 2];

        $this->build()->rememberCurrent('default', null, PreviousCredentials::KEY_PATH);

        $pairs = $this->decode($this->written[0]['value']);
        self::assertSame([[1, 'default-key']], array_map(fn ($p) => [$p['website'], $p['key']], $pairs));
    }

    /**
     * Type, key and secret in one save: the type is written first, and the key and secret that
     * follow are still read as the old ones, so nothing more is kept for the website.
     */
    public function testNothingIsKeptAfterATypeChangeInTheSameSave(): void
    {
        $this->setCurrent('websites', 1, 'sandbox-key', 'sandbox-secret');
        $previous = $this->build();

        $previous->forget('websites', 1, true);
        $previous->rememberCurrent('websites', 1, PreviousCredentials::KEY_PATH);

        self::assertSame([], $this->decode(end($this->written)['value']));
    }

    /**
     * A change of API type drops the pairs of that website only, and at the default scope every
     * pair: a sandbox pair must vouch for nothing in production.
     */
    public function testAnApiTypeChangeForgetsThePairs(): void
    {
        $this->setStored('default', null, [$this->pair('w1', 1, time()), $this->pair('w2', 2, time())]);

        $this->build()->forget('websites', 2);
        self::assertSame(['w1'], array_column($this->decode($this->written[0]['value']), 'key'));

        $this->build()->forget('default', null);
        self::assertSame([], $this->decode($this->written[1]['value']));
    }

    /**
     * A store scoped save lands on the store's website.
     */
    public function testAStoreScopedChangeKeepsThePairUnderItsWebsite(): void
    {
        $this->setCurrent('websites', 1, 'old-key', 'old-secret');

        $this->build()->rememberCurrent('stores', 'se');

        self::assertSame(1, $this->decode($this->written[0]['value'])[0]['website']);
    }

    /**
     * Five pairs per website and type, so changes on other websites push out none of them.
     */
    public function testKeepsFivePairsPerWebsite(): void
    {
        $this->setCurrent('websites', 2, 'key-w2', 'secret-w2');
        $this->setStored('default', null, array_merge(
            [$this->pair('key-w1', 1, 6000)],
            array_map(fn (int $n) => $this->pair('key-w2-' . $n, 2, $n * 1000), [5, 4, 3, 2, 1])
        ));

        $this->build()->rememberCurrent('websites', 2);

        self::assertSame(
            ['key-w2', 'key-w1', 'key-w2-5', 'key-w2-4', 'key-w2-3', 'key-w2-2'],
            array_column($this->decode($this->written[0]['value']), 'key')
        );
    }

    /**
     * A website holding its own key and inheriting the secret keeps its pair when the default
     * secret changes.
     */
    public function testADefaultChangeKeepsThePairOfAWebsiteHoldingTheOtherHalf(): void
    {
        $this->setCurrent('default', null, 'default-key', 'default-secret');
        $this->stored[PreviousCredentials::KEY_PATH . '|websites|2'] = 'own-key';
        $this->stored[PreviousCredentials::SECRET_PATH . '|websites|2'] = 'default-secret';
        $this->stored[PreviousCredentials::KEY_PATH . '|websites|3'] = 'default-key';
        $this->stored[PreviousCredentials::SECRET_PATH . '|websites|3'] = 'default-secret';
        $this->websites = [2, 3];

        $this->build()->rememberCurrent('default', null, PreviousCredentials::SECRET_PATH);

        $pairs = $this->decode($this->written[0]['value']);
        self::assertSame([[3, 'default-key'], [2, 'own-key']], array_map(fn ($p) => [$p['website'], $p['key']], $pairs));
    }

    /**
     * With the window off nothing is kept, and forgetting drops what was.
     */
    public function testNothingIsKeptWithTheWindowOff(): void
    {
        $this->setCurrent('default', null, 'old-key', 'old-secret');
        $this->days = 0;

        $this->build()->rememberCurrent('default', null);
        self::assertSame([], $this->written);

        $this->build()->forget();
        self::assertSame([], $this->decode($this->written[0]['value']));
    }

    /**
     * A value that cannot be encoded keeps the list as it was rather than wiping it.
     */
    public function testAnUnencodablePairWipesNothing(): void
    {
        $this->setCurrent('websites', 1, "bad-\xB1-key", 'secret');
        $this->setStored('default', null, [$this->pair('kept', 1, time())]);

        $this->build()->rememberCurrent('websites', 1);

        self::assertSame([], $this->written);
    }

    /**
     * @param string $key
     * @param int $website
     * @param int $replacedAt
     * @return array
     */
    private function pair(string $key, int $website, int $replacedAt): array
    {
        return ['key' => $key, 'secret' => 's', 'replaced_at' => $replacedAt, 'website' => $website];
    }

    /**
     * What is stored is read defensively: it is a config value anyone with database access can edit.
     *
     * @dataProvider unreadableProvider
     */
    public function testUnreadableStorageMeansNoPairs(string $stored): void
    {
        $this->stored[PreviousCredentials::PATH . '|default|'] = $stored;

        self::assertSame([], $this->build()->getActive());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function unreadableProvider(): array
    {
        return [
            'not encrypted by us' => ['plain'],
            'not json' => ['enc:{'],
            'not a list of pairs' => ['enc:[1,"x",{"key":"k"}]'],
            'a pair with no time' => ['enc:[{"key":"k","secret":"s","website":0}]'],
            'a pair with no website' => ['enc:[{"key":"k","secret":"s","replaced_at":1}]'],
        ];
    }

    /**
     * @return PreviousCredentials
     */
    private function build(): PreviousCredentials
    {
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturnCallback(
            fn (string $path, string $scope = 'default', $scopeId = null) =>
                $this->stored[$path . '|' . $scope . '|' . $scopeId] ?? null
        );

        $writer = $this->createMock(WriterInterface::class);
        $writer->method('save')->willReturnCallback(
            function (string $path, $value, string $scope = 'default', int $scopeId = 0): void {
                $this->written[] = ['path' => $path, 'value' => (string)$value, 'scope' => $scope, 'scopeId' => $scopeId];
            }
        );

        $encryptor = $this->createMock(EncryptorInterface::class);
        $encryptor->method('encrypt')->willReturnCallback(fn (string $data) => 'enc:' . $data);
        $encryptor->method('decrypt')->willReturnCallback(
            fn (string $data) => str_starts_with($data, 'enc:') ? substr($data, 4) : ''
        );

        $config = $this->createMock(Config::class);
        $config->method('getPreviousCredentialsDays')->willReturnCallback(fn () => $this->days);

        $store = $this->createMock(StoreInterface::class);
        $store->method('getWebsiteId')->willReturn(1);
        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store);
        $storeManager->method('getWebsites')->willReturnCallback(fn () => array_map(function (int $id) {
            $website = $this->createMock(WebsiteInterface::class);
            $website->method('getId')->willReturn($id);

            return $website;
        }, $this->websites));
        $storeManager->method('getWebsite')->willReturnCallback(function ($code) {
            $website = $this->createMock(WebsiteInterface::class);
            $website->method('getId')->willReturn((int)$code);

            return $website;
        });

        return new PreviousCredentials($scopeConfig, $writer, $encryptor, $config, $storeManager);
    }

    /**
     * @param string $scope
     * @param int|null $scopeId
     * @param string $key
     * @param string $secret
     * @return void
     */
    private function setCurrent(string $scope, ?int $scopeId, string $key, string $secret): void
    {
        $this->stored[PreviousCredentials::KEY_PATH . '|' . $scope . '|' . $scopeId] = $key;
        $this->stored[PreviousCredentials::SECRET_PATH . '|' . $scope . '|' . $scopeId] = $secret;
    }

    /**
     * @param string $scope
     * @param int|null $scopeId
     * @param array $pairs
     * @return void
     */
    private function setStored(string $scope, ?int $scopeId, array $pairs): void
    {
        $this->stored[PreviousCredentials::PATH . '|' . $scope . '|' . $scopeId] = 'enc:' . json_encode($pairs);
    }

    /**
     * @param string $value
     * @return array
     */
    private function decode(string $value): array
    {
        return json_decode(substr($value, 4), true);
    }
}
