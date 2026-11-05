<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A question/comment the client sends from the portal (Etapa 4). No account: the client says
 * who they are, and leaves an email only if they want to be told when the agency answers.
 */
final class StoreClientMessageRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:80'],
            'email' => ['nullable', 'email', 'max:190'],
            'body' => ['required', 'string', 'min:2', 'max:2000'],
            // Honeypot: real people never see this field; bots fill every input they find.
            'website' => ['nullable', 'string', 'max:200'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'Dinos tu nombre para que la agencia sepa quién pregunta.',
            'email.email' => 'Ese correo no parece válido.',
            'body.required' => 'Escribe tu pregunta.',
            'body.max' => 'La pregunta es muy larga (máximo 2000 caracteres).',
        ];
    }
}
