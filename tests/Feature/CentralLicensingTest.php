<?php

namespace Tests\Feature;

use App\Http\Middleware\CheckPermission;
use App\Http\Middleware\CheckRole;
use App\Http\Middleware\CheckRoleOrPermission;
use App\Http\Middleware\EnsureCentralLicense;
use App\Http\Middleware\EnsureInstallationModules;
use App\Livewire\Dashboard;
use App\Models\Role;
use App\Models\User;
use App\Providers\CentralLicensingServiceProvider;
use App\Services\CentralLicenseService;
use App\Services\InstallationCatalog;
use App\Services\InstallationOnboarding;
use App\Services\LicenseService;
use App\Services\ProductEntitlementService;
use App\Services\OperationalSyncLicenseProof;
use App\Exceptions\OperationalSyncEnrollmentException;
use App\Services\SidebarService;
use Dotenv\Repository\Adapter\PutenvAdapter;
use Dotenv\Repository\RepositoryBuilder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use KernelBridge\LicensingClient\Services\DeviceIdentity;
use KernelBridge\LicensingClient\Services\LicenseCacheService;
use KernelBridge\LicensingClient\Services\LicenseVerificationService;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class CentralLicensingTest extends TestCase
{
    private LicenseCacheService $cache;

    public function test_client_activation_is_enabled_when_app_mode_is_not_set(): void
    {
        $environment = RepositoryBuilder::createWithDefaultAdapters()
            ->addAdapter(PutenvAdapter::class)
            ->make();
        $oldMode = $environment->get('APP_MODE');
        $oldFlag = $environment->get('MEDIFLOW_CENTRAL_LICENSING');

        try {
            $environment->clear('APP_MODE');
            $environment->set('MEDIFLOW_CENTRAL_LICENSING', 'false');

            $this->assertTrue((require config_path('central_licensing.php'))['enabled']);
            config(['central_licensing' => require config_path('central_licensing.php')]);
            $this->get('/')->assertRedirect(route('kernelbridge.license.show'));
        } finally {
            $oldMode === null ? $environment->clear('APP_MODE') : $environment->set('APP_MODE', $oldMode);
            $oldFlag === null ? $environment->clear('MEDIFLOW_CENTRAL_LICENSING') : $environment->set('MEDIFLOW_CENTRAL_LICENSING', $oldFlag);
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        config([
            'database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:',
            'central_licensing.enabled' => true, 'session.driver' => 'array',
            'kernelbridge-licensing.product_code' => 'MEDIFLOW',
            'kernelbridge-licensing.api_url' => 'https://licensing.test/api/v1',
            'kernelbridge-licensing.api_token' => 'test-token',
            'kernelbridge-licensing.signature_key' => 'test-cache-signing-secret',
        ]);
        foreach (glob(base_path('vendor/kernelbridge/licensing-client-laravel/database/migrations/*.php')) as $path) {
            (require $path)->up();
        }
        $this->mock(DeviceIdentity::class, function ($mock): void {
            $mock->shouldReceive('fingerprint')->andReturn(str_repeat('a', 64));
            $mock->shouldReceive('information')->andReturn(['hostname' => 'CLINIC-01']);
        });
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->boolean('is_installation_admin')->default(false);
            $table->rememberToken();
            $table->softDeletes();
            $table->timestamps();
        });
        (require database_path('migrations/2026_10_01_000001_create_installation_onboarding.php'))->up();
        (require database_path('migrations/2026_09_28_000001_create_system_settings_table.php'))->up();
        (require database_path('migrations/2026_09_30_000002_link_installation_administrator.php'))->up();
        (require database_path('migrations/2026_09_30_000003_add_welcome_appearance_to_system_settings.php'))->up();
        Schema::create('roles', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('display_name')->nullable();
            $table->timestamps();
        });
        Schema::create('role_user', function (Blueprint $table): void {
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('role_id');
        });
        $this->cache = app(LicenseCacheService::class);
    }

    public function test_client_mode_requires_activation_even_when_optional_flag_is_false(): void
    {
        Http::fake([
            $this->licensingPolicyUrl() => Http::response([
                'success' => true,
                'data' => $this->signedLicensingPolicy(),
            ]),
        ]);
        $environment = RepositoryBuilder::createWithDefaultAdapters()
            ->addAdapter(PutenvAdapter::class)->make();
        $oldMode = $environment->get('APP_MODE');
        $oldFlag = $environment->get('MEDIFLOW_CENTRAL_LICENSING');
        try {
            $environment->set('APP_MODE', 'client');
            $environment->set('MEDIFLOW_CENTRAL_LICENSING', 'false');
            config(['central_licensing' => require config_path('central_licensing.php')]);
            $this->assertTrue(config('central_licensing.enabled'));
            (new CentralLicensingServiceProvider($this->app))->register();
            $this->assertInstanceOf(CentralLicenseService::class, app(LicenseService::class));
            $this->get('/')->assertRedirect(route('kernelbridge.license.show'));
            $this->get('/license')->assertOk();
            $environment->set('APP_MODE', 'standalone');
            $this->assertFalse((require config_path('central_licensing.php'))['enabled']);
        } finally {
            $oldMode === null ? $environment->clear('APP_MODE') : $environment->set('APP_MODE', $oldMode);
            $oldFlag === null ? $environment->clear('MEDIFLOW_CENTRAL_LICENSING') : $environment->set('MEDIFLOW_CENTRAL_LICENSING', $oldFlag);
        }
    }

    public function test_server_url_is_normalized_saved_and_untrusted_address_is_rejected(): void
    {
        Http::fake();
        $this->post('/license', ['license_key' => 'test', 'server_url' => 'https://untrusted.example'])->assertSessionHasErrors('server_url');
        Http::assertNothingSent();
        Http::swap(new Factory);
        $this->fakeActivation();
        $this->post('/license', ['license_key' => 'KBT-TEST', 'server_url' => 'https://licensing.test'])->assertRedirect(route('installation.setup'));
        $this->assertSame('https://licensing.test/api/v1', Storage::disk('local')->get('licensing/server-url.txt'));
    }

    public function test_installation_code_activates_without_sending_a_product_api_credential(): void
    {
        $keyPair = sodium_crypto_sign_keypair();
        $secretKey = sodium_crypto_sign_secretkey($keyPair);
        $publicKey = sodium_crypto_sign_publickey($keyPair);
        config(['kernelbridge-licensing.entitlement_public_keys' => [
            'mediflow-test-key' => base64_encode($publicKey),
        ]]);
        $installationUuid = $this->cache->state()->installation_identifier;
        $claims = [
            'version' => 1,
            'issued_at' => now()->toIso8601String(),
            'product_code' => 'MEDIFLOW',
            'licensing_mode' => 'direct',
            'licensing_policy_revision' => 1,
            'licensing_policy_effective_at' => null,
            'installation_uuid' => $installationUuid,
            'platform_account_link_uuid' => null,
            'business_uuid' => null,
            'license_uuid' => 'license-code-exchange-test',
            'license_status' => 'active',
            'subscription_uuid' => 'subscription-code-exchange-test',
            'plan' => ['uuid' => 'plan-code-exchange-test', 'name' => 'Test'],
            'expires_at' => now()->addYear()->toIso8601String(),
            'grace_expires_at' => null,
            'entitlements' => [
                ['key' => 'core', 'name' => 'Core', 'enabled' => true, 'limit' => null],
                ['key' => 'pharmacy', 'name' => 'Pharmacy', 'enabled' => true, 'limit' => null],
            ],
            'entitlement_revision' => 1,
            'entitlement_digest' => 'test',
        ];
        $payloadJson = json_encode($claims, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $signedEntitlements = [
            'payload_json' => $payloadJson,
            'signature' => base64_encode(sodium_crypto_sign_detached($payloadJson, $secretKey)),
            'algorithm' => 'ed25519',
            'key_id' => 'mediflow-test-key',
        ];
        Http::fake([
            $this->licensingPolicyUrl() => Http::response([
                'success' => true,
                'data' => $this->signedLicensingPolicy(),
            ]),
            '*' => Http::response(['success' => true, 'data' => [
                'installation_uuid' => $installationUuid,
                'license_uuid' => $claims['license_uuid'],
                'product_code' => 'MEDIFLOW',
                'credential_type' => 'bearer',
                'credential' => $installationUuid.'.test-secret',
                'expires_at' => $claims['expires_at'],
                'signed_entitlements' => $signedEntitlements,
                'created' => true,
            ]], 201),
        ]);

        $this->get('/license')->assertOk()
            ->assertSee('KernelBridge')
            ->assertSee('vendor/kernelbridge-licensing/kernelbridge-logo.png')
            ->assertSee('<title>Activate KernelBridge</title>', false)
            ->assertSee('Activate your MediFlow installation.')
            ->assertSee('name="activation_code"', false)
            ->assertDontSee('MEDIFLOW ? ACTIVATE ? INSTALLATION SETUP ? WELCOME')
            ->assertDontSee('name="server_url"', false);
        $this->post('/license', [
            'activation_code' => 'KBI-ONE-TIME-TEST',
            'device_name' => 'MediFlow test',
            'server_url' => 'https://licensing.test',
        ])->assertRedirect(route('installation.setup'));

        Http::assertSent(fn (ClientRequest $request): bool => str_ends_with($request->url(), '/installations/activate')
            && ($request->data()['activation_code'] ?? null) === 'KBI-ONE-TIME-TEST'
            && ! $request->hasHeader('Authorization'));
        $this->assertSame('installation_bearer', $this->cache->state()->credential_kind);
        $this->assertSame($installationUuid.'.test-secret', $this->cache->state()->encrypted_installation_credential);
        $this->assertTrue($this->cache->hasFeature('pharmacy'));
        $this->assertSame('https://licensing.test/api/v1', Storage::disk('local')->get('licensing/server-url.txt'));
    }

    public function test_sync_proof_uses_installation_credential_and_requires_trusted_challenge_signature(): void
    {
        config(['kernelbridge-licensing.deployment.strict_profile' => false]);
        $this->activateWithFramework([], ['core']);
        $id = $this->cache->state()->installation_identifier;
        $keys = sodium_crypto_sign_keypair();
        config(['kernelbridge-licensing.entitlement_public_keys.proof-test' => base64_encode(sodium_crypto_sign_publickey($keys))]);
        $challenge = hash('sha256', 'synthetic sync code');
        $claims = json_encode([
            'purpose' => 'mediflow.operational-sync.enrollment',
            'challenge' => $challenge, 'installation_uuid' => $id,
        ], JSON_THROW_ON_ERROR);
        $proof = [
            'payload_json' => $claims,
            'signature' => base64_encode(sodium_crypto_sign_detached($claims, sodium_crypto_sign_secretkey($keys))),
            'algorithm' => 'ed25519', 'key_id' => 'proof-test',
        ];
        Http::fake(['*' => Http::sequence()->push(['success' => true, 'data' => $proof])
            ->push(['success' => true, 'data' => [...$proof, 'signature' => base64_encode(random_bytes(64))]])]);
        $result = app(OperationalSyncLicenseProof::class)->create($challenge);
        $this->assertSame($id, $result['installation_uuid']);
        Http::assertSent(fn (ClientRequest $request): bool => str_ends_with($request->url(), '/installations/operational-sync-proof')
            && $request->hasHeader('Authorization', 'Bearer '.$id.'.framework-test-secret')
            && $request['challenge'] === $challenge);
        $this->expectException(OperationalSyncEnrollmentException::class);
        app(OperationalSyncLicenseProof::class)->create($challenge);
    }

    public function test_central_activation_uses_package_dashboard_without_legacy_license_record(): void
    {
        $this->activate();
        $this->app->bind(LicenseService::class, CentralLicenseService::class);
        $this->mock(SidebarService::class, function ($mock): void {
            $mock->shouldReceive('groupsFor')->twice()->andReturn([]);
        });
        // No legacy licence or hospital data tables exist in this fixture.
        $this->assertSame('components.installation-dashboard', (new \App\Livewire\Admin\Dashboard)->render()->name());
        $this->assertSame('components.installation-dashboard', (new Dashboard)->render()->name());
    }

    public function test_admin_routes_still_require_activated_features(): void
    {
        $this->activate();
        $service = new CentralLicenseService($this->cache);
        $this->assertFalse($service->routeEnabled('admin.wards.index'));
        $this->assertFalse($service->routeEnabled('admin.investigations.index'));
        $this->assertFalse($service->routeEnabled('admin.bills.investigations.store'));
        $this->assertFalse($service->routeEnabled('admin.sync.index'));
        $this->assertTrue($service->routeEnabled('admin.users.index'));
        $this->assertTrue($service->routeEnabled('admin.installation'));
        $this->app->instance(LicenseService::class, $service);
        $request = Request::create('/admin/wards');
        $route = new Route('GET', 'admin/wards', fn () => null);
        $route->name('admin.wards.index');
        $request->setRouteResolver(fn () => $route);
        $this->expectException(HttpException::class);
        (new EnsureInstallationModules)->handle($request, fn () => new Response('must not run'));
    }

    public function test_purchased_packages_and_modules_replace_unlicensed_local_presets(): void
    {
        $this->activate();
        $service = new CentralLicenseService($this->cache);
        $this->assertSame('pharmacy', $service->currentPlan());
        $this->assertSame(['pharmacy', 'general_clinic'], $service->selectedPackages());
        $this->assertTrue($service->moduleEnabled('pharmacy'));
        $this->assertTrue($service->moduleEnabled('doctor'));
        $this->assertFalse($service->moduleEnabled('maternity'));
    }

    public function test_framework_grants_are_authoritative_at_route_and_module_boundaries(): void
    {
        $this->activateWithFramework([
            'mediflow.module.pharmacy' => ['type' => 'capability', 'enabled' => true],
        ], ['pharmacy', 'doctor', 'maternity']);

        $service = new CentralLicenseService($this->cache);
        $this->assertTrue($service->moduleEnabled('pharmacy'));
        $this->assertFalse($service->moduleEnabled('doctor'));
        $this->assertFalse($service->moduleEnabled('maternity'));
        $this->assertTrue($service->routeEnabled('pharmacy.products.index'));
        $this->assertFalse($service->routeEnabled('pharmacy.transactions.index'));
        $this->assertFalse($service->routeEnabled('doctor.patients.index'));
        $this->assertFalse($service->moduleEnabled('unknown_module'));
        $diagnostics = $service->entitlementDiagnostics();
        $this->assertSame('direct', $diagnostics['source']);
        $this->assertSame(3, $diagnostics['catalog_revision']);
        $this->assertSame(12, $diagnostics['source_revision']);
        $this->assertSame(8, $diagnostics['entitlement_revision']);
        $this->assertArrayNotHasKey('entitlement_framework', $diagnostics);
        $this->assertArrayNotHasKey('credential', $diagnostics);

        $this->app->instance(LicenseService::class, $service);
        $request = Request::create('/doctor/patients', 'GET', server: ['HTTP_ACCEPT' => 'application/json']);
        $route = new Route('GET', 'doctor/patients', fn () => null);
        $route->name('doctor.patients.index');
        $request->setRouteResolver(fn () => $route);
        $response = (new EnsureInstallationModules)->handle($request, fn () => new Response('must not run'));

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame('missing_capability', $response->getData(true)['error']['code']);
    }

    public function test_strict_permission_and_role_checks_require_signed_capabilities(): void
    {
        config(['entitlement_framework.strict_enforcement' => true]);
        $this->activateWithFramework([
            'mediflow.module.access_control' => ['type' => 'capability', 'enabled' => true],
            'mediflow.module.pharmacy' => ['type' => 'capability', 'enabled' => true],
        ], ['pharmacy', 'doctor']);

        Schema::create('permissions', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('module')->nullable();
            $table->unsignedBigInteger('module_id')->nullable();
            $table->timestamps();
        });
        Schema::create('role_permission', function (Blueprint $table): void {
            $table->unsignedBigInteger('role_id');
            $table->unsignedBigInteger('permission_id');
        });
        Schema::create('temporary_permissions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('permission_id');
            $table->unsignedBigInteger('granted_by')->nullable();
            $table->string('reason')->nullable();
            $table->timestamp('expires_at');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        DB::table('roles')->insert([
            ['id' => 1, 'name' => 'pharmacist', 'display_name' => 'Pharmacist', 'created_at' => now(), 'updated_at' => now()],
            ['id' => 2, 'name' => 'doctor', 'display_name' => 'Doctor', 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::table('users')->insert([
            'id' => 1,
            'name' => 'Test User',
            'email' => 'test@example.test',
            'password' => 'hashed-password',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('role_user')->insert([
            ['user_id' => 1, 'role_id' => 1],
            ['user_id' => 1, 'role_id' => 2],
        ]);
        DB::table('permissions')->insert([
            ['id' => 1, 'name' => 'medicine.read', 'module' => 'Pharmacy', 'created_at' => now(), 'updated_at' => now()],
            ['id' => 2, 'name' => 'patient.read', 'module' => 'Patient', 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::table('role_permission')->insert([
            ['role_id' => 1, 'permission_id' => 1],
            ['role_id' => 1, 'permission_id' => 2],
        ]);
        $user = User::findOrFail(1);
        $this->assertTrue(app(ProductEntitlementService::class)->strictEnforcementEnabled());
        $this->assertFalse((new CentralLicenseService($this->cache))->moduleEnabled('doctor'));
        $this->assertTrue($user->hasPermission('medicine.read'));
        $this->assertFalse($user->hasPermission('patient.read'));
        $this->assertFalse($user->hasAnyPermission(['patient.read']));
        $this->assertFalse($user->hasRole('doctor'));
        $catalog = app(InstallationCatalog::class);
        $this->assertSame([1], $catalog->permissions()->pluck('id')->all());
        $this->assertSame([2], $catalog->retainedUnavailablePermissionIds(Role::findOrFail(1)));
        $catalog->roleInput('custom_staff', [1]);
        try {
            $catalog->roleInput('custom_staff', [1, 2]);
            $this->fail('A role must not receive permissions outside the signed plan capabilities.');
        } catch (ValidationException) {
            $this->assertTrue(true);
        }

        $grantedPermission = Request::create('/pharmacy');
        $grantedPermission->setUserResolver(fn () => $user);
        $response = (new CheckPermission)->handle(
            $grantedPermission,
            fn () => new Response('allowed'),
            'medicine.read',
        );
        $this->assertSame('allowed', $response->getContent());

        $missingCapability = Request::create('/patients');
        $missingCapability->setUserResolver(fn () => $user);
        $response = (new CheckPermission)->handle(
            $missingCapability,
            fn () => new Response('must not run'),
            'patient.read',
        );
        $this->assertSame(403, $response->getStatusCode());

        $missingRoleCapability = Request::create('/doctor');
        $missingRoleCapability->setUserResolver(fn () => $user);
        $response = (new CheckRole)->handle(
            $missingRoleCapability,
            fn () => new Response('must not run'),
            'doctor',
        );
        $this->assertSame(403, $response->getStatusCode());

        $roleOrPermission = Request::create('/doctor');
        $roleOrPermission->setUserResolver(fn () => $user);
        $response = (new CheckRoleOrPermission)->handle(
            $roleOrPermission,
            fn () => new Response('must not run'),
            'role:doctor',
            'permission:patient.read',
        );
        $this->assertSame(403, $response->getStatusCode());
    }

    public function test_strict_mode_does_not_fall_back_to_legacy_entitlements(): void
    {
        config(['entitlement_framework.strict_enforcement' => true]);
        $this->activate();

        $this->assertFalse((new CentralLicenseService($this->cache))->moduleEnabled('pharmacy'));
    }

    public function test_product_catalog_has_stable_module_grants_and_plan_labels_are_not_runtime_keys(): void
    {
        $catalog = app(ProductEntitlementService::class)->catalog();
        $this->assertSame('MEDIFLOW', $catalog['product_code']);
        $this->assertSame(1, $catalog['schema_version']);
        $pharmacyPlan = collect($catalog['plans'])->firstWhere('key', 'pharmacy');
        $this->assertSame('capability', $pharmacyPlan['grants']['mediflow.module.pharmacy']['type']);
        $catalogKeys = collect($catalog['packages'])
            ->flatMap(fn (array $package) => $package['modules'])
            ->flatMap(fn (array $module) => $module['capabilities'])
            ->pluck('key')
            ->all();
        $this->assertCount(count(array_unique($catalogKeys)), $catalogKeys);
        $this->assertContains('mediflow.module.pharmacy', $catalogKeys);
        $this->assertContains('mediflow.module.branch_management', $catalogKeys);
    }

    public function test_expired_or_copied_cache_cannot_unlock_modules(): void
    {
        $this->activate();
        $this->mock(DeviceIdentity::class, function ($mock): void {
            $mock->shouldReceive('fingerprint')->andReturn(str_repeat('b', 64));
        });
        $this->assertFalse((new CentralLicenseService($this->cache))->moduleEnabled('pharmacy'));
        $this->assertFalse($this->cache->hasUsableLicense());
    }

    public function test_unlicensed_api_request_is_blocked_and_activation_is_reachable(): void
    {
        $middleware = new EnsureCentralLicense;
        $response = $middleware->handle(Request::create('/license'), fn () => new Response('activate'));
        $this->assertSame('activate', $response->getContent());
        $response = $middleware->handle(
            Request::create('/api/v1/sync'),
            fn () => new Response('should not run'),
        );
        $this->assertSame(402, $response->getStatusCode());
        $this->assertSame('license_unavailable', $response->getData(true)['error']['code']);
    }

    public function test_outage_respects_offline_grace_and_expired_grace_is_rejected(): void
    {
        $this->activate();
        DB::table('installation_onboarding')->update(['completed_at' => now()]);
        Http::fake(['*' => Http::response([], 503)]);
        config(['kernelbridge-licensing.verification_interval_minutes' => 0]);
        $middleware = new EnsureCentralLicense;
        $response = $middleware->handle(Request::create('/dashboard'), fn () => new Response('allowed'));
        $this->assertSame('allowed', $response->getContent());
        $this->travel(73)->hours();
        $this->assertFalse($this->cache->hasUsableLicense());
        $apiResponse = $middleware->handle(
            Request::create('/api/v1/sync', 'GET', server: ['HTTP_ACCEPT' => 'application/json']),
            fn () => new Response('blocked'),
        );
        $this->assertSame(402, $apiResponse->getStatusCode());
        $this->assertSame('stale_snapshot', $apiResponse->getData(true)['error']['code']);
        $this->assertSame(302, $middleware->handle(Request::create('/dashboard'), fn () => new Response('blocked'))->getStatusCode());
    }

    public function test_public_activation_and_protected_refresh(): void
    {
        Http::fake([
            $this->licensingPolicyUrl() => Http::response([
                'success' => true,
                'data' => $this->signedLicensingPolicy(),
            ]),
        ]);
        $this->get('/license')->assertOk()->assertSee('KernelBridge');
        $this->post('/license/verify')->assertRedirect(route('login'));
        $user = new User(['name' => 'Ordinary', 'email' => 'ordinary@example.test']);
        $user->id = 1;
        $this->actingAs($user)->post('/license/verify')->assertForbidden();
    }

    public function test_first_run_activation_setup_and_completion(): void
    {
        $this->get('/')->assertRedirect(route('kernelbridge.license.show'));
        $this->fakeActivation();
        $this->post('/license', ['license_key' => 'KBT-TEST'])->assertRedirect(route('installation.setup'));
        $this->get('/installation/setup')->assertOk()->assertSee('Pharmacy')->assertSee('Dispense safely');
        $this->post('/installation/setup', $this->setupData())->assertRedirect('/');
        $admin = User::firstOrFail();
        $this->assertTrue($admin->is_installation_admin);
        $this->assertTrue(Hash::check('very-strong-password', $admin->password));
        $this->assertAuthenticatedAs($admin);
        $this->assertDatabaseHas('system_settings', ['id' => 1, 'brand_name' => 'Example Clinic']);
        $this->assertTrue(app(InstallationOnboarding::class)->completed());
        $response = (new EnsureCentralLicense)->handle(Request::create('/dashboard'), fn () => new Response('ready'));
        $this->assertSame('ready', $response->getContent());
        $this->post('/installation/setup', $this->setupData())->assertStatus(409);
        $this->assertDatabaseCount('users', 1);
    }

    public function test_cache_without_browser_grant_cannot_create_first_admin(): void
    {
        $this->activate();
        $this->get('/installation/setup')->assertForbidden();
        $this->post('/installation/setup', $this->setupData())->assertForbidden();
        $this->assertDatabaseCount('users', 0);
    }

    public function test_expired_bootstrap_grant_cannot_create_admin_and_can_reopen_activation(): void
    {
        $this->fakeActivation();
        $this->post('/license', ['license_key' => 'KBT-TEST'])->assertRedirect(route('installation.setup'));
        $this->travel(31)->minutes();
        $this->get('/license')->assertOk();
        $this->post('/installation/setup', $this->setupData())->assertForbidden();
        $this->assertDatabaseCount('users', 0);
    }

    public function test_existing_admin_prevents_public_reactivation_takeover(): void
    {
        User::withoutEvents(function (): void {
            $user = new User(['name' => 'Owner', 'email' => 'owner@example.test', 'password' => 'old-password']);
            $user->is_installation_admin = true;
            $user->save();
        });
        Http::fake();
        $this->post('/license', ['license_key' => 'KBT-TEST'])->assertRedirect(route('login'));
        Http::assertNothingSent();
        $this->assertDatabaseCount('users', 1);
    }

    public function test_refresh_replaces_signed_package_configuration_without_recreating_setup(): void
    {
        $this->fakeActivation();
        $this->post('/license', ['license_key' => 'KBT-TEST']);
        $this->post('/installation/setup', $this->setupData())->assertRedirect('/');
        Http::swap(new Factory);
        Http::fake(['*' => Http::response(['success' => true, 'data' => [
            'license_uuid' => 'license-test', 'subscription_uuid' => 'subscription-test',
            'activation_uuid' => 'activation-test', 'expires_at' => now()->addYear()->toISOString(),
            'configuration' => ['revision' => 2, 'packages' => [['key' => 'general_clinic', 'name' => 'General Clinic', 'features' => []]]],
            'subscription' => ['status' => 'active', 'ends_at' => now()->addYear()->toISOString(),
                'primary_package' => 'general_clinic', 'selected_packages' => ['general_clinic']],
            'entitlements' => ['entitlements' => [['key' => 'core', 'enabled' => true], ['key' => 'doctor', 'enabled' => true]]],
        ]])]);
        $this->post('/license/verify')->assertRedirect(route('admin.installation'));
        $this->assertSame(2, $this->cache->state()->entitlement_payload['configuration']['revision']);
        $service = new CentralLicenseService($this->cache);
        $this->assertTrue($service->moduleEnabled('doctor'));
        $this->assertFalse($service->moduleEnabled('pharmacy'));
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseHas('system_settings', ['brand_name' => 'Example Clinic']);
    }

    public function test_platform_account_activation_stores_credential_and_exposes_only_safe_status(): void
    {
        $uuid = $this->cache->state()->installation_identifier;
        $signed = $this->platformSnapshot(['platform_account_link_uuid' => 'link-1', 'licensing_mode' => 'platform_account']);
        Http::fake(['*' => Http::response(['success' => true, 'data' => $this->exchangeBody($uuid, $signed)], 201)]);

        $this->post('/license', ['activation_code' => 'KBI-PLATFORM', 'server_url' => 'https://licensing.test'])
            ->assertRedirect(route('installation.setup'));

        Http::assertSent(fn (ClientRequest $request): bool => str_ends_with($request->url(), '/installations/activate')
            && $request->data()['activation_code'] === 'KBI-PLATFORM'
            && $request->data()['product_code'] === 'MEDIFLOW'
            && $request->data()['installation_uuid'] === $uuid
            && ! $request->hasHeader('Authorization'));
        $state = $this->cache->state();
        $this->assertSame('installation_bearer', $state->credential_kind);
        $this->assertSame($uuid.'.platform-secret', $state->encrypted_installation_credential);
        $diagnostics = app(ProductEntitlementService::class)->diagnostics();
        $this->assertSame('platform_account', $diagnostics['activation_source']);
        $this->assertSame('platform_account', $diagnostics['licensing_mode']);
        $this->assertSame($uuid, $diagnostics['installation_uuid']);
        $this->assertTrue($diagnostics['signature_verified']);
        $this->assertNotNull($diagnostics['snapshot_issued_at']);
        $this->assertNotNull($diagnostics['offline_grace_expires_at']);
        $this->assertStringNotContainsString('platform-secret', json_encode($diagnostics));
        $this->assertStringNotContainsString('KBI-PLATFORM', json_encode($diagnostics));
    }

    public function test_platform_activation_rejects_wrong_installation_or_product_binding(): void
    {
        $uuid = $this->cache->state()->installation_identifier;
        $signed = $this->platformSnapshot();
        foreach ([
            ['installation_uuid' => 'another-installation'],
            ['product_code' => 'OTHERPRODUCT'],
            ['credential' => ''],
        ] as $override) {
            Http::swap(new Factory);
            Http::fake(['*' => Http::response(['success' => true, 'data' => $this->exchangeBody($uuid, $signed, $override)], 201)]);
            $this->post('/license', ['activation_code' => 'KBI-BAD', 'server_url' => 'https://licensing.test'])
                ->assertSessionHasErrors('activation_code');
            $this->assertFalse($this->cache->hasUsableLicense());
            $this->assertNull($this->cache->state()->encrypted_installation_credential);
        }

        foreach ([['installation_uuid' => 'another-installation'], ['product_code' => 'OTHERPRODUCT']] as $claims) {
            Http::swap(new Factory);
            Http::fake(['*' => Http::response(['success' => true, 'data' => $this->exchangeBody($uuid, $this->platformSnapshot($claims))], 201)]);
            $this->post('/license', ['activation_code' => 'KBI-BAD', 'server_url' => 'https://licensing.test'])
                ->assertSessionHasErrors('activation_code');
            $this->assertNull($this->cache->state()->encrypted_installation_credential);
        }
    }

    public function test_platform_activation_rejects_tampered_or_untrusted_signatures(): void
    {
        $uuid = $this->cache->state()->installation_identifier;
        $good = $this->platformSnapshot();
        $tampered = $good;
        $tampered['payload_json'] = str_replace('"enabled":true', '"enabled":false', $tampered['payload_json']).' ';
        $unknownKey = array_merge($good, ['key_id' => 'not-trusted']);
        foreach ([$tampered, $unknownKey] as $signed) {
            Http::swap(new Factory);
            Http::fake(['*' => Http::response(['success' => true, 'data' => $this->exchangeBody($uuid, $signed)], 201)]);
            $this->post('/license', ['activation_code' => 'KBI-SIG', 'server_url' => 'https://licensing.test'])
                ->assertSessionHasErrors('activation_code');
            $this->assertFalse($this->cache->hasUsableLicense());
            $this->assertNull($this->cache->state()->encrypted_installation_credential);
        }
    }

    public function test_platform_activation_can_be_retried_after_an_outage_and_verifies_with_the_credential(): void
    {
        $uuid = $this->cache->state()->installation_identifier;
        $signed = $this->platformSnapshot(['platform_account_link_uuid' => 'link-1']);
        Http::fake(['*' => Http::sequence()
            ->pushFailedConnection()
            ->push(['success' => true, 'data' => $this->exchangeBody($uuid, $signed)], 201)
            ->push(['success' => true, 'data' => $signed], 200)]);

        $this->post('/license', ['activation_code' => 'KBI-RETRY', 'server_url' => 'https://licensing.test'])
            ->assertSessionHasErrors('activation_code');
        $this->assertFalse($this->cache->hasUsableLicense());
        $this->post('/license', ['activation_code' => 'KBI-RETRY', 'server_url' => 'https://licensing.test'])
            ->assertRedirect(route('installation.setup'));
        $this->assertTrue($this->cache->hasUsableLicense());

        app(LicenseVerificationService::class)->verify(true);
        Http::assertSent(fn (ClientRequest $request): bool => str_ends_with($request->url(), '/installations/verify')
            && $request->header('Authorization') === ['Bearer '.$uuid.'.platform-secret']);
    }

    public function test_direct_activation_keeps_direct_source_and_local_state(): void
    {
        $this->activateWithFramework(['mediflow.module.pharmacy' => ['type' => 'capability', 'enabled' => true]], ['pharmacy']);
        $diagnostics = app(ProductEntitlementService::class)->diagnostics();
        $this->assertSame('direct', $diagnostics['activation_source']);
        $this->assertSame('direct', $diagnostics['licensing_mode']);
        $this->assertTrue($this->cache->hasUsableLicense());

        $this->cache->reset();
        $this->activate();
        $this->assertSame('legacy_key', app(ProductEntitlementService::class)->diagnostics()['activation_source']);
    }

    public function test_platform_account_page_supports_code_only_and_legacy_binding(): void
    {
        Http::fake();
        $this->get('/license/platform-account')->assertOk()
            ->assertSee('name="installation_uuid"', false)
            ->assertSee('Leave this blank for newly generated server activation codes.')
            ->assertSee('name="activation_code"', false);
        Http::assertNothingSent();
    }

    public function test_platform_registered_installation_uuid_is_bound_and_consumed_exactly(): void
    {
        $platformUuid = (string) Str::uuid();
        $local = $this->cache->state()->installation_identifier;
        $this->assertNotSame($platformUuid, $local);
        $signed = $this->platformSnapshot(['installation_uuid' => $platformUuid]);
        Http::fake(['*' => Http::response(['success' => true, 'data' => $this->exchangeBody($platformUuid, $signed)], 201)]);

        $this->post('/license', [
            'activation_code' => 'KBI-PLATFORM', 'installation_uuid' => strtoupper($platformUuid),
            'server_url' => 'https://licensing.test',
        ])->assertRedirect(route('installation.setup'));

        Http::assertSent(fn (ClientRequest $request): bool => str_ends_with($request->url(), '/installations/activate')
            && $request->data()['installation_uuid'] === $platformUuid);
        $state = $this->cache->state();
        $this->assertSame($platformUuid, $state->installation_identifier);
        $this->assertSame('installation_bearer', $state->credential_kind);
        $this->assertTrue($this->cache->hasUsableLicense());
    }

    public function test_platform_uuid_mismatch_and_invalid_input_are_rejected_without_credentials(): void
    {
        $platformUuid = (string) Str::uuid();
        $other = (string) Str::uuid();
        $this->post('/license', ['activation_code' => 'KBI-X', 'installation_uuid' => 'not-a-uuid', 'server_url' => 'https://licensing.test'])
            ->assertSessionHasErrors('installation_uuid');

        Http::fake(['*' => Http::response(['success' => false, 'message' => 'The activation code is not bound to this installation.', 'error' => ['code' => 'invalid_activation_code']], 422)]);
        $this->post('/license', ['activation_code' => 'KBI-X', 'installation_uuid' => $platformUuid, 'server_url' => 'https://licensing.test'])
            ->assertSessionHasErrors('activation_code');
        $this->assertNull($this->cache->state()->encrypted_installation_credential);
        $this->assertFalse($this->cache->hasUsableLicense());

        Http::swap(new Factory);
        $signed = $this->platformSnapshot(['installation_uuid' => $other]);
        Http::fake(['*' => Http::response(['success' => true, 'data' => $this->exchangeBody($other, $signed)], 201)]);
        $this->post('/license', ['activation_code' => 'KBI-X', 'installation_uuid' => $platformUuid, 'server_url' => 'https://licensing.test'])
            ->assertSessionHasErrors('activation_code');
        $this->assertNull($this->cache->state()->encrypted_installation_credential);
    }

    public function test_bound_platform_uuid_survives_outage_retry_and_cannot_change_after_activation(): void
    {
        $platformUuid = (string) Str::uuid();
        $signed = $this->platformSnapshot(['installation_uuid' => $platformUuid]);
        Http::fake(['*' => Http::sequence()
            ->pushFailedConnection()
            ->push(['success' => true, 'data' => $this->exchangeBody($platformUuid, $signed)], 201)]);
        $payload = ['activation_code' => 'KBI-RETRY', 'installation_uuid' => $platformUuid, 'server_url' => 'https://licensing.test'];

        $this->post('/license', $payload)->assertSessionHasErrors('activation_code');
        $this->assertSame($platformUuid, $this->cache->state()->installation_identifier);
        $this->post('/license', $payload)->assertRedirect(route('installation.setup'));
        $this->assertTrue($this->cache->hasUsableLicense());

        $this->post('/license', ['activation_code' => 'KBI-N', 'installation_uuid' => (string) Str::uuid(), 'server_url' => 'https://licensing.test'])
            ->assertSessionHasErrors('installation_uuid');
        $this->assertSame($platformUuid, $this->cache->state()->installation_identifier);
    }

    public function test_direct_activation_without_binding_keeps_local_installation_uuid(): void
    {
        $local = $this->cache->state()->installation_identifier;
        $this->fakeActivation();
        $this->post('/license', ['license_key' => 'KBT-TEST', 'server_url' => 'https://licensing.test'])
            ->assertRedirect(route('installation.setup'));
        $this->assertSame($local, $this->cache->state()->installation_identifier);
        $this->post('/license', ['license_key' => 'KBT-TEST', 'installation_uuid' => (string) Str::uuid()])
            ->assertSessionHasErrors('installation_uuid');
        $this->assertSame($local, $this->cache->state()->installation_identifier);
    }

    public function test_insight_catalog_uses_verified_effective_module_grants(): void
    {
        $catalog = app(\App\Services\InsightCategoryCatalog::class);
        $this->assertNull($catalog->availableForInstallation());
        $this->activateWithFramework([
            'mediflow.module.pharmacy' => ['type' => 'capability', 'enabled' => true],
            'mediflow.module.patient_records' => ['type' => 'capability', 'enabled' => false],
        ], ['pharmacy', 'patient_records']);
        $keys = array_column($catalog->availableForInstallation(), 'key');
        $this->assertContains('medicine_stock', $keys);
        $this->assertNotContains('patients', $keys);
        $this->cache->reset();
        $this->assertNull($catalog->availableForInstallation());
    }

    /** @return array{algorithm: string, key_id: string, payload_json: string, signature: string} */
    private function platformSnapshot(array $claimOverrides = []): array
    {
        $keyPair = sodium_crypto_sign_keypair();
        config(['kernelbridge-licensing.entitlement_public_keys' => [
            'platform-test-key' => base64_encode(sodium_crypto_sign_publickey($keyPair)),
        ]]);
        $claims = array_merge([
            'version' => 1,
            'issued_at' => now()->toIso8601String(),
            'product_code' => 'MEDIFLOW',
            'licensing_mode' => 'platform_account',
            'licensing_policy_revision' => 1,
            'licensing_policy_effective_at' => null,
            'installation_uuid' => $this->cache->state()->installation_identifier,
            'platform_account_link_uuid' => 'link-1',
            'business_uuid' => 'business-1',
            'license_uuid' => 'license-platform-test',
            'license_status' => 'active',
            'subscription_uuid' => 'subscription-platform-test',
            'plan' => ['uuid' => 'plan-platform-test', 'name' => 'Test'],
            'expires_at' => now()->addYear()->toIso8601String(),
            'grace_expires_at' => null,
            'entitlements' => [['key' => 'core', 'name' => 'Core', 'enabled' => true, 'limit' => null]],
            'entitlement_revision' => 1,
            'entitlement_digest' => 'test',
        ], $claimOverrides);
        $payloadJson = json_encode($claims, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        return [
            'payload_json' => $payloadJson,
            'signature' => base64_encode(sodium_crypto_sign_detached($payloadJson, sodium_crypto_sign_secretkey($keyPair))),
            'algorithm' => 'ed25519',
            'key_id' => 'platform-test-key',
        ];
    }

    private function exchangeBody(string $uuid, array $signed, array $override = []): array
    {
        return array_merge([
            'installation_uuid' => $uuid,
            'license_uuid' => 'license-platform-test',
            'product_code' => 'MEDIFLOW',
            'credential_type' => 'bearer',
            'credential' => $uuid.'.platform-secret',
            'signed_entitlements' => $signed,
        ], $override);
    }

    private function setupData(): array
    {
        return ['brand_name' => 'Example Clinic', 'welcome_template' => 'auto',
            'admin_name' => 'Owner', 'admin_email' => 'owner@example.test',
            'password' => 'very-strong-password', 'password_confirmation' => 'very-strong-password'];
    }

    private function fakeActivation(): void
    {
        Http::fake(['*' => Http::response(['success' => true, 'data' => [
            'license_uuid' => 'license-test', 'subscription_uuid' => 'subscription-test',
            'activation_uuid' => 'activation-test', 'expires_at' => now()->addYear()->toISOString(),
            'configuration' => ['revision' => 1, 'packages' => [[
                'key' => 'pharmacy', 'name' => 'Pharmacy', 'description' => 'Dispense safely',
                'features' => [['key' => 'pharmacy', 'name' => 'Pharmacy', 'description' => 'Manage medicines', 'required' => false]],
            ]]],
            'subscription' => ['status' => 'active', 'ends_at' => now()->addYear()->toISOString(),
                'primary_package' => 'pharmacy', 'selected_packages' => ['pharmacy']],
            'entitlements' => ['entitlements' => [['key' => 'core', 'enabled' => true], ['key' => 'pharmacy', 'enabled' => true]]],
        ]])]);
    }

    private function activate(): void
    {
        $this->cache->storeVerified('KBT-TEST', [
            'license_uuid' => 'license-test', 'subscription_uuid' => 'subscription-test',
            'activation_uuid' => 'activation-test', 'expires_at' => now()->addYear()->toISOString(),
        ], [
            'status' => 'active', 'ends_at' => now()->addYear()->toISOString(),
            'primary_package' => 'pharmacy', 'selected_packages' => ['pharmacy', 'general_clinic'],
        ], [
            'entitlements' => [
                ['key' => 'core', 'enabled' => true], ['key' => 'access_control', 'enabled' => true],
                ['key' => 'pharmacy', 'enabled' => true], ['key' => 'doctor', 'enabled' => true],
                ['key' => 'maternity', 'enabled' => false],
            ],
        ]);
    }

    private function licensingPolicyUrl(): string
    {
        return 'https://licensing.test/api/v1/licensing/products/MEDIFLOW/policy';
    }

    /**
     * @return array{algorithm: string, key_id: string, payload_json: string, signature: string}
     */
    private function signedLicensingPolicy(): array
    {
        $keyPair = sodium_crypto_sign_keypair();
        $secretKey = sodium_crypto_sign_secretkey($keyPair);
        $keyId = 'mediflow-policy-test';
        $trustedKeys = config('kernelbridge-licensing.entitlement_public_keys', []);
        $trustedKeys[$keyId] = base64_encode(sodium_crypto_sign_publickey($keyPair));
        config(['kernelbridge-licensing.entitlement_public_keys' => $trustedKeys]);
        $payloadJson = json_encode([
            'version' => 1,
            'policy_type' => 'licensing_mode',
            'issued_at' => now()->toIso8601String(),
            'product_code' => 'MEDIFLOW',
            'licensing_mode' => 'direct',
            'licensing_policy_revision' => 1,
            'licensing_policy_effective_at' => null,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        return [
            'algorithm' => 'ed25519',
            'key_id' => $keyId,
            'payload_json' => $payloadJson,
            'signature' => base64_encode(sodium_crypto_sign_detached($payloadJson, $secretKey)),
        ];
    }

    /**
     * @param  array<string, array{type: string, enabled: bool}>  $grants
     * @param  list<string>  $legacyModules
     */
    private function activateWithFramework(array $grants, array $legacyModules): void
    {
        $keyPair = sodium_crypto_sign_keypair();
        $secretKey = sodium_crypto_sign_secretkey($keyPair);
        config(['kernelbridge-licensing.entitlement_public_keys' => [
            'mediflow-framework-test' => base64_encode(sodium_crypto_sign_publickey($keyPair)),
        ]]);
        $installationUuid = $this->cache->state()->installation_identifier;
        $claims = [
            'version' => 1,
            'issued_at' => now()->toIso8601String(),
            'product_code' => 'MEDIFLOW',
            'licensing_mode' => 'direct',
            'licensing_policy_revision' => 1,
            'licensing_policy_effective_at' => null,
            'installation_uuid' => $installationUuid,
            'platform_account_link_uuid' => null,
            'business_uuid' => null,
            'license_uuid' => 'license-framework-test',
            'license_status' => 'active',
            'subscription_uuid' => 'subscription-framework-test',
            'plan' => ['uuid' => 'plan-framework-test', 'name' => 'Display Name Is Not Authority'],
            'expires_at' => now()->addYear()->toIso8601String(),
            'grace_expires_at' => null,
            'entitlements' => array_map(
                fn (string $module): array => ['key' => $module, 'name' => $module, 'enabled' => true, 'limit' => null],
                $legacyModules,
            ),
            'entitlement_revision' => 8,
            'entitlement_digest' => 'test',
            'entitlement_framework' => [
                'schema_version' => 1,
                'source' => 'direct',
                'catalog_revision' => 3,
                'source_revision' => 12,
                'grants' => (object) $grants,
            ],
        ];
        $payloadJson = json_encode($claims, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $this->cache->storeInstallationSnapshot([
            'payload_json' => $payloadJson,
            'signature' => base64_encode(sodium_crypto_sign_detached($payloadJson, $secretKey)),
            'algorithm' => 'ed25519',
            'key_id' => 'mediflow-framework-test',
        ], $installationUuid.'.framework-test-secret');
    }
}
