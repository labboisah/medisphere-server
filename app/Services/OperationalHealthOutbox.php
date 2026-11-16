<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

final class OperationalHealthOutbox
{
    public function __construct(private readonly OperationalHealthSource $source) {}

    /** @return array{event_id: string, sequence: int} */
    public function record(string $version): array
    {
        if (! preg_match('/\A[A-Za-z0-9][A-Za-z0-9._+-]{0,63}\z/', $version)) {
            throw new RuntimeException('Invalid operational health version.');
        }
        $enabled = $this->source->enabledModules();

        return DB::transaction(function () use ($version, $enabled): array {
            DB::table('operational_sync_sequences')->insertOrIgnore(['id' => 1, 'last_sequence' => 0]);
            $sequence = DB::table('operational_sync_sequences')->where('id', 1)->lockForUpdate()->first();
            $pending = DB::table('operational_sync_outbox')->whereIn('status', ['pending', 'retry'])->count();
            $failed = DB::table('operational_sync_outbox')->where('status', 'failed')->count();
            if ($pending + $failed >= 10000) {
                throw new RuntimeException('Operational sync outbox capacity reached; operator action is required.');
            }
            $payload = json_encode([
                'version' => $version,
                'pending_count' => $pending,
                'failed_count' => $failed,
                'enabled_modules' => $enabled,
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $id = (string) Str::uuid();
            $next = (int) $sequence->last_sequence + 1;
            $now = now();
            DB::table('operational_sync_outbox')->insert([
                'id' => $id,
                'sequence' => $next,
                'payload_json' => $payload,
                'payload_sha256' => hash('sha256', $payload),
                'occurred_at' => $now->copy()->utc()->startOfSecond(),
                'status' => 'pending',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            DB::table('operational_sync_sequences')->where('id', 1)->update(['last_sequence' => $next]);

            return ['event_id' => $id, 'sequence' => $next];
        });
    }
}
