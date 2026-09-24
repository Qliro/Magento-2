<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Test\Unit\Controller;

use Magento\Backend\App\AbstractAction as BackendAction;
use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\RequestInterface;
use PHPUnit\Framework\TestCase;
use Qliro\QliroOne\Controller\Qliro\Callback\CheckoutStatus;
use Qliro\QliroOne\Controller\Qliro\Callback\MerchantNotification;
use Qliro\QliroOne\Controller\Qliro\Callback\SavedCreditCard;
use Qliro\QliroOne\Controller\Qliro\Callback\ShippingMethods;
use Qliro\QliroOne\Controller\Qliro\Callback\TransactionStatus;
use Qliro\QliroOne\Controller\Qliro\Callback\Validate;

/**
 * PLIN-370: the controllers are on the action interfaces, and the callbacks declare their own
 * CSRF exemption now that the plugin matching them by route name is gone.
 */
class ActionInterfacesTest extends TestCase
{
    /**
     * These extend Magento's own checkout controllers, which still sit on the deprecated base
     */
    private const CORE_CHECKOUT_PAGES = [
        \Qliro\QliroOne\Controller\Qliro\Index::class,
        \Qliro\QliroOne\Controller\Qliro\Pending::class,
        \Qliro\QliroOne\Controller\Qliro\Success::class,
    ];

    public function testNoControllerExtendsTheDeprecatedActionBase(): void
    {
        $offenders = [];

        foreach ($this->controllerClasses() as $class) {
            $reflection = new \ReflectionClass($class);

            if (in_array($class, self::CORE_CHECKOUT_PAGES, true) || $reflection->isInterface()) {
                continue;
            }

            // The admin base is the supported one, it carries the ACL check
            if (is_subclass_of($class, Action::class) && !is_subclass_of($class, BackendAction::class)) {
                $offenders[] = $class;
            }
        }

        self::assertSame([], $offenders);
    }

    /**
     * The storefront controllers are the ones the buyer's browser calls, and each needs the plugin
     * that keeps the vary cookie, or a buyer in a second currency gets cached pages in the default
     */
    public function testEveryStorefrontControllerKeepsTheVaryCookie(): void
    {
        $xml = simplexml_load_file(dirname(__DIR__, 3) . '/etc/frontend/di.xml');
        $covered = [];

        foreach ($xml->xpath('//type[plugin[@name="qliroone_keep_vary_cookie"]]') as $type) {
            $covered[] = (string)$type['name'];
        }

        $missing = [];

        foreach ($this->controllerClasses() as $class) {
            $reflection = new \ReflectionClass($class);

            if ($reflection->isAbstract()
                || in_array($class, self::CORE_CHECKOUT_PAGES, true)
                || str_contains($class, '\\Adminhtml\\')
                || str_contains($class, '\\Callback\\')
            ) {
                continue;
            }

            $isCovered = false;

            foreach ($covered as $type) {
                $isCovered = $isCovered || $class === $type || is_subclass_of($class, $type);
            }

            if (!$isCovered) {
                $missing[] = $class;
            }
        }

        self::assertSame([], $missing);
    }

    /**
     * @dataProvider callbackProvider
     */
    public function testCallbackAcceptsAPostWithoutAFormKey(string $class): void
    {
        $controller = (new \ReflectionClass($class))->newInstanceWithoutConstructor();
        $request = $this->createMock(RequestInterface::class);

        self::assertInstanceOf(HttpPostActionInterface::class, $controller);
        self::assertInstanceOf(CsrfAwareActionInterface::class, $controller);
        self::assertTrue($controller->validateForCsrf($request));
        self::assertNull($controller->createCsrfValidationException($request));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function callbackProvider(): array
    {
        return [
            'checkoutStatus' => [CheckoutStatus::class],
            'merchantNotification' => [MerchantNotification::class],
            'savedCreditCard' => [SavedCreditCard::class],
            'shippingMethods' => [ShippingMethods::class],
            'transactionStatus' => [TransactionStatus::class],
            'validate' => [Validate::class],
        ];
    }

    /**
     * Every class under Controller/, read off the file tree so a new one cannot be missed
     *
     * @return string[]
     */
    private function controllerClasses(): array
    {
        $root = dirname(__DIR__, 3) . '/Controller';
        $classes = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));

        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $relative = substr($file->getPathname(), strlen($root) + 1, -4);
            $classes[] = 'Qliro\\QliroOne\\Controller\\' . str_replace('/', '\\', $relative);
        }

        self::assertNotEmpty($classes);

        return $classes;
    }
}
