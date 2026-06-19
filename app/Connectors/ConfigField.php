<?php

declare(strict_types=1);

namespace App\Connectors;

/**
 * One field in a connector's config schema (CLAUDE.md §7). The collection returned
 * by configSchema() drives the admin "configure data source" form. `secret: true`
 * marks credential fields that must be stored encrypted and never echoed back.
 */
final readonly class ConfigField
{
    public function __construct(
        public string $key,
        public string $label,
        public ConfigFieldType $type = ConfigFieldType::Text,
        public bool $required = true,
        public bool $secret = false,
        public ?string $help = null,
    ) {}

    /**
     * @return array{key: string, label: string, type: string, required: bool, secret: bool, help: string|null}
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'label' => $this->label,
            'type' => $this->type->value,
            'required' => $this->required,
            'secret' => $this->secret,
            'help' => $this->help,
        ];
    }
}
