<?php

declare(strict_types=1);

namespace App\Domain\Agents\Services;

final class JsonSchemaValidator
{
    /**
     * Validates an associative payload against a strict subset of JSON Schema.
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $schema
     * @return list<string> Validation error messages (empty if valid)
     */
    public function validate(array $data, array $schema): array
    {
        $errors = [];

        /** @var list<string> $required */
        $required = is_array($schema['required'] ?? null) ? $schema['required'] : [];
        foreach ($required as $field) {
            if (! array_key_exists($field, $data) || $data[$field] === null) {
                $errors[] = "Missing required argument '{$field}'.";
            }
        }

        /** @var array<string, array<string, mixed>> $properties */
        $properties = is_array($schema['properties'] ?? null) ? $schema['properties'] : [];
        $allowAdditional = ($schema['additionalProperties'] ?? true) !== false;

        foreach ($data as $key => $value) {
            if (! array_key_exists($key, $properties)) {
                if (! $allowAdditional) {
                    $errors[] = "Unexpected argument '{$key}' is not permitted by tool schema.";
                }

                continue;
            }

            if ($value === null) {
                continue;
            }

            $propSchema = $properties[$key];
            $expectedType = is_string($propSchema['type'] ?? null) ? $propSchema['type'] : null;

            if ($expectedType !== null && ! $this->matchesType($value, $expectedType)) {
                $errors[] = "Argument '{$key}' must be of type '{$expectedType}'.";

                continue;
            }

            if ($expectedType === 'string' && is_string($value)) {
                if (isset($propSchema['minLength']) && mb_strlen($value) < (int) $propSchema['minLength']) {
                    $errors[] = "Argument '{$key}' must be at least {$propSchema['minLength']} characters.";
                }
                if (isset($propSchema['maxLength']) && mb_strlen($value) > (int) $propSchema['maxLength']) {
                    $errors[] = "Argument '{$key}' must not exceed {$propSchema['maxLength']} characters.";
                }
                if (isset($propSchema['pattern']) && is_string($propSchema['pattern']) && preg_match($propSchema['pattern'], $value) !== 1) {
                    $errors[] = "Argument '{$key}' does not match required format.";
                }
            }

            if (($expectedType === 'integer' || $expectedType === 'number') && (is_int($value) || is_float($value))) {
                if (isset($propSchema['minimum']) && $value < $propSchema['minimum']) {
                    $errors[] = "Argument '{$key}' must be >= {$propSchema['minimum']}.";
                }
                if (isset($propSchema['maximum']) && $value > $propSchema['maximum']) {
                    $errors[] = "Argument '{$key}' must be <= {$propSchema['maximum']}.";
                }
            }

            if (isset($propSchema['enum']) && is_array($propSchema['enum']) && ! in_array($value, $propSchema['enum'], true)) {
                $errors[] = "Argument '{$key}' must be one of: ".implode(', ', array_map('strval', $propSchema['enum'])).'.';
            }

            if ($expectedType === 'array' && is_array($value)) {
                if (isset($propSchema['minItems']) && count($value) < (int) $propSchema['minItems']) {
                    $errors[] = "Argument '{$key}' must contain at least {$propSchema['minItems']} items.";
                }
                if (isset($propSchema['maxItems']) && count($value) > (int) $propSchema['maxItems']) {
                    $errors[] = "Argument '{$key}' must contain at most {$propSchema['maxItems']} items.";
                }
                if (isset($propSchema['items']['type']) && is_string($propSchema['items']['type'])) {
                    $itemType = $propSchema['items']['type'];
                    foreach ($value as $idx => $item) {
                        if (! $this->matchesType($item, $itemType)) {
                            $errors[] = "Argument '{$key}[{$idx}]' must be of type '{$itemType}'.";
                        }
                    }
                }
            }
        }

        return $errors;
    }

    private function matchesType(mixed $value, string $expectedType): bool
    {
        return match ($expectedType) {
            'string' => is_string($value),
            'integer' => is_int($value),
            'number' => is_int($value) || is_float($value),
            'boolean' => is_bool($value),
            'array' => is_array($value) && array_is_list($value),
            'object' => is_array($value),
            default => true,
        };
    }
}
