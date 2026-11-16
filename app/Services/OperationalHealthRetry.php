<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class OperationalHealthRetry
{
    public function retry(User $actor, string $eventId): void
    {
        abort_unless($actor->exists && $actor->is_installation_admin, 403);

        DB::transaction(function () use ($actor, $eventId): void {
            $event = DB::table('operational_sync_outbox')->where('id', $eventId)->lockForUpdate()->first();
            if (! $event || $event->status !== 'failed' || $event->acknowledged_at !== null) {
                throw ValidationException::withMessages(['event' => 'Only a failed, unacknowledged operational event can be retried.']);
            }
            DB::table('operational_sync_outbox')->where('id', $eventId)->update([
                'status' => 'pending',
                'attempts' => 0,
                'next_attempt_at' => null,
                'lease_token' => null,
                'lease_expires_at' => null,
                'last_error_code' => null,
                'updated_at' => now(),
            ]);
            AuditLog::create([
                'actor_id' => $actor->id,
                'action' => 'operational_sync.retry_requested',
                'model_type' => 'operational_sync_outbox',
                'meta' => ['event_id' => $eventId, 'previous_error_code' => $event->last_error_code],
            ]);
        });
    }
}
