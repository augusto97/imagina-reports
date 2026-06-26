<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\AgencyFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * The tenant root (CLAUDE.md §5). Not itself agency-scoped; all other domain
 * models hang off it via agency_id and the AgencyScope global scope.
 *
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property string|null $logo_path
 * @property string|null $brand_color
 * @property string $default_locale
 * @property string|null $domain
 * @property array<string, mixed>|null $settings
 * @property int|null $snapshot_retention_months
 */
class Agency extends Model
{
    /** @use HasFactory<AgencyFactory> */
    use HasFactory;

    protected $table = 'ir_agencies';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'slug',
        'logo_path',
        'brand_color',
        'default_locale',
        'domain',
        'settings',
        'snapshot_retention_months',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'settings' => 'array',
            'snapshot_retention_months' => 'integer',
        ];
    }

    /**
     * @return HasMany<User, $this>
     */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /**
     * @return HasMany<Client, $this>
     */
    public function clients(): HasMany
    {
        return $this->hasMany(Client::class);
    }

    /**
     * The agency's own Anthropic API key (CLAUDE.md §10.6), stored encrypted in
     * `settings` so it never appears in plaintext. Lets each agency configure the AI
     * builder from the UI without touching the server's .env. Returns null if unset
     * or undecryptable.
     */
    public function anthropicKey(): ?string
    {
        $stored = $this->settings['anthropic_key'] ?? null;

        if (! is_string($stored) || $stored === '') {
            return null;
        }

        try {
            return Crypt::decryptString($stored);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Public URL of the agency logo (white-label, §11.5) for the portal/PDF, or null.
     */
    public function logoUrl(): ?string
    {
        return $this->logo_path === null ? null : Storage::disk('public')->url($this->logo_path);
    }

    public function setAnthropicKey(?string $key): void
    {
        $settings = $this->settings ?? [];

        if ($key === null || $key === '') {
            unset($settings['anthropic_key']);
        } else {
            $settings['anthropic_key'] = Crypt::encryptString($key);
        }

        $this->settings = $settings;
    }
}
