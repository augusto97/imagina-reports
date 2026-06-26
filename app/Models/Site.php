<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToAgency;
use Database\Factories\SiteFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A client's website (CLAUDE.md §5). Owns the data sources and report definitions.
 *
 * @property int $id
 * @property int $agency_id
 * @property int $client_id
 * @property string $name
 * @property string $url
 * @property string|null $hosting
 * @property string|null $support_plan
 * @property string $status
 * @property string $currency
 * @property numeric-string|null $plan_hours
 * @property array<int, mixed>|null $calculated_metrics
 */
class Site extends Model
{
    /** @use HasFactory<SiteFactory> */
    use BelongsToAgency, HasFactory;

    protected $table = 'ir_sites';

    /**
     * Supported reporting currencies (ISO 4217 → label). No FX conversion — each
     * site's amounts render in its own currency (CLAUDE.md §5). LATAM-first.
     *
     * @var array<string, string>
     */
    public const CURRENCIES = [
        'USD' => 'Dólar estadounidense (USD)',
        'COP' => 'Peso colombiano (COP)',
        'CLP' => 'Peso chileno (CLP)',
        'PEN' => 'Sol peruano (PEN)',
        'VES' => 'Bolívar venezolano (VES)',
        'ARS' => 'Peso argentino (ARS)',
        'MXN' => 'Peso mexicano (MXN)',
        'BRL' => 'Real brasileño (BRL)',
        'BOB' => 'Boliviano (BOB)',
        'UYU' => 'Peso uruguayo (UYU)',
        'PYG' => 'Guaraní paraguayo (PYG)',
        'GTQ' => 'Quetzal guatemalteco (GTQ)',
        'CRC' => 'Colón costarricense (CRC)',
        'DOP' => 'Peso dominicano (DOP)',
        'EUR' => 'Euro (EUR)',
    ];

    /**
     * @var list<string>
     */
    protected $fillable = [
        'agency_id',
        'client_id',
        'name',
        'url',
        'hosting',
        'support_plan',
        'status',
        'currency',
        'plan_hours',
        'calculated_metrics',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'calculated_metrics' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Client, $this>
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /**
     * @return HasMany<DataSource, $this>
     */
    public function dataSources(): HasMany
    {
        return $this->hasMany(DataSource::class);
    }

    /**
     * @return HasMany<WorkLog, $this>
     */
    public function workLogs(): HasMany
    {
        return $this->hasMany(WorkLog::class);
    }
}
