<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\ScheduleCadence;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreScheduleRequest extends FormRequest
{
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
            'report_definition_id' => ['required', 'integer'],
            'cadence' => ['required', Rule::enum(ScheduleCadence::class)],
            // Day of month a monthly report goes out (1–28). Ignored for weekly.
            'send_day' => ['nullable', 'integer', 'min:1', 'max:28'],
        ];
    }
}
