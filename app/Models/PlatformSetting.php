<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;
use Throwable;

/**
 * Platform-wide settings singleton (SaaS Fase 2 — billing). Holds the payment provider
 * credentials; secrets are stored encrypted inside the `settings` JSON and never returned
 * in plaintext. Access via PlatformSetting::current().
 *
 * @property array<string, mixed>|null $settings
 */
class PlatformSetting extends Model
{
    protected $table = 'ir_platform_settings';

    /**
     * @var list<string>
     */
    protected $fillable = ['settings'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['settings' => 'array'];
    }

    /** The singleton row (created on first access). */
    public static function current(): self
    {
        return static::query()->firstOrCreate([], ['settings' => []]);
    }

    public function get(string $key): mixed
    {
        return ($this->settings ?? [])[$key] ?? null;
    }

    public function put(string $key, mixed $value): void
    {
        $settings = $this->settings ?? [];
        $settings[$key] = $value;
        $this->settings = $settings;
    }

    /** Store a secret encrypted (empty clears it). */
    public function putSecret(string $key, ?string $value): void
    {
        $settings = $this->settings ?? [];
        if ($value === null || $value === '') {
            unset($settings[$key]);
        } else {
            $settings[$key] = Crypt::encryptString($value);
        }
        $this->settings = $settings;
    }

    /** Decrypt a secret, or null when absent/undecryptable. */
    public function secret(string $key): ?string
    {
        $raw = ($this->settings ?? [])[$key] ?? null;
        if (! is_string($raw) || $raw === '') {
            return null;
        }

        try {
            return Crypt::decryptString($raw);
        } catch (Throwable) {
            return null;
        }
    }

    public function hasSecret(string $key): bool
    {
        return $this->secret($key) !== null;
    }
}
