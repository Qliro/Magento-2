<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Test\Unit\Plugin\Magento\Config;

use Magento\Framework\App\Config\Initial;
use Magento\Framework\App\Config\Value;
use PHPUnit\Framework\TestCase;
use Qliro\QliroOne\Model\Security\PreviousCredentials;
use Qliro\QliroOne\Plugin\Magento\Config\RememberReplacedCredentials;

/**
 * @see \Qliro\QliroOne\Plugin\Magento\Config\RememberReplacedCredentials
 */
class RememberReplacedCredentialsTest extends TestCase
{
    private const DAYS_PATH = 'payment/qliroone/callback/previous_credentials_days';

    /**
     * A changed key or secret keeps the pair it replaces; an untouched one, the obscured secret
     * sent back, or any other setting keeps nothing.
     *
     * @dataProvider saveProvider
     */
    public function testASaveKeepsThePairOnlyWhenACredentialChanges(
        string $path,
        string $old,
        string $posted,
        bool $remembered
    ): void {
        $previous = $this->createMock(PreviousCredentials::class);
        $previous->expects($remembered ? self::once() : self::never())
            ->method('rememberCurrent')
            ->with('websites', 'base', $path);

        ($this->plugin($previous))->beforeBeforeSave($this->value($path, $old, $posted));
    }

    /**
     * @return array<string, array{string, string, string, bool}>
     */
    public static function saveProvider(): array
    {
        return [
            'a new key' => [PreviousCredentials::KEY_PATH, 'old', 'new', true],
            'the same key' => [PreviousCredentials::KEY_PATH, 'same', 'same', false],
            'a new secret' => [PreviousCredentials::SECRET_PATH, 'old', 'new', true],
            'a cleared secret' => [PreviousCredentials::SECRET_PATH, 'old', '', true],
            'the obscured secret sent back' => [PreviousCredentials::SECRET_PATH, 'old', '******', false],
            'the same secret typed again' => [PreviousCredentials::SECRET_PATH, 'old', 'old', false],
            'another setting' => ['payment/qliroone/active', '0', '1', false],
        ];
    }

    /**
     * "Use Default" on a website keeps the pair only when the website had a value of its own.
     *
     * @dataProvider deleteProvider
     */
    public function testUseDefaultKeepsThePairOnlyForAnOwnValue(bool $own, bool $remembered): void
    {
        $previous = $this->createMock(PreviousCredentials::class);
        $previous->method('overridesDefault')->with(PreviousCredentials::SECRET_PATH, 'websites', 'base')->willReturn($own);
        $previous->expects($remembered ? self::once() : self::never())->method('rememberCurrent');

        ($this->plugin($previous))
            ->beforeBeforeDelete($this->value(PreviousCredentials::SECRET_PATH, 'x', 'x'));
    }

    /**
     * @return array<string, array{bool, bool}>
     */
    public static function deleteProvider(): array
    {
        return [
            'its own secret' => [true, true],
            'the default one' => [false, false],
        ];
    }

    /**
     * Turning the window off drops the kept pairs; any other window keeps them.
     */
    public function testTurningTheWindowOffForgetsThePairs(): void
    {
        $previous = $this->createMock(PreviousCredentials::class);
        $previous->expects(self::exactly(2))->method('forget');
        $previous->expects(self::never())->method('rememberCurrent');

        $plugin = $this->plugin($previous);
        $plugin->beforeBeforeSave($this->value(self::DAYS_PATH, '90', '0'));
        $plugin->beforeBeforeSave($this->value(self::DAYS_PATH, '0', '00'));
        $plugin->beforeBeforeSave($this->value(self::DAYS_PATH, '0', '30'));
    }

    /**
     * A new API type drops the website's pairs; the same type saved again drops nothing.
     */
    public function testANewApiTypeForgetsTheWebsitePairs(): void
    {
        $previous = $this->createMock(PreviousCredentials::class);
        $previous->expects(self::once())->method('forget')->with('websites', 'base', true);

        $plugin = $this->plugin($previous);
        $plugin->beforeBeforeSave($this->value(PreviousCredentials::TYPE_PATH, 'sandbox', 'prod'));
        $plugin->beforeBeforeSave($this->value(PreviousCredentials::TYPE_PATH, 'prod', 'prod'));
    }

    /**
     * "Use system value" on the API type at the default scope drops the pairs only when it changes
     * the type: the box ticked deletes on every save.
     */
    public function testUseSystemValueOnTheTypeForgetsOnlyARealChange(): void
    {
        $previous = $this->createMock(PreviousCredentials::class);
        $previous->expects(self::once())->method('forget')->with('default', self::anything(), true);

        $plugin = $this->plugin($previous, 'sandbox');
        $plugin->beforeBeforeDelete($this->value(PreviousCredentials::TYPE_PATH, 'prod', '', 'default'));
        $plugin->beforeBeforeDelete($this->value(PreviousCredentials::TYPE_PATH, 'sandbox', '', 'default'));
    }

    /**
     * @param PreviousCredentials $previous
     * @param string $systemType
     * @return RememberReplacedCredentials
     */
    private function plugin(PreviousCredentials $previous, string $systemType = 'sandbox'): RememberReplacedCredentials
    {
        $initial = $this->createMock(Initial::class);
        $initial->method('getData')->willReturn(
            ['payment' => ['qliroone' => ['qliro_api' => ['type' => $systemType]]]]
        );

        return new RememberReplacedCredentials($previous, $initial);
    }

    /**
     * @param string $path
     * @param string $old
     * @param string $posted
     * @param string $scope
     * @return Value
     */
    private function value(string $path, string $old, string $posted, string $scope = 'websites'): Value
    {
        $value = $this->getMockBuilder(Value::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getOldValue'])
            ->getMock();
        $value->method('getOldValue')->willReturn($old);
        $value->setData([
            'path' => $path,
            'value' => $posted,
            'scope' => $scope,
            'scope_code' => $scope === 'default' ? null : 'base',
            'scope_id' => 1,
        ]);

        return $value;
    }
}
