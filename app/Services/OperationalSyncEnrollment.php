<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Exceptions\OperationalSyncEnrollmentException;
use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

final class OperationalSyncEnrollment
{
    public function __construct(
        private readonly OperationalSyncLicenseProof $proofs,
        private readonly OperationalSyncEndpoint $endpoint,
    ) {}

    public function enroll(User $actor, string $code): void
    {
        abort_unless($actor->exists && $actor->is_installation_admin, 403);
        $code = trim($code);
        if (! preg_match('/\A[A-Za-z0-9_-]{43}\z/', $code)) {
            throw new OperationalSyncEnrollmentException('Enter the operational sync enrollment code exactly as shown in the Platform.');
        }
        $base = $this->platformUrl();
        DB::table('operational_sync_connection')->insertOrIgnore(['id' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $row = DB::table('operational_sync_connection')->where('id', 1)->first();
        if (($row->platform_api_url && $row->platform_api_url !== $base)
            || ($row->pending_platform_api_url && $row->pending_platform_api_url !== $base)) {
            throw new OperationalSyncEnrollmentException('The configured Platform URL does not match the existing sync connection.');
        }
        $existingCode = $row->encrypted_pending_code ? Crypt::decryptString($row->encrypted_pending_code) : null;
        if ($existingCode !== null && hash_equals($existingCode, $code) && $row->encrypted_pending_proof) {
            $proofData = json_decode(Crypt::decryptString($row->encrypted_pending_proof), true, 16, JSON_THROW_ON_ERROR);
        } else {
            $proofData = $this->proofs->create(hash('sha256', $code));
        }

        $proofData = DB::transaction(function () use ($base, $code, $proofData): array {
            $row = DB::table('operational_sync_connection')->where('id', 1)->lockForUpdate()->first();
            if (($row->platform_api_url && $row->platform_api_url !== $base)
                || ($row->pending_platform_api_url && $row->pending_platform_api_url !== $base)
                || ($row->installation_uuid && $row->installation_uuid !== $proofData['installation_uuid'])) {
                throw new OperationalSyncEnrollmentException('The sync connection does not match this installation or Platform.');
            }
            $pending = $row->encrypted_pending_code ? Crypt::decryptString($row->encrypted_pending_code) : null;
            if ($pending !== null && hash_equals($pending, $code) && $row->encrypted_pending_proof) {
                return json_decode(Crypt::decryptString($row->encrypted_pending_proof), true, 16, JSON_THROW_ON_ERROR);
            }
            DB::table('operational_sync_connection')->where('id', 1)->update([
                'encrypted_pending_code' => Crypt::encryptString($code),
                'encrypted_pending_proof' => Crypt::encryptString(json_encode($proofData, JSON_THROW_ON_ERROR)),
                'pending_platform_api_url' => $base,
                'updated_at' => now(),
            ]);

            return $proofData;
        });
        try {
            $response = Http::acceptJson()->asJson()->withoutRedirecting()->connectTimeout(5)->timeout(15)
                ->post($base.'/operational-sync/enroll', ['code' => $code, 'proof' => $proofData['proof']]);
        } catch (ConnectionException) {
            throw new OperationalSyncEnrollmentException('The Platform is unavailable. Retry with the same code to recover the enrollment response.');
        }
        $credential = $response->json('data.credential');
        if (! $response->successful() || ! is_string($credential)
            || ! preg_match('/\A[A-Za-z0-9_-]{43}\z/', $credential)
            || $response->json('data.installation_uuid') !== $proofData['installation_uuid']) {
            throw new OperationalSyncEnrollmentException('The Platform did not accept sync enrollment. Check the code and retry, or generate a new code.');
        }
        DB::transaction(function () use ($actor, $base, $code, $proofData, $credential): void {
            $row = DB::table('operational_sync_connection')->where('id', 1)->lockForUpdate()->first();
            if (! $row->encrypted_pending_code || ! $row->encrypted_pending_proof
                || ! hash_equals(Crypt::decryptString($row->encrypted_pending_code), $code)
                || $row->pending_platform_api_url !== $base
                || Crypt::decryptString($row->encrypted_pending_proof) !== json_encode($proofData, JSON_THROW_ON_ERROR)) {
                throw new OperationalSyncEnrollmentException('A newer enrollment request replaced this request; retry using the latest code.');
            }
            DB::table('operational_sync_connection')->where('id', 1)->update([
                'installation_uuid' => $proofData['installation_uuid'],
                'platform_api_url' => $base,
                'encrypted_credential' => Crypt::encryptString($credential),
                'encrypted_pending_code' => null,
                'encrypted_pending_proof' => null,
                'pending_platform_api_url' => null,
                'updated_at' => now(),
            ]);
            AuditLog::create([
                'actor_id' => $actor->id, 'action' => 'operational_sync.enrolled',
                'model_type' => 'operational_sync_connection',
                'meta' => ['installation_uuid' => $proofData['installation_uuid']],
            ]);
        });
    }

    public function platformUrl(): string
    {
        $url = rtrim(trim((string) config('operational_sync.platform_api_url')), '/');
        if (strlen($url) > 512 || ! $this->endpoint->isAllowed($url)) {
            throw new OperationalSyncEnrollmentException('The Platform API URL must use HTTPS, or an explicitly enabled local testing IP address and port over HTTP.');
        }

        return $url;
    }
}
