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
     * @param object|array|null $value
     * @return array|mixed
     */
    public function toArray($value)
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

        $data = [];
        foreach (\get_class_methods($value) as $method) {
            if (!\preg_match('/^get([A-Z].*)$/', $method, $m)) {
                continue;
            }
            $key = $m[1];
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
    public function fromArray(array $data, $target)
    {
        if (\is_string($target)) {
            $target = $this->objectManager->create($target);
        }

        if (!\is_object($target)) {
            throw new \InvalidArgumentException('Target must be an object or a valid class/interface name.');
        }

        foreach ($data as $key => $value) {
            $setter = 'set' . $key;
            if (!\method_exists($target, $setter)) {
                continue;
            }

            $ref = new \ReflectionMethod($target, $setter);
            $params = $ref->getParameters();
            if (!isset($params[0])) {
                continue;
            }
            $param = $params[0];
            $type = $param->getType();

            if ($type instanceof \ReflectionNamedType && !$type->isBuiltin() && \is_array($value)) {
                $className = $type->getName();
                $obj = $this->objectManager->create($className);
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
