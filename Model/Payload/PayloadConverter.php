<?php
/**
 * Lightweight payload converter for QliroOne.
 *
 * Converts simple DTOs to arrays using public getters (getXxx) and can hydrate DTOs from arrays using setters (setXxx).
 * NOTE: This intentionally avoids ContainerInterface + ContainerMapper reflection/docblock magic.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Model\Payload;

use Magento\Framework\ObjectManagerInterface;

final class PayloadConverter
{
    /**
     * Cached map of class => [getterMethod => key] for toArray().
     * Avoids re-scanning class methods via get_class_methods on every call.
     *
     * @var array<string, array<string, string>>
     */
    private static array $getterCache = [];

    /**
     * Cached setter type info for fromArray().
     * Maps 'ClassName::setFoo' => 'TypeName' (non-builtin object type) or false (builtin / no type).
     *
     * @var array<string, string|false>
     */
    private static array $setterTypeCache = [];

    /**
     * Class constructor
     *
     * @param ObjectManagerInterface $objectManager
     */
    public function __construct(
        private readonly ObjectManagerInterface $objectManager
    ) {
    }

    /**
     * Convert an object (and nested objects/arrays) into an array using getXxx methods.
     * Keys are the getter suffix, e.g. getCurrency() => ['Currency' => ...]
     *
     * @param mixed $value
     * @return mixed
     */
    public function toArray(mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }

        if (\is_array($value)) {
            $out = [];
            foreach ($value as $k => $v) {
                $out[$k] = $this->toArray($v);
            }
            return $out;
        }

        if (!\is_object($value)) {
            return $value;
        }

        $class = \get_class($value);
        if (!isset(self::$getterCache[$class])) {
            $getters = [];
            foreach (\get_class_methods($value) as $method) {
                if (\preg_match('/^get([A-Z].*)$/', $method, $m)) {
                    $getters[$method] = $m[1];
                }
            }
            self::$getterCache[$class] = $getters;
        }

        $data = [];
        foreach (self::$getterCache[$class] as $method => $key) {
            try {
                $v = $value->$method();
            } catch (\Throwable $e) {
                continue;
            }
            if ($v === null) {
                continue;
            }
            $data[$key] = $this->toArray($v);
        }

        return $data;
    }

    /**
     * Hydrate a DTO from array using setXxx methods.
     * Supports interface names - Magento ObjectManager resolves them to concrete classes.
     *
     * @param array $data
     * @param object|string $target  Either an object instance or a class/interface name string
     * @return object
     */
    public function fromArray(array $data, object|string $target): object
    {
        if (\is_string($target)) {
            $target = $this->objectManager->create($target);
        }

        if (!\is_object($target)) {
            throw new \InvalidArgumentException('Target must be an object or a valid class/interface name.');
        }

        $class = \get_class($target);

        foreach ($data as $key => $value) {
            $setter = 'set' . $key;
            if (!\method_exists($target, $setter)) {
                continue;
            }

            $cacheKey = $class . '::' . $setter;
            if (!\array_key_exists($cacheKey, self::$setterTypeCache)) {
                $ref    = new \ReflectionMethod($target, $setter);
                $params = $ref->getParameters();
                if (!isset($params[0])) {
                    self::$setterTypeCache[$cacheKey] = false;
                } else {
                    $type = $params[0]->getType();
                    self::$setterTypeCache[$cacheKey] = (
                        $type instanceof \ReflectionNamedType && !$type->isBuiltin()
                    ) ? $type->getName() : false;
                }
            }

            $objectType = self::$setterTypeCache[$cacheKey];
            if ($objectType !== false && \is_array($value)) {
                $obj = $this->objectManager->create($objectType);
                $this->fromArray($value, $obj);
                $target->$setter($obj);
                continue;
            }

            // Arrays of objects are passed as-is; callers should build DTOs explicitly when needed.
            $target->$setter($value);
        }

        return $target;
    }
}
