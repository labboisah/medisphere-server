<?php

namespace App\Services;

use KernelBridge\LicensingClient\Services\LicenseCacheService;
use RuntimeException;

class OperationalHealthSource
{
    public function __construct(
        private readonly LicenseCacheService $cache,
        private readonly ProductEntitlementService $entitlements,
    ) {}

    /** @return list<string> */
    public function enabledModules(): array
    {
        if (! config('central_licensing.enabled') || ! $this->cache->hasUsableLicense()) {
            throw new RuntimeException('A verified central license is required for operational health.');
        }

        $modules = array_keys(config('entitlement_framework.module_capabilities', []));
        $enabled = array_values(array_filter($modules, fn (string $module): bool => $this->entitlements->moduleEnabled($module)));
        sort($enabled, SORT_STRING);

        return $enabled;
    }
}
