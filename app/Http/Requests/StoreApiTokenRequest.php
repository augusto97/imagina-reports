<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Mcp\McpAbility;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreApiTokenRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        // Owner decision (2026-09-23): only owners and admins hand out assistant access.
        return $user instanceof User && ! $user->is_platform_admin && $user->agency_id !== null && $user->role->isPrivileged();
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:80'],
            'abilities' => ['required', 'array', 'min:1'],
            'abilities.*' => ['string', Rule::in(McpAbility::values())],
        ];
    }

    /**
     * The requested permissions, always including read: a connector that can't look anything
     * up can't describe a change either.
     *
     * @return list<string>
     */
    public function abilities(): array
    {
        $abilities = [McpAbility::Read->value];

        foreach ((array) $this->input('abilities', []) as $ability) {
            if (is_string($ability) && McpAbility::tryFrom($ability) !== null) {
                $abilities[] = $ability;
            }
        }

        return array_values(array_unique($abilities));
    }
}
