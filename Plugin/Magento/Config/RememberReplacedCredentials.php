<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Plugin\Magento\Config;

use Magento\Framework\App\Config\Initial;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Config\Value;
use Qliro\QliroOne\Model\Config;
use Qliro\QliroOne\Model\Security\PreviousCredentials;

/**
 * Keeps the API key and secret pair a save or a "Use Default" is about to replace
 *
 * A plugin rather than backend models: the secret keeps Magento's own encrypted backend, which is
 * what an encryption key change re-encrypts.
 */
class RememberReplacedCredentials
{
    private const DAYS_PATH = 'payment/qliroone/' . Config::QLIROONE_PREVIOUS_CREDENTIALS_DAYS;

    /**
     * @param PreviousCredentials $previousCredentials
     * @param Initial $initialConfig
     */
    public function __construct(
        private PreviousCredentials $previousCredentials,
        private Initial $initialConfig
    ) {
    }

    /**
     * @param Value $subject
     * @return void
     */
    public function beforeBeforeSave(Value $subject): void
    {
        $path = (string)$subject->getPath();
        $value = (string)$subject->getValue();
        [$scope, $scopeCode] = $this->scopeOf($subject);

        switch ($path) {
            case self::DAYS_PATH:
                // Turning the window off drops what was kept, so turning it on later revives nothing
                if (ctype_digit(trim($value)) && (int)$value === 0 && $value !== $subject->getOldValue()) {
                    $this->previousCredentials->forget();
                }
                break;

            case PreviousCredentials::TYPE_PATH:
                if ($value !== $subject->getOldValue()) {
                    $this->previousCredentials->forget($scope, $scopeCode, true);
                }
                break;

            case PreviousCredentials::KEY_PATH:
            case PreviousCredentials::SECRET_PATH:
                // Asterisks are the obscured secret sent back untouched
                $obscured = $path === PreviousCredentials::SECRET_PATH && preg_match('/^\*+$/', $value);

                if (!$obscured && $value !== $subject->getOldValue()) {
                    $this->previousCredentials->rememberCurrent($scope, $scopeCode, $path);
                }
                break;
        }
    }

    /**
     * "Use Default" on a website, or "Use system value" on the default scope
     *
     * @param Value $subject
     * @return void
     */
    public function beforeBeforeDelete(Value $subject): void
    {
        $path = (string)$subject->getPath();
        [$scope, $scopeCode] = $this->scopeOf($subject);
        $watched = [PreviousCredentials::KEY_PATH, PreviousCredentials::SECRET_PATH, PreviousCredentials::TYPE_PATH];

        if (!in_array($path, $watched, true)) {
            return;
        }

        // A website saved with "Use Default" ticked deletes on every save, own value or not
        if ($scope !== ScopeConfigInterface::SCOPE_TYPE_DEFAULT
            && !$this->previousCredentials->overridesDefault($path, $scope, $scopeCode)
        ) {
            return;
        }

        if ($path !== PreviousCredentials::TYPE_PATH) {
            // An empty pair, as when nothing was stored, keeps nothing
            $this->previousCredentials->rememberCurrent($scope, $scopeCode, $path);
        } elseif ($scope !== ScopeConfigInterface::SCOPE_TYPE_DEFAULT
            // At the default scope "Use system value" deletes on every save, changed or not
            || $subject->getOldValue() !== $this->systemValue($path)
        ) {
            $this->previousCredentials->forget($scope, $scopeCode, true);
        }
    }

    /**
     * The value "Use system value" restores, from the modules' config.xml
     *
     * @param string $path
     * @return string
     */
    private function systemValue(string $path): string
    {
        $value = $this->initialConfig->getData(ScopeConfigInterface::SCOPE_TYPE_DEFAULT);

        foreach (explode('/', $path) as $part) {
            $value = is_array($value) ? ($value[$part] ?? null) : null;
        }

        return is_scalar($value) ? (string)$value : '';
    }

    /**
     * @param Value $subject
     * @return array{0: string, 1: int|string|null}
     */
    private function scopeOf(Value $subject): array
    {
        return [
            (string)($subject->getScope() ?: ScopeConfigInterface::SCOPE_TYPE_DEFAULT),
            $subject->getScopeCode() ?: $subject->getScopeId(),
        ];
    }
}
