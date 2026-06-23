<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToAgency;
use Database\Factories\ClientFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * An agency's client (CLAUDE.md §5). First agency-scoped domain entity, used to
 * validate tenant isolation. Sites/data sources/reports hang off clients later.
 *
 * @property int $id
 * @property int $agency_id
 * @property string $name
 * @property string|null $contact_email
 * @property string|null $locale
 * @property string|null $timezone
 * @property string|null $notes
 */
class Client extends Model
{
    /** @use HasFactory<ClientFactory> */
    use BelongsToAgency, HasFactory;

    protected $table = 'ir_clients';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'agency_id',
        'name',
        'contact_email',
        'locale',
        'timezone',
        'notes',
    ];
}
