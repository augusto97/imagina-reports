<?php

declare(strict_types=1);

namespace App\Mcp;

/**
 * What a tool hands back: human-readable text for the assistant plus, when there is data, the
 * same data as `structuredContent` so clients that render it natively can.
 */
final readonly class ToolResult
{
    /**
     * @param  array<string, mixed>|null  $data
     */
    private function __construct(
        public string $text,
        public ?array $data,
        public bool $isError,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function data(array $data, ?string $summary = null): self
    {
        $json = (string) json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $text = $summary === null || $summary === '' ? $json : $summary."\n\n".$json;

        return new self($text, $data, false);
    }

    public static function error(string $message): self
    {
        return new self($message, null, true);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $result = [
            'content' => [['type' => 'text', 'text' => $this->text]],
            'isError' => $this->isError,
        ];

        if ($this->data !== null) {
            $result['structuredContent'] = $this->data;
        }

        return $result;
    }
}
