<?php

namespace Tests\Feature;

use App\Services\OperationalHealthSource;
use App\Services\OperationalSyncLicenseProof;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

final class OperationalSyncConnectorTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        (require database_path('migrations/2026_11_11_000010_create_operational_sync_outbox.php'))->up();
        config([
            'operational_sync.platform_api_url' => 'https://platform.example.test/api/v1',
            'operational_sync.version' => '1.2.3',
        ]);
    }

    public function test_commands_are_disabled_by_default_and_deliver_only_after_enrollment(): void
    {
        $this->artisan('operational-sync:heartbeat')->assertFailed();
        $this->artisan('operational-sync:deliver')->assertFailed();
        config(['operational_sync.connector_enabled' => true]);
        $installation = (string) Str::uuid();
        DB::table('operational_sync_connection')->insert([
            'id' => 1, 'installation_uuid' => $installation, 'platform_api_url' => 'https://platform.example.test/api/v1',
            'encrypted_credential' => Crypt::encryptString(str_repeat('s', 43)), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->mock(OperationalSyncLicenseProof::class)->shouldReceive('installationUuid')->twice()->andReturn($installation);
        $this->mock(OperationalHealthSource::class)->shouldReceive('enabledModules')->once()->andReturn(['core', 'pharmacy']);
        Http::fake(function ($request) {
            $this->assertSame('https://platform.example.test/api/v1/operational-sync/events', $request->url());
            $this->assertTrue($request->hasHeader('Authorization', 'Bearer '.str_repeat('s', 43)));
            $payload = json_decode($request['event']['payload_json'], true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame(['core', 'pharmacy'], $payload['enabled_modules']);

            return Http::response(['event_id' => $request['event']['event_id'], 'status' => 'accepted']);
        });
        $this->artisan('operational-sync:heartbeat')->assertSuccessful();
        $this->artisan('operational-sync:deliver')->assertSuccessful();
        $this->assertDatabaseHas('operational_sync_outbox', ['sequence' => 1, 'status' => 'acknowledged']);
    }

    public function test_connector_does_not_send_while_enrollment_response_is_pending(): void
    {
        config(['operational_sync.connector_enabled' => true]);
        DB::table('operational_sync_connection')->insert([
            'id' => 1, 'installation_uuid' => (string) Str::uuid(), 'platform_api_url' => 'https://platform.example.test/api/v1',
            'encrypted_credential' => Crypt::encryptString(str_repeat('s', 43)),
            'encrypted_pending_code' => Crypt::encryptString(str_repeat('c', 43)),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        Http::fake();
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('paused until pending enrollment');
        app(\App\Services\OperationalSyncConnector::class)->deliver();
    }
}
