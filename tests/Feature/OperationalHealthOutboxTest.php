<?php

namespace Tests\Feature;

use App\Services\OperationalHealthOutbox;
use App\Services\OperationalHealthSource;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class OperationalHealthOutboxTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        (require database_path('migrations/2026_11_11_000010_create_operational_sync_outbox.php'))->up();
        config(['central_licensing.enabled' => true]);
        $this->mock(OperationalHealthSource::class)->shouldReceive('enabledModules')->andReturn(['core', 'pharmacy']);
    }

    public function test_records_durable_ordered_allowlisted_operational_health(): void
    {
        $service = app(OperationalHealthOutbox::class);
        $first = $service->record('1.0.0');
        $second = $service->record('1.0.0');
        $this->assertSame(1, $first['sequence']);
        $this->assertSame(2, $second['sequence']);
        $row = DB::table('operational_sync_outbox')->where('id', $second['event_id'])->first();
        $this->assertSame(hash('sha256', $row->payload_json), $row->payload_sha256);
        $this->assertSame([
            'version' => '1.0.0', 'pending_count' => 1, 'failed_count' => 0,
            'enabled_modules' => ['core', 'pharmacy'],
        ], json_decode($row->payload_json, true, flags: JSON_THROW_ON_ERROR));
    }

    public function test_invalid_version_does_not_create_event(): void
    {
        try {
            app(OperationalHealthOutbox::class)->record('not a version');
            $this->fail('Invalid versions must not be queued.');
        } catch (\RuntimeException) {
            $this->assertSame(0, DB::table('operational_sync_outbox')->count());
        }
    }
}
