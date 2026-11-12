<?php

declare(strict_types=1);

namespace App\Reports;

use App\Connectors\ConnectorRegistry;
use App\Enums\DataSourceType;
use App\Models\DataSource;
use Throwable;

/**
 * The unit every connector metric is declared with (its catalog), and the number format that
 * unit implies — so a block nobody formatted by hand still reads right: a rate as «5,4 %»,
 * money with its currency, a load time as «310 ms», a backup as «3 días», a size as «1,2 GB».
 * Catalog units don't depend on how a source is configured, so they're read once per process.
 */
final class MetricUnits
{
    /** Catalog unit → renderer format (resources/js/shared/blocks/units.ts mirrors this). */
    public const FORMATS = [
        'percent' => 'percent',
        'currency' => 'currency',
        'duration' => 'duration',
        'seconds' => 'seconds',
        'ms' => 'ms',
        'days' => 'days',
        'bytes' => 'bytes',
        'MB' => 'mb',
        'position' => 'position',
        'ratio' => 'ratio',
        'bool' => 'bool',
    ];

    /** @var array<string, string>|null metric key → unit */
    private static ?array $units = null;

    public function __construct(private readonly ?ConnectorRegistry $registry = null) {}

    /** The format a metric's unit implies, or null when it reads fine as a plain number. */
    public function formatFor(string $key): ?string
    {
        $unit = $this->units()[$key] ?? null;

        return $unit !== null ? (self::FORMATS[$unit] ?? null) : null;
    }

    /**
     * @return array<string, string>
     */
    public function units(): array
    {
        if (self::$units !== null) {
            return self::$units;
        }

        $registry = $this->registry ?? app(ConnectorRegistry::class);
        $units = [];
        foreach (DataSourceType::cases() as $type) {
            if (! $registry->has($type->value)) {
                continue;
            }
            $source = new DataSource(['type' => $type, 'config' => [], 'credentials' => []]);
            try {
                foreach ($registry->for($source)->metricCatalog($source)->all() as $definition) {
                    if ($definition->unit !== null) {
                        $units[$definition->key] = $definition->unit;
                    }
                }
            } catch (Throwable) {
                // A catalog that needs a configured source (custom queries) simply adds nothing.
            }
        }

        return self::$units = $units;
    }

    /** For tests that register fake connectors. */
    public static function flush(): void
    {
        self::$units = null;
    }
}
