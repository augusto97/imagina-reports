<?php

declare(strict_types=1);

namespace App\Ai;

use App\Models\Agency;
use App\Models\PlatformSetting;

/**
 * What the AI section of each panel shows: the choices built from Anthropic's live model list,
 * what the current choice means today, and the effort levels that model accepts.
 */
final class AiPanel
{
    /**
     * Superadmin → Integraciones → Inteligencia artificial.
     *
     * @return array<string, mixed>
     */
    public static function platform(AiModelCatalog $catalog, bool $refresh = false): array
    {
        $key = AiSettings::platformKey();
        $list = $key !== null ? $catalog->list($key, $refresh) : ['models' => [], 'error' => null];
        $choice = AiSettings::platformModel();
        $resolved = AiModelCatalog::resolve($choice, $list['models']);
        $effort = AiSettings::platformEffort();

        return [
            'anthropic_key_set' => PlatformSetting::current()->hasSecret(AiSettings::PLATFORM_KEY),
            'ai_ready' => $key !== null,
            'ai_key_preinstalled' => AiSettings::platformKeyPreinstalled(),
            'ai_model' => $choice,
            'ai_model_now' => $resolved['label'],
            'ai_choices' => AiModelCatalog::choices($list['models']),
            'ai_effort' => $effort,
            ...self::efforts($resolved['model'], $effort),
            'ai_models_error' => $list['error'],
        ];
    }

    /**
     * Ajustes → Integraciones → Inteligencia artificial (the agency).
     *
     * @return array<string, mixed>
     */
    public static function agency(Agency $agency, AiModelCatalog $catalog, bool $refresh = false): array
    {
        $platformKey = AiSettings::platformKey();
        // The platform's choice as it stands (only what's already known: no call to Anthropic here).
        $platform = AiModelCatalog::resolve(AiSettings::platformModel(), $platformKey !== null ? $catalog->known($platformKey) : []);

        $own = $agency->anthropicKey();
        $list = $own !== null ? $catalog->list($own, $refresh) : ['models' => [], 'error' => null];
        ['model' => $choice, 'effort' => $effort] = AiSettings::for($agency);
        $inUse = $own !== null ? AiModelCatalog::resolve($choice, $list['models']) : $platform;

        return [
            'ai_key_set' => $own !== null,
            'ai_model' => AiSettings::agencyModel($agency),
            'ai_choices' => AiModelCatalog::choices($list['models']),
            'ai_model_now' => $inUse['label'],
            'ai_platform_model' => $platform['label'],
            'ai_effort' => AiSettings::agencyEffort($agency),
            ...self::efforts($inUse['model'], $effort),
            'ai_platform_effort' => AiModels::effortLabel(AiSettings::platformEffort()),
            'ai_platform_available' => $platformKey !== null,
            'ai_models_error' => $list['error'],
        ];
    }

    /**
     * The levels this model accepts, and the one that will actually be sent.
     *
     * @param  array{efforts: list<string>}|null  $model
     * @return array{ai_efforts: list<array{id: string, label: string, hint: string}>, ai_effort_now: string|null, ai_model_known: bool}
     */
    private static function efforts(?array $model, string $wanted): array
    {
        $levels = $model['efforts'] ?? [];
        $now = AiModels::effortFor($wanted, $levels);

        return [
            'ai_efforts' => array_map(static fn (string $level): array => AiModels::effortOption($level), $levels),
            'ai_effort_now' => $now !== null ? AiModels::effortLabel($now) : null,
            // False while Anthropic's list couldn't be read: what the model accepts isn't known yet.
            'ai_model_known' => $model !== null,
        ];
    }
}
