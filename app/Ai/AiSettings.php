<?php

declare(strict_types=1);

namespace App\Ai;

use App\Models\Agency;
use App\Models\PlatformSetting;

/**
 * Which Claude key and model a call uses (§10.6).
 *
 * - An agency with its OWN key uses its own model choice (or the platform default).
 * - Otherwise the platform's key and model: set in super-admin → Integraciones, falling back
 *   to .env (ANTHROPIC_API_KEY / ANTHROPIC_MODEL), then to AiModels::DEFAULT.
 *
 * An agency without its own key can't pick a model: the platform pays for those calls.
 */
final class AiSettings
{
    /** PlatformSetting keys. */
    public const PLATFORM_KEY = 'anthropic_key';

    public const PLATFORM_MODEL = 'ai_model';

    /** Agency settings key for its own model choice. */
    public const AGENCY_MODEL = 'ai_model';

    /**
     * @return array{key: string|null, model: string, own_key: bool}
     */
    public static function for(?Agency $agency): array
    {
        $own = $agency?->anthropicKey();
        if (is_string($own) && $own !== '') {
            return ['key' => $own, 'model' => self::agencyModel($agency) ?? self::platformModel(), 'own_key' => true];
        }

        return ['key' => self::platformKey(), 'model' => self::platformModel(), 'own_key' => false];
    }

    public static function available(?Agency $agency): bool
    {
        return self::for($agency)['key'] !== null;
    }

    public static function platformKey(): ?string
    {
        $panel = PlatformSetting::current()->secret(self::PLATFORM_KEY);
        if (is_string($panel) && $panel !== '') {
            return $panel;
        }

        $env = config('services.anthropic.key');

        return is_string($env) && $env !== '' ? $env : null;
    }

    /** True when the platform key comes from .env (nothing saved in the panel). */
    public static function platformKeyFromEnv(): bool
    {
        return ! PlatformSetting::current()->hasSecret(self::PLATFORM_KEY) && self::platformKey() !== null;
    }

    public static function platformModel(): string
    {
        $panel = PlatformSetting::current()->get(self::PLATFORM_MODEL);
        if (is_string($panel) && $panel !== '') {
            return $panel;
        }

        $env = config('services.anthropic.model');

        return is_string($env) && $env !== '' ? $env : AiModels::DEFAULT;
    }

    /** The agency's own choice (only meaningful with its own key), or null for "the platform's". */
    public static function agencyModel(?Agency $agency): ?string
    {
        $model = $agency?->settings[self::AGENCY_MODEL] ?? null;

        return is_string($model) && AiModels::known($model) ? $model : null;
    }
}
