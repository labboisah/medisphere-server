<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\OperationalSyncEnrollment;
use App\Services\OperationalSyncLicenseProof;
use App\Services\OperationalSyncEndpoint;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

final class OperationalSyncEnrollmentTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        (require database_path('migrations/2026_11_11_000010_create_operational_sync_outbox.php'))->up();
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->boolean('is_installation_admin');
            $table->softDeletes();
        });
        (require database_path('migrations/2026_02_10_000010_create_audit_logs_table.php'))->up();
        config(['operational_sync.platform_api_url' => 'https://platform.example.test/api/v1']);
    }

    public function test_lost_response_reuses_durable_proof_and_saves_separate_credential_encrypted(): void
    {
        $id = (string) Str::uuid();
        $code = str_repeat('c', 43);
        $credential = str_repeat('s', 43);
        $proof = ['payload_json' => '{"synthetic":true}', 'signature' => 'synthetic', 'algorithm' => 'ed25519', 'key_id' => 'test'];
        $this->mock(OperationalSyncLicenseProof::class)->shouldReceive('create')->once()
            ->with(hash('sha256', $code))->andReturn(['installation_uuid' => $id, 'proof' => $proof]);
        $calls = 0;
        Http::fake(function ($request) use (&$calls, $code, $proof, $credential, $id) {
            $this->assertSame($code, $request['code']);
            $this->assertSame($proof, $request['proof']);
            $calls++;
            if ($calls === 1) {
                throw new ConnectionException('response lost');
            }

            return Http::response(['data' => ['credential' => $credential, 'installation_uuid' => $id]]);
        });
        $adminId = DB::table('users')->insertGetId(['is_installation_admin' => true]);
        $admin = User::query()->findOrFail($adminId);
        $service = app(OperationalSyncEnrollment::class);
        try {
            $service->enroll($admin, $code);
            $this->fail('A lost response must be reported.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('Retry with the same code', $exception->getMessage());
        }
        $row = DB::table('operational_sync_connection')->first();
        $this->assertStringNotContainsString($code, json_encode($row, JSON_THROW_ON_ERROR));
        $this->assertNotNull($row->encrypted_pending_proof);
        $service->enroll($admin, $code);
        $row = DB::table('operational_sync_connection')->first();
        $this->assertSame($id, $row->installation_uuid);
        $this->assertSame($credential, \Illuminate\Support\Facades\Crypt::decryptString($row->encrypted_credential));
        $this->assertStringNotContainsString($credential, json_encode($row, JSON_THROW_ON_ERROR));
        $this->assertNull($row->encrypted_pending_code);
        $this->assertDatabaseHas('audit_logs', ['actor_id' => $adminId, 'action' => 'operational_sync.enrolled']);
    }

    public function test_non_admin_and_insecure_endpoint_cannot_enroll(): void
    {
        $id = DB::table('users')->insertGetId(['is_installation_admin' => false]);
        try {
            app(OperationalSyncEnrollment::class)->enroll(User::query()->findOrFail($id), str_repeat('c', 43));
            $this->fail('Only an installation administrator may enroll.');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
        $id = DB::table('users')->insertGetId(['is_installation_admin' => true]);
        config(['operational_sync.platform_api_url' => 'http://platform.example.test/api/v1']);
        try {
            app(OperationalSyncEnrollment::class)->enroll(User::query()->findOrFail($id), str_repeat('c', 43));
            $this->fail('The Platform must use HTTPS.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('HTTPS', $exception->getMessage());
        }
        $this->assertDatabaseCount('operational_sync_connection', 0);
    }

    public function test_http_is_permitted_only_for_opted_in_local_numeric_ip_and_port(): void
    {
        $endpoint = app(OperationalSyncEndpoint::class);
        $this->assertFalse($endpoint->isAllowed('http://127.0.0.1:8000/api/v1'));
        config(['operational_sync.allow_insecure_ip_http' => true]);
        $this->assertTrue($endpoint->isAllowed('http://127.0.0.1:8000/api/v1'));
        $this->assertTrue($endpoint->isAllowed('http://192.168.1.20:8000/api/v1'));
        $this->assertTrue($endpoint->isAllowed('http://[::1]:8000/api/v1'));
        foreach (['http://localhost:8000/api/v1', 'http://example.test:8000/api/v1', 'http://127.0.0.1/api/v1',
            'http://user@127.0.0.1:8000/api/v1', 'http://127.0.0.1:8000/api/v1?token=x',
            'http://127.0.0.1:8000/api/v1#fragment'] as $url) {
            $this->assertFalse($endpoint->isAllowed($url), $url);
        }
        $this->assertTrue($endpoint->isAllowed('https://example.test/api/v1'));
        config(['operational_sync.platform_api_url' => 'http://127.0.0.1:8000/api/v1']);
        $this->assertSame('http://127.0.0.1:8000/api/v1', app(OperationalSyncEnrollment::class)->platformUrl());
        app(\App\Services\OperationalHealthTransport::class)->validateConfiguration('http://127.0.0.1:8000/api/v1/events', 'test-token');
        $environment = $this->app['env'];
        try {
            $this->app['env'] = 'production';
            $this->assertFalse($endpoint->isAllowed('http://127.0.0.1:8000/api/v1'));
            $this->assertTrue($endpoint->isAllowed('https://example.test/api/v1'));
        } finally {
            $this->app['env'] = $environment;
        }
    }
}
