<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ValidatesBlocks;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates a live editor preview request (CLAUDE.md §11.3): a draft block layout
 * resolved against a site + period into REAL metric data. The blocks are validated
 * through the same schema as saved templates so the preview can never choke on a
 * malformed layout.
 */
final class PreviewReportRequest extends FormRequest
{
    use ValidatesBlocks;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'blocks' => ['required', 'array'],
            'period_start' => ['nullable', 'date'],
            'period_end' => ['nullable', 'date', 'after_or_equal:period_start'],
        ];
    }
}
