<?php

declare(strict_types=1);

namespace App\Reports\Blocks;

/**
 * Validates a block layout (the `blocks` JSON on report templates/definitions,
 * CLAUDE.md §10.2). Guarantees a well-formed, uniquely-identified, correctly-typed
 * list of blocks before it is persisted or rendered. The AI builder's output is
 * validated through here too, so it can never emit a malformed layout (§10.6).
 */
final class BlocksValidator
{
    /**
     * @return list<Block>
     *
     * @throws BlockValidationException
     */
    public function validate(mixed $blocks): array
    {
        if (! is_array($blocks) || ! array_is_list($blocks)) {
            throw new BlockValidationException(['Blocks must be a JSON array.']);
        }

        $errors = [];
        $seenIds = [];
        $result = [];

        foreach ($blocks as $index => $raw) {
            if (! is_array($raw)) {
                $errors[] = "Block #{$index} must be an object.";

                continue;
            }

            $id = $raw['id'] ?? null;
            $label = is_string($id) && $id !== '' ? "'{$id}'" : "#{$index}";

            if (! is_string($id) || $id === '') {
                $errors[] = "Block {$label} is missing a non-empty string 'id'.";
            } elseif (isset($seenIds[$id])) {
                $errors[] = "Duplicate block id '{$id}'.";
            } else {
                $seenIds[$id] = true;
            }

            $typeValue = $raw['type'] ?? null;
            $type = is_string($typeValue) ? BlockType::tryFrom($typeValue) : null;

            if ($type === null) {
                $errors[] = "Block {$label} has an unknown or missing 'type'.";
            }

            $binding = $raw['binding'] ?? null;
            if ($binding !== null && ! is_array($binding)) {
                $errors[] = "Block {$label} 'binding' must be an object.";
                $binding = null;
            }

            if ($type !== null && $type->requiresBinding() && ! $this->hasMetricBinding($binding)) {
                $errors[] = "Block {$label} ({$type->value}) requires a binding with 'source' and 'metric'.";
            }

            $props = $this->objectOrError($raw['props'] ?? [], $label, 'props', $errors);
            $style = $this->objectOrError($raw['style'] ?? [], $label, 'style', $errors);

            if (is_string($id) && $id !== '' && $type !== null) {
                $result[] = new Block($id, $type, is_array($binding) ? $binding : null, $props, $style);
            }
        }

        if ($errors !== []) {
            throw new BlockValidationException($errors);
        }

        return $result;
    }

    private function hasMetricBinding(mixed $binding): bool
    {
        if (! is_array($binding)) {
            return false;
        }

        $source = $binding['source'] ?? null;
        $metric = $binding['metric'] ?? null;

        return is_string($source) && $source !== '' && is_string($metric) && $metric !== '';
    }

    /**
     * @param  list<string>  $errors
     * @return array<array-key, mixed>
     */
    private function objectOrError(mixed $value, string $label, string $field, array &$errors): array
    {
        if ($value === []) {
            return [];
        }

        if (is_array($value) && ! array_is_list($value)) {
            return $value;
        }

        $errors[] = "Block {$label} '{$field}' must be an object.";

        return [];
    }
}
