<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

/**
 * Internal accounting primitive only. It does not configure a budget,
 * generate private values, or authorize a release.
 */
final class InsightPrivacyBudget
{
    private const MAX_UNITS = 1000000000;

    /**
     * Reserve against a pre-provisioned lifetime budget. Retries must use the
     * same ID, request hash and cost. Reservations are not refundable.
     *
     * @return 'reserved'|'duplicate'
     */
    public function reserve(string $id, string $requestHash, int $costUnits): string
    {
        if (! Str::isUuid($id) || ! preg_match('/\A[a-f0-9]{64}\z/', $requestHash)
            || $costUnits < 1 || $costUnits > self::MAX_UNITS) {
            throw new InvalidArgumentException('Invalid privacy budget reservation.');
        }

        return DB::transaction(function () use ($id, $requestHash, $costUnits): string {
            $budget = DB::table('insight_privacy_budget')->where('id', 1)->lockForUpdate()->first();
            if (! $budget) {
                throw new RuntimeException('A reviewed privacy budget has not been provisioned.');
            }
            if (DB::table('insight_privacy_budget')->where('id', '<>', 1)->exists()) {
                throw new RuntimeException('The privacy budget has unexpected rows; releases are blocked.');
            }
            $limit = (int) $budget->limit_units;
            $spent = (int) $budget->spent_units;
            if ($limit < 1 || $limit > self::MAX_UNITS || $spent < 0 || $spent > $limit) {
                throw new RuntimeException('The privacy budget is invalid; releases are blocked.');
            }
            if (DB::table('insight_privacy_reservations')
                ->where(function ($query): void {
                    $query->where('cost_units', '<', 1)->orWhere('cost_units', '>', self::MAX_UNITS);
                })->exists()) {
                throw new RuntimeException('The privacy reservation ledger contains invalid costs; releases are blocked.');
            }
            $reserved = (int) DB::table('insight_privacy_reservations')->sum('cost_units');
            if ($reserved !== $spent) {
                throw new RuntimeException('The privacy budget does not match the reservation ledger; releases are blocked.');
            }
            $existing = DB::table('insight_privacy_reservations')->where('id', $id)->first();
            if ($existing) {
                if (! hash_equals($existing->request_hash, $requestHash) || (int) $existing->cost_units !== $costUnits) {
                    throw new RuntimeException('The privacy reservation conflicts with an existing request.');
                }

                return 'duplicate';
            }
            if ($costUnits > $limit - $spent) {
                throw new RuntimeException('The privacy budget is exhausted.');
            }

            DB::table('insight_privacy_reservations')->insert([
                'id' => $id,
                'request_hash' => $requestHash,
                'cost_units' => $costUnits,
                'created_at' => now(),
            ]);
            $updated = DB::table('insight_privacy_budget')->where('id', 1)
                ->where('limit_units', $limit)->where('spent_units', $spent)->update([
                    'spent_units' => $spent + $costUnits,
                    'updated_at' => now(),
                ]);
            if ($updated !== 1) {
                throw new RuntimeException('The privacy budget changed concurrently; retry the reservation.');
            }

            return 'reserved';
        }, 3);
    }
}
