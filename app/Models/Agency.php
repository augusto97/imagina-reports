<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\AgencyFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
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
 * @property string|null $domain_status
 * @property Carbon|null $domain_checked_at
 * @property string|null $domain_error
 * @property array<string, mixed>|null $settings
 * @property int|null $snapshot_retention_months
 * @property array<int, mixed>|null $calculated_metrics
 * @property int|null $plan_id
 * @property string $status
 * @property array<string, mixed>|null $plan_overrides
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
        'domain_status',
        'domain_checked_at',
        'domain_error',
        'settings',
        'snapshot_retention_months',
        'calculated_metrics',
        'plan_id',
        'status',
        'plan_overrides',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'settings' => 'array',
            'domain_checked_at' => 'datetime',
            'snapshot_retention_months' => 'integer',
            'calculated_metrics' => 'array',
            'plan_overrides' => 'array',
        ];
    }

    public function isSuspended(): bool
    {
        return $this->status === 'suspended';
    }

    /**
     * The agency's own mail server, ready for Mail::build(), or null when not configured.
     *
     * @return array{mailer: array<string, mixed>, from_address: string, from_name: string|null}|null
     */
    public function senderMailer(): ?array
    {
        $sender = $this->settings['sender'] ?? null;
        if (! is_array($sender) || ! is_string($sender['host'] ?? null) || ! is_string($sender['from_address'] ?? null)) {
            return null;
        }

        $password = null;
        if (is_string($sender['password'] ?? null) && $sender['password'] !== '') {
            try {
                $password = Crypt::decryptString($sender['password']);
            } catch (Throwable) {
                $password = null;
            }
        }
        $port = is_numeric($sender['port'] ?? null) ? (int) $sender['port'] : 587;
        $scheme = is_string($sender['scheme'] ?? null) && $sender['scheme'] !== '' ? $sender['scheme'] : ($port === 465 ? 'smtps' : 'smtp');

        return [
            'mailer' => [
                'transport' => 'smtp',
                'scheme' => $scheme,
                'host' => $sender['host'],
                'port' => $port,
                'username' => is_string($sender['username'] ?? null) ? $sender['username'] : null,
                'password' => $password,
                'timeout' => 20,
            ],
            'from_address' => $sender['from_address'],
            'from_name' => is_string($sender['from_name'] ?? null) && $sender['from_name'] !== '' ? $sender['from_name'] : null,
        ];
    }

    /** The agency's own domain, only once it is live (DNS + HTTPS verified). */
    public function activeDomain(): ?string
    {
        return $this->domain !== null && $this->domain !== '' && $this->domain_status === 'active' ? $this->domain : null;
    }

    /** Where the agency's clients open their reports: its own domain when live, else the platform's. */
    public function publicBaseUrl(): string
    {
        $domain = $this->activeDomain();
        if ($domain !== null) {
            return 'https://'.$domain;
        }
        $appUrl = config('app.url');

        return rtrim(is_string($appUrl) ? $appUrl : '', '/');
    }

    public function publicUrl(string $path): string
    {
        return $this->publicBaseUrl().'/'.ltrim($path, '/');
    }

    /**
     * @return BelongsTo<Plan, $this>
     */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
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
        } catch (Throwable $e) {
            // Usually an APP_KEY change — log it (never the key itself) so the operator knows why
            // the AI builder stopped working instead of it silently doing nothing.
            Log::warning('Failed to decrypt the agency Anthropic key; APP_KEY may have changed.', ['agency_id' => $this->id, 'error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * Public URL of the agency logo (white-label, §11.5) for the portal/PDF, or null.
     */
    /**
     * How the agency's clients reach it (set in Ajustes → Mi agencia). Only the filled fields.
     *
     * @return array{email?: string, phone?: string, website?: string}
     */
    public function contact(): array
    {
        $out = [];
        foreach (['email' => 'contact_email', 'phone' => 'contact_phone', 'website' => 'website'] as $key => $field) {
            $value = $this->settings[$field] ?? null;
            if (is_string($value) && $value !== '') {
                $out[$key] = $value;
            }
        }

        return $out;
    }

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
