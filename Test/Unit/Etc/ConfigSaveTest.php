<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Test\Unit\Etc;

use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\Initial;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Config\Value;
use Magento\Framework\Event\ManagerInterface as EventManager;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Model\Context;
use Magento\Framework\Registry;
use Magento\Framework\Serialize\Serializer\Json;
use PHPUnit\Framework\TestCase;
use Qliro\QliroOne\Model\Config;
use Qliro\QliroOne\Model\Security\PreviousCredentials;
use Qliro\QliroOne\Model\System\Config\Backend\LogRetentionDays;
use Qliro\QliroOne\Model\System\Config\Backend\Recurring\FrequencyOptions;
use Qliro\QliroOne\Plugin\Magento\Config\RememberReplacedCredentials;

/**
 * A configuration save hands the module every setting of every module, in whatever shape the
 * form posted it: text, nothing, or the array of a multiselect or a table field. None of them
 * may raise a PHP error, which Magento turns into a failed save.
 *
 * What takes part in a save is read from the module's own XML, so a new setting, plugin, observer
 * or backend model is covered, or fails here until it is.
 */
class ConfigSaveTest extends TestCase
{
    /** Magento types a configuration save goes through */
    private const SAVE_TYPES = [
        'Magento\Framework\App\Config\Value',
        'Magento\Framework\App\Config\ValueInterface',
        'Magento\Framework\App\Config\Storage\WriterInterface',
        'Magento\Framework\App\Config\Storage\Writer',
        'Magento\Framework\Model\AbstractModel',
        'Magento\Config\Model\Config',
        'Magento\Config\Model\ResourceModel\Config',
        'Magento\Config\Model\ResourceModel\Config\Data',
        'Magento\Config\Controller\Adminhtml\System\Config\Save',
        'Magento\Config\Model\PreparedValueFactory',
        'Magento\Framework\DB\Transaction',
        'Magento\Framework\App\Config\ReinitableConfigInterface',
    ];

    /** Plugins on those types, each run below with every setting and shape */
    private const SAVE_PLUGINS = [RememberReplacedCredentials::class];

    /** Backend models of the module's settings, each run below with every shape */
    private const BACKEND_MODELS = [LogRetentionDays::class, FrequencyOptions::class];

    /** Settings of other modules, the ones the reports named among them */
    private const FOREIGN_PATHS = [
        'payment/checkmo/specificcountry',
        'general/country/allow',
        'carriers/tablerate/import',
        'design/theme/ua_regexp',
        'some_vendor/some_group/some_field',
    ];

    private const SCOPES = [
        ['default', null, 0],
        ['websites', 'base', 1],
        ['stores', 'default', 1],
        [null, null, null],
    ];

    /**
     * @param string $path
     * @param mixed $posted
     * @dataProvider savedSettingProvider
     */
    public function testNoSettingInAnyShapeBreaksTheSavePlugin(string $path, mixed $posted): void
    {
        $watched = [
            PreviousCredentials::KEY_PATH,
            PreviousCredentials::SECRET_PATH,
            PreviousCredentials::TYPE_PATH,
            'payment/qliroone/' . Config::QLIROONE_PREVIOUS_CREDENTIALS_DAYS,
        ];

        foreach (self::SCOPES as [$scope, $scopeCode, $scopeId]) {
            foreach (['', '0', 'old', '["SE","NO"]'] as $old) {
                foreach (self::SAVE_PLUGINS as $class) {
                    $plugin = $this->plugin($class, in_array($path, $watched, true));

                    foreach (self::pluginMethods($class) as $method) {
                        $subject = $this->value($path, $old, $posted, $scope, $scopeCode, $scopeId);
                        // The second argument is the result for an after plugin, proceed for an around one
                        $second = str_starts_with($method, 'around') ? static fn() => $subject : $subject;

                        $this->withoutPhpErrors(static function () use ($plugin, $method, $subject, $second): void {
                            $plugin->$method($subject, $second);
                        });
                    }
                }
            }
        }

        $this->addToAssertionCount(1);
    }

    /**
     * Every setting of the module and a few of other modules, in every shape a form posts
     *
     * @return iterable<string, array{string, mixed}>
     */
    public static function savedSettingProvider(): iterable
    {
        $paths = array_merge(array_keys(self::settings()), self::FOREIGN_PATHS);

        foreach ($paths as $path) {
            foreach (self::shapes() as $name => $shape) {
                yield $path . ', ' . $name => [$path, $shape];
            }
        }
    }

    /**
     * Every method of a plugin is a hook, so every public one is run
     *
     * @param class-string $class
     * @return string[]
     */
    private static function pluginMethods(string $class): array
    {
        $methods = [];

        foreach ((new \ReflectionClass($class))->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            if (!$method->isConstructor()) {
                self::assertMatchesRegularExpression('/^(before|after|around)[A-Z]/', $method->getName());
                $methods[] = $method->getName();
            }
        }

        self::assertNotEmpty($methods, $class);

        return $methods;
    }

    /**
     * @param class-string $class
     * @param bool $watched Whether the setting is one the plugin acts on
     * @return object
     */
    private function plugin(string $class, bool $watched): object
    {
        // A new plugin has no arm here and fails until it gets one
        return match ($class) {
            RememberReplacedCredentials::class => (function () use ($watched): RememberReplacedCredentials {
                $previous = $this->createMock(PreviousCredentials::class);
                $previous->expects($watched ? self::any() : self::never())->method(self::anything());
                $initial = $this->createMock(Initial::class);
                $initial->method('getData')->willReturn(
                    ['payment' => ['qliroone' => ['qliro_api' => ['type' => 'sandbox']]]]
                );

                return new RememberReplacedCredentials($previous, $initial);
            })(),
        };
    }

    /**
     * The provider above must see the whole form, or a broken reader would pass everything
     */
    public function testEverySettingOfTheFormIsRead(): void
    {
        $settings = self::settings();
        $xml = (string)file_get_contents(self::root() . '/etc/adminhtml/system.xml');

        // The <field> elements under <depends> name a condition, not a setting
        $declared = preg_match_all('/<field\s[^>]*\btranslate=/', $xml);

        self::assertGreaterThan(50, $declared);
        self::assertCount($declared, $settings, 'Two settings of system.xml share a path, or one was not read');
        self::assertArrayHasKey(PreviousCredentials::KEY_PATH, $settings);
        self::assertArrayHasKey(PreviousCredentials::SECRET_PATH, $settings);
        self::assertArrayHasKey(PreviousCredentials::TYPE_PATH, $settings);
        self::assertArrayHasKey('payment/qliroone/' . Config::QLIROONE_PREVIOUS_CREDENTIALS_DAYS, $settings);
    }

    /**
     * A new plugin on the save path is called for every setting too: list it in SAVE_PLUGINS and
     * run it in testNoSettingInAnyShapeBreaksTheSavePlugin.
     */
    public function testEveryPluginOnTheSavePathIsCovered(): void
    {
        $found = [];

        foreach (self::xmlFiles('di.xml') as $file) {
            foreach (self::load($file)->xpath('//type[plugin]') ?: [] as $type) {
                if (!self::takesPartInASave((string)$type['name'])) {
                    continue;
                }

                foreach ($type->plugin as $plugin) {
                    if ((string)$plugin['disabled'] !== 'true') {
                        $found[] = ltrim((string)$plugin['type'], '\\');
                    }
                }
            }
        }

        sort($found);
        $expected = self::SAVE_PLUGINS;
        sort($expected);

        self::assertSame($expected, $found);
    }

    /**
     * A replaced class of the save path would run the module's code for every setting as well
     */
    public function testNoClassOfTheSavePathIsReplaced(): void
    {
        $found = [];

        foreach (self::xmlFiles('di.xml') as $file) {
            foreach (self::load($file)->xpath('//preference') ?: [] as $preference) {
                if (self::takesPartInASave((string)$preference['for'])) {
                    $found[] = (string)$preference['for'];
                }
            }
        }

        self::assertSame([], $found);
    }

    /**
     * One of SAVE_TYPES, or a backend model of some setting, Magento's own included
     *
     * @param string $type
     * @return bool
     */
    private static function takesPartInASave(string $type): bool
    {
        $type = ltrim($type, '\\');

        return in_array($type, self::SAVE_TYPES, true)
            || is_a($type, Value::class, true)
            || is_a($type, \Magento\Framework\App\Config\ValueInterface::class, true);
    }

    /**
     * An observer of a configuration save, or of every model save, sees every setting as well.
     * The module has none: one added later needs its own run with every shape here.
     */
    public function testNoObserverListensToAConfigurationSave(): void
    {
        $found = [];

        foreach (self::xmlFiles('events.xml') as $file) {
            foreach (self::load($file)->xpath('//event') ?: [] as $event) {
                $name = (string)$event['name'];

                if (preg_match('/config|^model_(save|delete)_|^core_abstract_/', $name)) {
                    $found[] = $name;
                }
            }
        }

        self::assertSame([], $found);
    }

    /**
     * @param class-string<Value> $class
     * @param mixed $posted
     * @dataProvider backendModelProvider
     */
    public function testNoShapeBreaksABackendModel(string $class, mixed $posted): void
    {
        $backend = $this->backend($class);
        $backend->setValue($posted);

        $refused = false;

        $this->withoutPhpErrors(static function () use ($backend, &$refused): void {
            try {
                $backend->beforeSave();
            } catch (LocalizedException) {
                // A refusal the admin reads on the form is an answer, not a failure
                $refused = true;
            }
        });

        if ($refused) {
            $this->addToAssertionCount(1);

            return;
        }

        // What is not refused goes to a text column
        self::assertTrue(is_scalar($backend->getValue()) || $backend->getValue() === null);

        // and is read back when the section opens
        $this->withoutPhpErrors(static function () use ($backend): void {
            (new \ReflectionMethod($backend, '_afterLoad'))->invoke($backend);
        });
    }

    /**
     * @return iterable<string, array{class-string<Value>, mixed}>
     */
    public static function backendModelProvider(): iterable
    {
        foreach (self::BACKEND_MODELS as $class) {
            foreach (self::shapes() as $name => $shape) {
                yield substr((string)strrchr($class, '\\'), 1) . ', ' . $name => [$class, $shape];
            }
        }
    }

    public function testEveryBackendModelOfTheModuleIsCovered(): void
    {
        $declared = array_column(self::settings(), 'backend');

        foreach (self::load(self::root() . '/etc/config.xml')->xpath('//*[@backend_model]') ?: [] as $node) {
            $declared[] = ltrim((string)$node['backend_model'], '\\');
        }

        $found = array_values(array_unique(array_filter(
            $declared,
            static fn(string $class): bool => str_starts_with($class, 'Qliro\\')
        )));

        sort($found);
        $expected = self::BACKEND_MODELS;
        sort($expected);

        self::assertSame($expected, $found);
    }

    /**
     * @return array<string, mixed>
     */
    private static function shapes(): array
    {
        return [
            'text' => 'text',
            'a number as text' => '30',
            'zero' => '0',
            'nothing typed' => '',
            'asterisks' => '******',
            'no value' => null,
            'an integer' => 30,
            'a float' => 1.5,
            'a boolean' => false,
            'a multiselect' => ['SE', 'NO'],
            'an emptied multiselect' => [],
            'a table field' => ['_1' => ['value' => '1', 'label' => 'Weekly'], '__empty' => ''],
            'a file upload' => ['name' => 'rates.csv', 'tmp_name' => '/tmp/php1', 'error' => 0, 'size' => 1],
        ];
    }

    /**
     * Every setting of etc/adminhtml/system.xml by its config path
     *
     * @return array<string, array{backend: string}>
     */
    private static function settings(): array
    {
        $settings = [];
        $walk = static function (\SimpleXMLElement $node, string $prefix) use (&$walk, &$settings): void {
            foreach ($node->group as $group) {
                $walk($group, $prefix . '/' . $group['id']);
            }

            foreach ($node->field as $field) {
                $path = (string)$field->config_path ?: $prefix . '/' . $field['id'];
                $settings[$path] = ['backend' => ltrim((string)$field->backend_model, '\\')];
            }
        };

        foreach (self::load(self::root() . '/etc/adminhtml/system.xml')->system->section as $section) {
            $walk($section, (string)$section['id']);
        }

        return $settings;
    }

    /**
     * @param string $name
     * @return string[]
     */
    private static function xmlFiles(string $name): array
    {
        return array_merge(
            glob(self::root() . '/etc/' . $name) ?: [],
            glob(self::root() . '/etc/*/' . $name) ?: []
        );
    }

    private static function load(string $file): \SimpleXMLElement
    {
        $xml = simplexml_load_string((string)file_get_contents($file));
        self::assertNotFalse($xml, $file);

        return $xml;
    }

    private static function root(): string
    {
        return dirname(__DIR__, 3);
    }

    /**
     * Runs the callback with every PHP warning, notice and deprecation thrown, as Magento does
     *
     * @param callable(): void $callback
     * @return void
     */
    private function withoutPhpErrors(callable $callback): void
    {
        set_error_handler(static function (int $code, string $message, string $file, int $line): never {
            throw new \ErrorException($message, 0, $code, $file, $line);
        });

        try {
            $callback();
        } finally {
            restore_error_handler();
        }
    }

    /**
     * @param string $path
     * @param string $old
     * @param mixed $posted
     * @param string|null $scope
     * @param string|null $scopeCode
     * @param int|null $scopeId
     * @return Value
     */
    private function value(
        string $path,
        string $old,
        mixed $posted,
        ?string $scope,
        ?string $scopeCode,
        ?int $scopeId
    ): Value {
        $value = $this->getMockBuilder(Value::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getOldValue'])
            ->getMock();
        $value->method('getOldValue')->willReturn($old);
        $value->setData([
            'path' => $path,
            'value' => $posted,
            'scope' => $scope,
            'scope_code' => $scopeCode,
            'scope_id' => $scopeId,
        ]);

        return $value;
    }

    /**
     * @param class-string<Value> $class
     * @return Value
     */
    private function backend(string $class): Value
    {
        $context = $this->createMock(Context::class);
        $context->method('getEventDispatcher')->willReturn($this->createMock(EventManager::class));
        $arguments = [
            $context,
            $this->createMock(Registry::class),
            $this->createMock(ScopeConfigInterface::class),
            $this->createMock(TypeListInterface::class),
        ];

        return match ($class) {
            LogRetentionDays::class => new LogRetentionDays(...[...$arguments, null, null, [], new Config(
                $this->createMock(\Magento\Payment\Model\Method\Adapter::class),
                $this->createMock(ScopeConfigInterface::class),
                $this->createMock(Json::class),
                $this->createMock(\Magento\Directory\Helper\Data::class),
                $this->createMock(\Magento\Directory\Model\ResourceModel\Country\CollectionFactory::class)
            )]),
            FrequencyOptions::class => new FrequencyOptions(...[...$arguments, new Json()]),
        };
    }
}
