<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class OperationalHealthDelivery
{
    private const MAX_ATTEMPTS = 10;

    public function __construct(private readonly OperationalHealthTransport $transport) {}

    /**
     * Deliver at most the oldest outstanding event. A failed or delayed event
     * blocks later sequence numbers until an operator resolves it.
     */
    public function deliverNext(string $url, string $credential): string
    {
        $this->transport->validateConfiguration($url, $credential);
        $lease = DB::transaction(function (): object|string|null {
            $row = DB::table('operational_sync_outbox')
                ->whereNull('acknowledged_at')->orderBy('sequence')->lockForUpdate()->first();
            if ($row && $row->status === 'failed') {
                return 'blocked';
            }
            if (! $row
                || ($row->next_attempt_at && Carbon::parse($row->next_attempt_at)->isFuture())
                || ($row->lease_expires_at && Carbon::parse($row->lease_expires_at)->isFuture())) {
                return null;
            }
            $row->lease_token = (string) Str::uuid();
            DB::table('operational_sync_outbox')->where('id', $row->id)->update([
                'lease_token' => $row->lease_token,
                'lease_expires_at' => now()->addSeconds(60),
                'attempts' => $row->attempts + 1,
                'updated_at' => now(),
            ]);

            return $row;
        });
        if (! $lease) {
            return 'idle';
        }
        if ($lease === 'blocked') {
            return 'blocked';
        }

        $result = $this->transport->send($url, $credential, [
            'event_id' => $lease->id,
            'sequence' => (int) $lease->sequence,
            'occurred_at' => Carbon::parse($lease->occurred_at, 'UTC')->format('Y-m-d\TH:i:s\Z'),
            'payload_json' => $lease->payload_json,
            'payload_sha256' => $lease->payload_sha256,
        ]);
        $attempt = (int) $lease->attempts + 1;
        $status = $result['result'];
        if ($status === 'retry' && $attempt >= self::MAX_ATTEMPTS) {
            $status = 'failed';
        }
        $changes = [
            'status' => $status,
            'lease_token' => null,
            'lease_expires_at' => null,
            'last_error_code' => $status === 'acknowledged' ? null : $result['code'],
            'acknowledged_at' => $status === 'acknowledged' ? now() : null,
            'next_attempt_at' => $status === 'retry'
                ? now()->addSeconds(min(3600, 30 * (2 ** min($attempt - 1, 7))) + random_int(0, 30))
                : null,
            'updated_at' => now(),
        ];
        $updated = DB::table('operational_sync_outbox')->where('id', $lease->id)
            ->where('lease_token', $lease->lease_token)->update($changes);

        return $updated === 1 ? $status : 'lease_lost';
    }
}
