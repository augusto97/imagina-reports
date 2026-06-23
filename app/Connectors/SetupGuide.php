<?php

declare(strict_types=1);

namespace App\Connectors;

/**
 * A short "how to connect" guide for a connector, shown in the admin data-source
 * form so the operator knows exactly what to create/paste (CLAUDE.md §7/§11.1).
 */
final readonly class SetupGuide
{
    /**
     * @param  list<string>  $steps  Ordered, plain-language steps.
     */
    public function __construct(
        public string $intro,
        public array $steps,
        public ?string $docsUrl = null,
    ) {}

    /**
     * @return array{intro: string, steps: list<string>, docs_url: string|null}
     */
    public function toArray(): array
    {
        return [
            'intro' => $this->intro,
            'steps' => $this->steps,
            'docs_url' => $this->docsUrl,
        ];
    }
}
