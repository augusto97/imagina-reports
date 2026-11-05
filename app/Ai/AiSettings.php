<?php

declare(strict_types=1);

namespace App\Ai;

use App\Models\Agency;
use App\Models\PlatformSetting;

/**
 * Which Claude key, model choice and effort a call uses (§10.6). Everything is set from the
 * panels — nobody has to touch the server:
 *
 * - An agency with its OWN key uses its own choices (or the platform's when it hasn't chosen).
 * - Otherwise the platform's key and choices, set in Superadmin → Integraciones.
 *
 * The model is a choice (`auto:opus` by default — «always the newest Opus»), resolved against
 * Anthropic's live list by AiModelCatalog. An agency without its own key can't choose: the
 * platform pays for those calls. A key left in the server config at install time still works
 * until one is saved in the panel.
 */
final class AiSettings
{
    /** PlatformSetting keys. */
    public const PLATFORM_KEY = 'anthropic_key';

    public const PLATFORM_MODEL = 'ai_model';

    public const PLATFORM_EFFORT = 'ai_effort';

    /** Agency settings keys for its own choices (used only with its own key). */
    public const AGENCY_MODEL = 'ai_model';

    public const AGENCY_EFFORT = 'ai_effort';

    /**
     * @return array{key: string|null, model: string, effort: string, own_key: bool}
     */
    public static function for(?Agency $agency): array
    {
        $own = $agency?->anthropicKey();
        if (is_string($own) && $own !== '') {
            return [
                'key' => $own,
                'model' => self::agencyModel($agency) ?? self::platformModel(),
                'effort' => self::agencyEffort($agency) ?? self::platformEffort(),
                'own_key' => true,
            ];
        }

        return ['key' => self::platformKey(), 'model' => self::platformModel(), 'effort' => self::platformEffort(), 'own_key' => false];
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

        // A key left in the server config at install time keeps working until one is saved here.
        $installed = config('services.anthropic.key');

        return is_string($installed) && $installed !== '' ? $installed : null;
    }

    /** True when the key in use was set at install time (nothing saved in the panel yet). */
    public static function platformKeyPreinstalled(): bool
    {
        return ! PlatformSetting::current()->hasSecret(self::PLATFORM_KEY) && self::platformKey() !== null;
    }

    public static function platformModel(): string
    {
        $panel = PlatformSetting::current()->get(self::PLATFORM_MODEL);

        return is_string($panel) && AiModels::isValidChoice($panel) ? $panel : AiModels::DEFAULT_CHOICE;
    }

    public static function platformEffort(): string
    {
        $panel = PlatformSetting::current()->get(self::PLATFORM_EFFORT);

        return is_string($panel) && AiModels::isValidEffort($panel) ? $panel : AiModels::DEFAULT_EFFORT;
    }

    /** The agency's own model choice (only meaningful with its own key), or null for "the platform's". */
    public static function agencyModel(?Agency $agency): ?string
    {
        $model = $agency?->settings[self::AGENCY_MODEL] ?? null;

        return is_string($model) && AiModels::isValidChoice($model) ? $model : null;
    }

    public static function agencyEffort(?Agency $agency): ?string
    {
        $effort = $agency?->settings[self::AGENCY_EFFORT] ?? null;

        return is_string($effort) && AiModels::isValidEffort($effort) ? $effort : null;
    }
}
