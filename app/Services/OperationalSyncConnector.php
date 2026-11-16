<?php

namespace App\Services;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class OperationalSyncConnector
{
    public function __construct(
        private readonly OperationalSyncEnrollment $enrollment,
        private readonly OperationalSyncLicenseProof $license,
        private readonly OperationalHealthOutbox $outbox,
        private readonly OperationalHealthDelivery $delivery,
    ) {}

    public function heartbeat(): void
    {
        $this->connection();
        $this->outbox->record((string) config('operational_sync.version'));
    }

    public function deliver(): string
    {
        $connection = $this->connection();

        return $this->delivery->deliverNext(
            $connection->platform_api_url.'/operational-sync/events',
            Crypt::decryptString($connection->encrypted_credential),
        );
    }

    private function connection(): object
    {
        $row = DB::table('operational_sync_connection')->where('id', 1)->first();
        if (! $row || ! $row->encrypted_credential || ! $row->platform_api_url || ! $row->installation_uuid) {
            throw new RuntimeException('Operational sync is enabled but this installation is not enrolled.');
        }
        if ($row->encrypted_pending_code || $row->encrypted_pending_proof) {
            throw new RuntimeException('Operational sync is paused until pending enrollment is recovered.');
        }
        if ($row->platform_api_url !== $this->enrollment->platformUrl()
            || $row->installation_uuid !== $this->license->installationUuid()) {
            throw new RuntimeException('Operational sync connection does not match the active Platform or installation.');
        }

        return $row;
    }
}
