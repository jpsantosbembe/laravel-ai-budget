<?php

namespace Bembe\AiBudget\Schema;

/**
 * A deliberately small JSON Schema validator: type, required, properties,
 * items and enum, recursively.
 *
 * That subset is what structured LLM output actually uses, and keeping it here
 * means the package has no schema dependency. If you need the full spec (refs,
 * allOf/anyOf, formats, numeric bounds), point `ai-budget.schema_validator` at
 * your own implementation wrapping a real validator.
 */
class SubsetSchemaValidator implements SchemaValidator
{
    /**
     * @param  array<string, mixed>  $schema
     */
    public function matches(mixed $value, array $schema): bool
    {
        if (isset($schema['enum']) && is_array($schema['enum']) && ! in_array($value, $schema['enum'], true)) {
            return false;
        }

        $type = $schema['type'] ?? null;

        if (is_string($type) && ! $this->matchesType($value, $type)) {
            return false;
        }

        if (($type === 'object' || isset($schema['properties'])) && is_array($value)) {
            foreach ((array) ($schema['required'] ?? []) as $requiredKey) {
                if (! array_key_exists($requiredKey, $value)) {
                    return false;
                }
            }

            foreach ((array) ($schema['properties'] ?? []) as $key => $propertySchema) {
                if (array_key_exists($key, $value) && is_array($propertySchema)) {
                    if (! $this->matches($value[$key], $propertySchema)) {
                        return false;
                    }
                }
            }
        }

        if ($type === 'array' && is_array($value) && isset($schema['items']) && is_array($schema['items'])) {
            foreach ($value as $item) {
                if (! $this->matches($item, $schema['items'])) {
                    return false;
                }
            }
        }

        return true;
    }

    private function matchesType(mixed $value, string $type): bool
    {
        return match ($type) {
            'object' => is_array($value),
            'array' => is_array($value) && array_is_list($value),
            'string' => is_string($value),
            'integer' => is_int($value),
            'number' => is_int($value) || is_float($value),
            'boolean' => is_bool($value),
            'null' => $value === null,
            default => true,
        };
    }
}
