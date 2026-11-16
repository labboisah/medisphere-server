<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;

class OperationalHealthTransport
{
    public function __construct(private readonly OperationalSyncEndpoint $endpoint) {}

    public function validateConfiguration(string $url, string $credential): void
    {
        if (! $this->endpoint->isAllowed($url) || strlen($credential) > 512
            || ! preg_match('/\A[\x21-\x7e]+\z/', $credential)) {
            throw new InvalidArgumentException('A permitted operational sync endpoint and credential are required; HTTP requires an enabled local IP address and port.');
        }
    }

    /**
     * @param  array<string, mixed>  $event
     * @return array{result: string, code: string}
     */
    public function send(string $url, string $credential, array $event): array
    {
        $this->validateConfiguration($url, $credential);

        try {
            $response = Http::acceptJson()->asJson()->withToken($credential)
                ->withOptions(['allow_redirects' => false])
                ->connectTimeout(5)->timeout(15)
                ->post($url, ['event' => $event]);
        } catch (ConnectionException) {
            return ['result' => 'retry', 'code' => 'connection_failed'];
        }

        if ($response->successful()) {
            $data = $response->json();
            if (is_array($data)
                && ($data['event_id'] ?? null) === $event['event_id']
                && in_array($data['status'] ?? null, ['accepted', 'duplicate'], true)) {
                return ['result' => 'acknowledged', 'code' => $data['status']];
            }

            return ['result' => 'retry', 'code' => 'invalid_acknowledgement'];
        }
        if ($response->status() === 429 || $response->status() >= 500 || $response->status() === 408) {
            return ['result' => 'retry', 'code' => 'remote_unavailable'];
        }

        return ['result' => 'failed', 'code' => 'remote_rejected'];
    }
}
