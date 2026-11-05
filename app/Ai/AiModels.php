<?php

declare(strict_types=1);

namespace App\Ai;

/**
 * The Claude models the panel offers (super-admin default + each agency with its own key).
 * One place for the ids, the plain-language hints and what each request needs:
 *
 * - `effort`: sent explicitly (Sonnet 5.5 would default to `high`; a report summary doesn't need it).
 * - `fallbacks`: server-side «default» fallback on a safety decline (Opus 5.5 / Sonnet 5.5 only;
 *   Haiku 5.5 has none). A decline still never breaks a report: the summary falls back to the
 *   figures-only text.
 *
 * A model id that isn't listed here (e.g. set in .env) is still used as-is, just without extras.
 */
final class AiModels
{
    public const DEFAULT = 'claude-opus-5-5';

    public const FALLBACK_BETA = 'server-side-fallback-2026-07-01';

    /**
     * @var array<string, array{label: string, hint: string, effort: string, fallbacks: bool}>
     */
    private const MODELS = [
        'claude-opus-5-5' => [
            'label' => 'Claude Opus 5.5',
            'hint' => 'El más capaz: los mejores resúmenes y plantillas. Recomendado.',
            'effort' => 'medium',
            'fallbacks' => true,
        ],
        'claude-sonnet-5-5' => [
            'label' => 'Claude Sonnet 5.5',
            'hint' => 'Muy bueno y cuesta la mitad que Opus.',
            'effort' => 'medium',
            'fallbacks' => true,
        ],
        'claude-haiku-5-5' => [
            'label' => 'Claude Haiku 5.5',
            'hint' => 'El más rápido y económico (unas 40 veces más barato que Opus); textos más sencillos.',
            'effort' => 'medium',
            'fallbacks' => false,
        ],
    ];

    /**
     * @return list<array{id: string, label: string, hint: string}>
     */
    public static function options(): array
    {
        $out = [];
        foreach (self::MODELS as $id => $model) {
            $out[] = ['id' => $id, 'label' => $model['label'], 'hint' => $model['hint']];
        }

        return $out;
    }

    public static function known(string $id): bool
    {
        return isset(self::MODELS[$id]);
    }

    /** @return list<string> */
    public static function ids(): array
    {
        return array_keys(self::MODELS);
    }

    public static function label(string $id): string
    {
        return self::MODELS[$id]['label'] ?? $id;
    }

    /**
     * Extra body fields and beta header for a model we know (nothing for any other id).
     *
     * @return array{body: array<string, mixed>, beta: string|null}
     */
    public static function extras(string $id): array
    {
        $model = self::MODELS[$id] ?? null;
        if ($model === null) {
            return ['body' => [], 'beta' => null];
        }

        $body = ['output_config' => ['effort' => $model['effort']]];
        if ($model['fallbacks']) {
            $body['fallbacks'] = 'default';
        }

        return ['body' => $body, 'beta' => $model['fallbacks'] ? self::FALLBACK_BETA : null];
    }
}
