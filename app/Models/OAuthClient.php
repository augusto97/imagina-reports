<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * An assistant app registered to connect to the MCP endpoint (see the migration).
 *
 * @property int $id
 * @property string $client_id
 * @property string $name
 * @property list<string> $redirect_uris
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class OAuthClient extends Model
{
    protected $table = 'ir_oauth_clients';

    /**
     * @var list<string>
     */
    protected $fillable = ['client_id', 'name', 'redirect_uris'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['redirect_uris' => 'array'];
    }

    public function allowsRedirect(string $uri): bool
    {
        // Exact match only (OAuth 2.1): no prefix or pattern matching of redirect targets.
        return in_array($uri, $this->redirect_uris, true);
    }
}
