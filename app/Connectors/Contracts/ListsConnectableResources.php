<?php

declare(strict_types=1);

namespace App\Connectors\Contracts;

use App\Connectors\Connect\ConnectableResources;
use App\Models\DataSource;

/**
 * A connector that, given a source whose OAuth credentials are already stored, can list
 * the resources the client's account can access (GA4 properties, GSC sites, ad accounts)
 * so the UI offers a picker instead of a manual ID field. Best-effort: returns null when
 * it can't list (missing token, API error) and the client falls back to typing the ID.
 */
interface ListsConnectableResources
{
    public function connectableResources(DataSource $source): ?ConnectableResources;
}
