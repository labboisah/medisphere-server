<?php

namespace App\Services;

use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use KernelBridge\LicensingClient\Services\LicenseCacheService;

final class InstallationBinding
{
    public function __construct(private readonly LicenseCacheService $cache) {}

    /** Adopts the platform-registered installation UUID, only while this installation holds no credential. */
    public function bind(string $uuid): void
    {
        $uuid = Str::lower(trim($uuid));
        if (! Str::isUuid($uuid)) {
            throw ValidationException::withMessages(['installation_uuid' => 'Enter the installation ID exactly as shown in MediFlow Platform.']);
        }

        $state = $this->cache->state();
        if ($state->installation_identifier === $uuid) {
            return;
        }
        if ($state->encrypted_installation_credential || $state->encrypted_license_key || $this->cache->hasUsableLicense()) {
            throw ValidationException::withMessages(['installation_uuid' => 'This installation is already activated and cannot change its installation ID. Deactivate it first.']);
        }

        $state->forceFill(['installation_identifier' => $uuid])->save();
    }
}
