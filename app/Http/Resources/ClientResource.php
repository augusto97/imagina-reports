<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Client;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use LogicException;

final class ClientResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $client = $this->resource;

        if (! $client instanceof Client) {
            throw new LogicException('ClientResource expects a Client.');
        }

        return [
            'id' => $client->id,
            'name' => $client->name,
            'contact_email' => $client->contact_email,
            'locale' => $client->locale,
            'timezone' => $client->timezone,
            'notes' => $client->notes,
        ];
    }
}
