<?php

namespace App\Services;

use App\Exceptions\OperationalSyncEnrollmentException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use KernelBridge\LicensingClient\Services\DeploymentProfile;
use KernelBridge\LicensingClient\Services\LicenseCacheService;
use KernelBridge\LicensingClient\Models\LicenseState;

class OperationalSyncLicenseProof
{
    public function __construct(private readonly LicenseCacheService $cache, private readonly DeploymentProfile $profile) {}

    /** @return array{installation_uuid: string, proof: array<string, string>} */
    public function create(string $challenge): array
    {
        $state = $this->activeState();
        $url = rtrim($this->profile->apiUrl(), '/');
        $parts = parse_url($url);
        $host = strtolower((string) ($parts['host'] ?? ''));
        if (! is_array($parts) || $host === '' || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['query']) || isset($parts['fragment'])
            || (($parts['scheme'] ?? null) !== 'https'
                && !(($parts['scheme'] ?? null) === 'http' && in_array($host, ['localhost', '127.0.0.1', '::1'], true)))) {
            throw new OperationalSyncEnrollmentException('The KernelBridge API URL must use HTTPS except on local loopback.');
        }
        try {
            $response = Http::acceptJson()->asJson()->withToken($state->encrypted_installation_credential)
                ->withoutRedirecting()->connectTimeout(5)->timeout(15)
                ->post($url.'/installations/operational-sync-proof', ['challenge' => $challenge]);
        } catch (ConnectionException) {
            throw new OperationalSyncEnrollmentException('KernelBridge is unavailable; retry sync enrollment.');
        }
        $proof = $response->json('data');
        if (! $response->successful() || ! is_array($proof)
            || ! is_string($proof['payload_json'] ?? null) || strlen($proof['payload_json']) > 4096
            || ! is_string($proof['signature'] ?? null) || ! is_string($proof['key_id'] ?? null)
            || ($proof['algorithm'] ?? null) !== 'ed25519') {
            throw new OperationalSyncEnrollmentException('KernelBridge could not provide an installation proof.');
        }
        $keys = config('kernelbridge-licensing.entitlement_public_keys', []);
        $key = is_array($keys) && is_string($keys[$proof['key_id']] ?? null) ? base64_decode($keys[$proof['key_id']], true) : false;
        $signature = base64_decode($proof['signature'], true);
        if ($key === false || strlen($key) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES
            || $signature === false || strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES
            || ! sodium_crypto_sign_verify_detached($signature, $proof['payload_json'], $key)) {
            throw new OperationalSyncEnrollmentException('KernelBridge returned an untrusted installation proof.');
        }
        try {
            $claims = json_decode($proof['payload_json'], true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new OperationalSyncEnrollmentException('KernelBridge returned an invalid installation proof.');
        }
        if (! is_array($claims) || ($claims['purpose'] ?? null) !== 'mediflow.operational-sync.enrollment'
            || ($claims['installation_uuid'] ?? null) !== $state->installation_identifier
            || ($claims['challenge'] ?? null) !== $challenge) {
            throw new OperationalSyncEnrollmentException('KernelBridge returned a mismatched installation proof.');
        }

        return ['installation_uuid' => $state->installation_identifier, 'proof' => array_intersect_key($proof, array_flip(['payload_json', 'signature', 'algorithm', 'key_id']))];
    }

    public function installationUuid(): string
    {
        return $this->activeState()->installation_identifier;
    }

    private function activeState(): LicenseState
    {
        $this->profile->assertReady();
        $this->profile->assertConfigurationComplete();
        $state = $this->cache->state();
        if (! config('central_licensing.enabled') || ! $this->cache->hasUsableLicense()
            || $state->credential_kind !== 'installation_bearer' || ! $state->encrypted_installation_credential) {
            throw new OperationalSyncEnrollmentException('An active account-linked installation license is required for sync enrollment.');
        }
        return $state;
    }
}
