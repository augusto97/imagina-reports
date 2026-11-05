<?php

declare(strict_types=1);

namespace App\Ai;

/**
 * The vocabulary of the AI settings — not a list of models. The models themselves come live
 * from Anthropic's Models API (AiModelCatalog), so a new one shows up on its own and a retired
 * one disappears.
 *
 * A choice is either:
 * - `auto:{family}` — «always the newest of that family» (e.g. `auto:opus`): when Anthropic
 *   releases a new Opus, the next call already uses it. The default is `auto:opus`.
 * - a model id from the list (e.g. `claude-opus-5-5`) — pinned; if Anthropic retires it, the
 *   newest model of the same family takes over.
 */
final class AiModels
{
    public const AUTO_PREFIX = 'auto:';

    public const DEFAULT_CHOICE = 'auto:opus';

    /** Only when Anthropic's list has never been readable (no key yet, no network). */
    public const LAST_RESORT = 'claude-opus-5-5';

    public const DEFAULT_EFFORT = 'medium';

    /** Header to send with `fallbacks: "default"` (server-side retry on a safety decline). */
    public const FALLBACK_BETA = 'server-side-fallback-2026-07-01';

    /** Header that makes /v1/models publish `allowed_fallback_models` (who supports the above). */
    public const LIST_BETA = 'server-side-fallback-2026-06-01';

    /** Display order and plain-language hints for the families we know; others go last, unexplained. */
    private const FAMILIES = [
        'fable' => 'El más capaz de Anthropic y también el más caro.',
        'opus' => 'Muy capaz: los mejores resúmenes y plantillas por su precio. Recomendado.',
        'sonnet' => 'Muy bueno y más económico que Opus.',
        'haiku' => 'El más rápido y barato; textos más sencillos.',
    ];

    /** Spanish names for the effort levels Anthropic reports; an unknown level shows its own name. */
    private const EFFORTS = [
        'low' => ['Bajo', 'Responde más rápido y gasta menos; textos más directos.'],
        'medium' => ['Medio', 'El equilibrio entre calidad, tiempo y costo. Recomendado.'],
        'high' => ['Alto', 'Piensa más cada texto: algo mejor en informes complejos, pero más lento y más caro.'],
        'xhigh' => ['Muy alto', 'Todavía más razonamiento; rara vez hace falta para un informe.'],
        'max' => ['Máximo', 'Todo el razonamiento posible: el más lento y caro.'],
    ];

    /** A choice the panel can store: `auto:{family}` or something shaped like a model id. */
    public static function isValidChoice(string $choice): bool
    {
        return preg_match('/^(auto:[a-z0-9]{2,30}|[a-z0-9][a-z0-9._-]{2,99})$/', $choice) === 1;
    }

    public static function isValidEffort(string $effort): bool
    {
        return preg_match('/^[a-z_]{2,20}$/', $effort) === 1;
    }

    /** `auto:opus` → `opus`; a model id → null. */
    public static function autoFamily(string $choice): ?string
    {
        return str_starts_with($choice, self::AUTO_PREFIX) ? substr($choice, strlen(self::AUTO_PREFIX)) : null;
    }

    /** The family a model belongs to, from its display name («Claude Opus 5.5») or its id. */
    public static function familyOf(string $id, ?string $displayName = null): string
    {
        if (is_string($displayName) && preg_match('/^Claude\s+([A-Za-z]+)/', $displayName, $match) === 1) {
            return strtolower($match[1]);
        }
        foreach (explode('-', strtolower($id)) as $part) {
            if ($part !== 'claude' && preg_match('/^[a-z]+$/', $part) === 1) {
                return $part;
            }
        }

        return 'claude';
    }

    public static function familyName(string $family): string
    {
        return ucfirst($family);
    }

    public static function familyHint(string $family): string
    {
        return self::FAMILIES[$family] ?? '';
    }

    public static function familyRank(string $family): int
    {
        $rank = array_search($family, array_keys(self::FAMILIES), true);

        return is_int($rank) ? $rank : count(self::FAMILIES);
    }

    /** «claude-opus-5-5» → «Claude Opus 5.5» (and drops a date suffix) when Anthropic's list isn't at hand. */
    public static function prettyId(string $id): string
    {
        $words = [];
        $numbers = [];
        foreach (explode('-', strtolower($id)) as $part) {
            if (preg_match('/^\d{8}$/', $part) === 1) {
                continue; // a dated snapshot suffix
            }
            if (ctype_digit($part)) {
                $numbers[] = $part;

                continue;
            }
            if ($numbers !== []) {
                $words[] = implode('.', $numbers);
                $numbers = [];
            }
            $words[] = ucfirst($part);
        }
        if ($numbers !== []) {
            $words[] = implode('.', $numbers);
        }

        return implode(' ', $words);
    }

    public static function effortLabel(string $effort): string
    {
        return self::EFFORTS[$effort][0] ?? ucfirst($effort);
    }

    /**
     * @return array{id: string, label: string, hint: string}
     */
    public static function effortOption(string $effort): array
    {
        return ['id' => $effort, 'label' => self::effortLabel($effort), 'hint' => self::EFFORTS[$effort][1] ?? ''];
    }

    /**
     * The level to send: the chosen one if the model supports it, else «medium», else none.
     *
     * @param  list<string>  $supported
     */
    public static function effortFor(string $wanted, array $supported): ?string
    {
        if (in_array($wanted, $supported, true)) {
            return $wanted;
        }

        return in_array(self::DEFAULT_EFFORT, $supported, true) ? self::DEFAULT_EFFORT : null;
    }
}
