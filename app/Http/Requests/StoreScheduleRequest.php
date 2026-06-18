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
        ];
    }
}
