<?php

declare(strict_types=1);

namespace App\Connectors\Connect;

/**
 * The pickable resources a just-connected OAuth source exposes (GA4 properties, Search
 * Console sites, Google Ads customers, Meta ad accounts). After the client authorizes,
 * we list what their account can access and let them pick — so they choose their property
 * from a dropdown instead of hunting down a numeric ID. `field` is the config key the
 * chosen value fills (e.g. 'property_id').
 */
final readonly class ConnectableResources
{
    /**
     * @param  list<array{value: string, label: string}>  $options
     */
    public function __construct(
        public string $field,
        public string $label,
        public array $options,
    ) {}

    /**
     * @return array{field: string, label: string, options: list<array{value: string, label: string}>}
     */
    public function toArray(): array
    {
        return ['field' => $this->field, 'label' => $this->label, 'options' => $this->options];
    }
}
