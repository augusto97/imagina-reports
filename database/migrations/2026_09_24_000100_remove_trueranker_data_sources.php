<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * TrueRanker closed its API to anything below its monthly subscription, so the connector was
 * removed (2026-09-24). Its sources can no longer sync and the `trueranker` enum value no longer
 * exists — a leftover row would break every query that casts `type`. Snapshots cascade.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('ir_data_sources')->where('type', 'trueranker')->delete();

        // Plan whitelists that named it: drop the dead key.
        foreach (DB::table('ir_plans')->whereNotNull('allowed_connectors')->get(['id', 'allowed_connectors']) as $plan) {
            $raw = $plan->allowed_connectors;
            $allowed = is_string($raw) ? json_decode($raw, true) : null;
            if (! is_array($allowed) || ! in_array('trueranker', $allowed, true)) {
                continue;
            }

            $kept = array_values(array_filter($allowed, static fn (mixed $key): bool => is_string($key) && $key !== 'trueranker'));
            DB::table('ir_plans')->where('id', $plan->id)->update(['allowed_connectors' => json_encode($kept)]);
        }
    }

    public function down(): void
    {
        // Irreversible: the connector no longer exists.
    }
};
