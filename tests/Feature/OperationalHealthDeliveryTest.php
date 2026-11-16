<?php

namespace Tests\Feature;

use App\Services\OperationalHealthDelivery;
use App\Services\OperationalHealthOutbox;
use App\Services\OperationalHealthRetry;
use App\Services\OperationalHealthSource;
use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class OperationalHealthDeliveryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        (require database_path('migrations/2026_11_11_000010_create_operational_sync_outbox.php'))->up();
        $this->mock(OperationalHealthSource::class)->shouldReceive('enabledModules')->andReturn(['core']);
    }

    public function test_acknowledgements_are_persisted_in_order_and_envelope_matches_outbox(): void
    {
        $first = app(OperationalHealthOutbox::class)->record('1.0');
        $second = app(OperationalHealthOutbox::class)->record('1.0');
        Http::fake(function ($request) {
            $event = $request['event'];
            $this->assertSame(hash('sha256', $event['payload_json']), $event['payload_sha256']);
            $this->assertTrue($request->hasHeader('Authorization', 'Bearer separate-sync-token'));

            return Http::response(['event_id' => $event['event_id'], 'status' => 'accepted']);
        });
        $delivery = app(OperationalHealthDelivery::class);
        $this->assertSame('acknowledged', $delivery->deliverNext('https://platform.example.test/sync', 'separate-sync-token'));
        $this->assertNotNull(DB::table('operational_sync_outbox')->where('id', $first['event_id'])->value('acknowledged_at'));
        $this->assertNull(DB::table('operational_sync_outbox')->where('id', $second['event_id'])->value('acknowledged_at'));
        $this->assertSame('acknowledged', $delivery->deliverNext('https://platform.example.test/sync', 'separate-sync-token'));
        $this->assertSame('idle', $delivery->deliverNext('https://platform.example.test/sync', 'separate-sync-token'));
    }

    public function test_lost_response_retries_same_event_and_accepts_duplicate(): void
    {
        $event = app(OperationalHealthOutbox::class)->record('1.0');
        Http::fakeSequence()->pushStatus(503)->push(['event_id' => $event['event_id'], 'status' => 'duplicate']);
        $delivery = app(OperationalHealthDelivery::class);
        $this->assertSame('retry', $delivery->deliverNext('https://platform.example.test/sync', 'token'));
        $row = DB::table('operational_sync_outbox')->where('id', $event['event_id'])->first();
        $this->assertSame('remote_unavailable', $row->last_error_code);
        $this->assertSame('idle', $delivery->deliverNext('https://platform.example.test/sync', 'token'));
        $this->travel(2)->minutes();
        $this->assertSame('acknowledged', $delivery->deliverNext('https://platform.example.test/sync', 'token'));
        $this->assertSame(2, (int) DB::table('operational_sync_outbox')->where('id', $event['event_id'])->value('attempts'));
        Http::assertSentCount(2);
    }

    public function test_failed_message_blocks_later_messages_and_retry_exhaustion_is_visible(): void
    {
        $first = app(OperationalHealthOutbox::class)->record('1.0');
        app(OperationalHealthOutbox::class)->record('1.0');
        DB::table('operational_sync_outbox')->where('id', $first['event_id'])->update(['attempts' => 9]);
        Http::fake(['*' => Http::response([], 503)]);
        $delivery = app(OperationalHealthDelivery::class);
        $this->assertSame('failed', $delivery->deliverNext('https://platform.example.test/sync', 'token'));
        $this->assertSame('blocked', $delivery->deliverNext('https://platform.example.test/sync', 'token'));
        $this->assertSame('remote_unavailable', DB::table('operational_sync_outbox')->where('id', $first['event_id'])->value('last_error_code'));
        Http::assertSentCount(1);
    }

    public function test_recovery_after_expired_lease_and_bad_acknowledgement(): void
    {
        $event = app(OperationalHealthOutbox::class)->record('1.0');
        DB::table('operational_sync_outbox')->where('id', $event['event_id'])->update([
            'lease_token' => '11111111-1111-4111-8111-111111111111',
            'lease_expires_at' => now()->addSeconds(30),
        ]);
        Http::fake(['*' => Http::response(['event_id' => 'wrong', 'status' => 'accepted'])]);
        $delivery = app(OperationalHealthDelivery::class);
        $this->assertSame('idle', $delivery->deliverNext('https://platform.example.test/sync', 'token'));
        $this->travel(31)->seconds();
        $this->assertSame('retry', $delivery->deliverNext('https://platform.example.test/sync', 'token'));
        $this->assertSame('invalid_acknowledgement', DB::table('operational_sync_outbox')->where('id', $event['event_id'])->value('last_error_code'));
    }

    public function test_connection_failure_is_retryable_and_http_is_rejected(): void
    {
        app(OperationalHealthOutbox::class)->record('1.0');
        Http::fake(fn () => throw new ConnectionException('unavailable'));
        $delivery = app(OperationalHealthDelivery::class);
        $this->assertSame('retry', $delivery->deliverNext('https://platform.example.test/sync', 'token'));
        $this->travel(2)->minutes();
        try {
            $delivery->deliverNext('http://platform.example.test/sync', 'token');
            $this->fail('Insecure endpoints must not be used.');
        } catch (\InvalidArgumentException) {
            $this->assertSame(1, (int) DB::table('operational_sync_outbox')->value('attempts'));
            $this->assertNull(DB::table('operational_sync_outbox')->value('lease_token'));
        }
    }

    public function test_permanent_rejection_is_not_silently_discarded(): void
    {
        app(OperationalHealthOutbox::class)->record('1.0');
        Http::fake(['*' => Http::response(['message' => 'sensitive remote text'], 403)]);
        $this->assertSame('failed', app(OperationalHealthDelivery::class)->deliverNext('https://platform.example.test/sync', 'token'));
        $row = DB::table('operational_sync_outbox')->first();
        $this->assertSame('remote_rejected', $row->last_error_code);
        $this->assertNull($row->acknowledged_at);
    }

    public function test_stale_worker_cannot_acknowledge_a_replaced_lease(): void
    {
        $event = app(OperationalHealthOutbox::class)->record('1.0');
        Http::fake(function ($request) use ($event) {
            DB::table('operational_sync_outbox')->where('id', $event['event_id'])->update([
                'lease_token' => '22222222-2222-4222-8222-222222222222',
            ]);

            return Http::response(['event_id' => $request['event']['event_id'], 'status' => 'accepted']);
        });
        $this->assertSame('lease_lost', app(OperationalHealthDelivery::class)->deliverNext('https://platform.example.test/sync', 'token'));
        $this->assertNull(DB::table('operational_sync_outbox')->value('acknowledged_at'));
    }

    public function test_only_an_installation_admin_can_retry_failed_events_and_the_action_is_audited(): void
    {
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->boolean('is_installation_admin');
            $table->softDeletes();
        });
        (require database_path('migrations/2026_02_10_000010_create_audit_logs_table.php'))->up();
        $event = app(OperationalHealthOutbox::class)->record('1.0');
        DB::table('operational_sync_outbox')->where('id', $event['event_id'])->update([
            'status' => 'failed', 'attempts' => 10, 'last_error_code' => 'remote_rejected',
        ]);
        $adminId = DB::table('users')->insertGetId(['is_installation_admin' => true]);
        $userId = DB::table('users')->insertGetId(['is_installation_admin' => false]);
        $retry = app(OperationalHealthRetry::class);
        try {
            $retry->retry(User::query()->findOrFail($userId), $event['event_id']);
            $this->fail('Non-admin retry must be forbidden.');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
        $retry->retry(User::query()->findOrFail($adminId), $event['event_id']);
        $row = DB::table('operational_sync_outbox')->first();
        $this->assertSame('pending', $row->status);
        $this->assertSame(0, (int) $row->attempts);
        $this->assertNull($row->last_error_code);
        $audit = DB::table('audit_logs')->first();
        $this->assertSame('operational_sync.retry_requested', $audit->action);
        $this->assertSame(['event_id' => $event['event_id'], 'previous_error_code' => 'remote_rejected'], json_decode($audit->meta, true));

        try {
            $retry->retry(User::query()->findOrFail($adminId), $event['event_id']);
            $this->fail('Pending events cannot be retried again.');
        } catch (ValidationException) {
            $this->assertSame(1, DB::table('audit_logs')->count());
        }
    }

    public function test_retry_rolls_back_if_the_audit_cannot_be_written(): void
    {
        $event = app(OperationalHealthOutbox::class)->record('1.0');
        DB::table('operational_sync_outbox')->where('id', $event['event_id'])->update(['status' => 'failed']);
        $admin = new User;
        $admin->forceFill(['id' => 1, 'is_installation_admin' => true]);
        $admin->exists = true;
        try {
            app(OperationalHealthRetry::class)->retry($admin, $event['event_id']);
            $this->fail('The retry must not commit without an audit record.');
        } catch (\Illuminate\Database\QueryException) {
            $this->assertSame('failed', DB::table('operational_sync_outbox')->value('status'));
        }
    }
}
