<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureInstallationModules;
use App\Livewire\Admin\InstallationSetup;
use App\Livewire\Admin\InsightSharing;
use App\Models\ClientLicense;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\InstallationAdministrator;
use App\Services\InsightCategoryCatalog;
use App\Services\InsightReleaseReadiness;
use App\Services\LicenseService;
use App\Services\SystemBranding;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Livewire\Mechanisms\PersistentMiddleware\PersistentMiddleware;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class InstallationSetupTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
            'session.driver' => 'array',
            'central_licensing.enabled' => false,
        ]);
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->boolean('is_installation_admin')->default(false);
            $table->timestamp('email_verified_at')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });
        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('name');
        });
        Schema::create('role_user', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('role_id');
        });
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('action');
            $table->string('model_type')->nullable();
            $table->unsignedBigInteger('model_id')->nullable();
            foreach (['before', 'after', 'meta', 'ip', 'user_agent'] as $column) {
                $table->text($column)->nullable();
            }
            $table->timestamps();
        });
        (require database_path('migrations/2026_09_28_000001_create_system_settings_table.php'))->up();
        (require database_path('migrations/2026_09_30_000002_link_installation_administrator.php'))->up();
        (require database_path('migrations/2026_09_30_000003_add_welcome_appearance_to_system_settings.php'))->up();
        DB::table('roles')->insert(['id' => 1, 'name' => 'administrator']);
        (require database_path('migrations/2026_08_27_000002_create_client_licenses_table.php'))->up();
        (require database_path('migrations/2026_08_27_000003_create_client_enabled_modules_table.php'))->up();
        (require database_path('migrations/2026_09_29_000001_add_selected_packages_to_client_licenses.php'))->up();
    }

    private function admin(bool $installation = true): User
    {
        return User::withoutEvents(function () use ($installation) {
            $user = new User(['name' => 'Admin', 'email' => 'admin@example.test', 'password' => 'test-password']);
            $user->is_installation_admin = $installation;
            $user->save();

            return $user;
        });
    }

    private function license(array $attributes = []): ClientLicense
    {
        return ClientLicense::create(array_merge(['client_name' => 'Test', 'plan' => 'pharmacy', 'is_active' => true], $attributes));
    }

    public function test_ordinary_administrator_cannot_open_setup(): void
    {
        $user = $this->admin(false);
        $user->roles()->attach(1);
        $this->actingAs($user)->get('/admin/installation')->assertForbidden();
        Livewire::actingAs($user)->test(InstallationSetup::class)->assertForbidden();
        $this->actingAs($user)->get('/admin/insight-sharing')->assertForbidden();
        Livewire::actingAs($user)->test(InsightSharing::class)->assertForbidden();
    }

    public function test_insight_catalog_filters_only_by_enabled_modules(): void
    {
        $catalog = app(InsightCategoryCatalog::class);
        $keys = fn (array $modules): array => array_column($catalog->forModules($modules), 'key');
        $pharmacy = $keys(['access_control', 'billing', 'pharmacy', 'finance']);
        $this->assertContains('medicine_stock', $pharmacy);
        $this->assertContains('expenses', $pharmacy);
        $this->assertNotContains('patients', $pharmacy);
        $this->assertNotContains('investigations', $pharmacy);

        $clinic = $keys(['patient_records', 'clinical_care', 'doctor', 'billing']);
        $this->assertContains('patients', $clinic);
        $this->assertContains('visits', $clinic);
        $this->assertContains('prescriptions', $clinic);
        $this->assertContains('labour_services', $clinic);
        $this->assertContains('surgery', $clinic);
        $this->assertNotContains('medicine_stock', $clinic);
        $this->assertNotContains('labours', $clinic);
        $this->assertNotContains('deliveries', $clinic);

        $diagnostics = $keys(['patient_records', 'laboratory', 'radiology']);
        $this->assertSame(1, count(array_filter($diagnostics, fn ($key) => $key === 'investigations')));
        $this->assertNotContains('prescriptions', $diagnostics);
        $this->assertNotContains('labour_services', $diagnostics);
        $this->assertNotContains('surgery', $diagnostics);
        $maternity = $keys(['maternity']);
        $this->assertContains('labour_episodes', $maternity);
        $this->assertContains('deliveries', $maternity);
        $this->assertContains('postnatal_examinations', $maternity);
        $hospital = $keys(array_keys(config('mediflow_modules.features')));
        $this->assertContains('newborns', $hospital);
        $this->assertContains('consumable_stock', $hospital);
        $this->assertCount(count(array_unique($hospital)), $hospital);
        $this->assertSame([], $keys([]));
        $descriptions = array_column($catalog->forModules(array_keys(config('mediflow_modules.features'))), 'description', 'key');
        $this->assertStringContainsString('drug charts', $descriptions['care']);
        $this->assertStringContainsString('all labour services', $descriptions['labour_services']);
        $this->assertStringContainsString('services in the surgery category', $descriptions['surgery']);
        $this->assertStringContainsString('used and remaining', $descriptions['consumable_stock']);
    }

    public function test_insight_sharing_preview_requires_verified_license_and_never_enables_sharing(): void
    {
        $admin = $this->admin();
        Livewire::actingAs($admin)->test(InsightSharing::class)
            ->assertSee('A usable verified KernelBridge license is required')
            ->assertDontSee('Enable synchronization for Patients');

        $this->mock(InsightCategoryCatalog::class)
            ->shouldReceive('availableForInstallation')->andReturn([
                ['key' => 'patients', 'label' => 'Patients', 'description' => 'New patient registrations by registration date.'],
            ]);
        Livewire::actingAs($admin)->test(InsightSharing::class)
            ->assertSee('No categories can be enabled')
            ->assertSee('Insight synchronization')
            ->assertSee('Release status: Pending privacy review')
            ->assertSee('The release has not been reviewed or approved.')
            ->assertSee('A privacy specialist must review the release design and set the lifetime privacy budget.')
            ->assertSee('Hospital consent, withdrawal and Platform deletion are not implemented.')
            ->assertSee('Enable synchronization for Patients')
            ->assertSee('Not available yet; synchronization is off.')
            ->assertSeeHtml('type="checkbox" disabled');
        $screen = Livewire::actingAs($admin)->test(InsightSharing::class);
        $admin->is_installation_admin = false;
        $admin->saveQuietly();
        $screen->call('$refresh')->assertForbidden();
    }

    public function test_insight_release_remains_pending_review_even_if_sync_flags_are_enabled(): void
    {
        config([
            'operational_sync.management_enabled' => true,
            'operational_sync.connector_enabled' => true,
            'operational_summary_sync.enabled' => true,
        ]);
        $readiness = app(InsightReleaseReadiness::class)->status();
        $this->assertSame('pending_privacy_review', $readiness['status']);
        $this->assertFalse($readiness['can_release']);
        $this->assertCount(4, $readiness['blockers']);

        Livewire::actingAs($this->admin())->test(InsightSharing::class)
            ->assertSee('Release status: Pending privacy review')
            ->assertSee('Aggregate releases and corrections are disabled.')
            ->assertSee('Aggregate delivery, Platform ingestion and reporting are not implemented.');
    }

    public function test_daily_insight_preview_is_local_suppressed_and_limited_to_licensed_categories(): void
    {
        config(['app.timezone' => 'UTC']);
        $this->travelTo(now()->setDate(2026, 11, 12)->startOfDay()->addHours(12));
        Schema::create('patients', function (Blueprint $table): void {
            $table->id();
            $table->dateTime('registration_date');
            $table->softDeletes();
        });
        foreach (range(1, 4) as $id) {
            DB::table('patients')->insert(['id' => $id, 'registration_date' => '2026-11-11 12:00:00']);
        }
        $categories = [
            ['key' => 'patients', 'label' => 'Patients', 'description' => 'New registrations.'],
        ];
        $this->mock(InsightCategoryCatalog::class)->shouldReceive('availableForInstallation')
            ->andReturnUsing(function () use (&$categories) { return $categories; });
        $screen = Livewire::actingAs($this->admin())->test(InsightSharing::class)
            ->assertSee('Nothing is saved or sent to the Platform')
            ->set('previewCategory', 'patients')
            ->call('preview')
            ->assertHasNoErrors()
            ->assertSee('Suppressed (fewer than five distinct patients).')
            ->assertSet('previewResult.value', null);

        DB::table('patients')->insert(['id' => 5, 'registration_date' => '2026-11-11 12:00:00']);
        $screen->call('preview')->assertSet('previewResult.value', 5);
        $categories = [];
        $screen->call('$refresh')->assertSet('previewResult', null);
        $categories = [['key' => 'patients', 'label' => 'Patients', 'description' => 'New registrations.']];
        $screen->set('previewDate', '2026-11-12')->assertSet('previewResult', null)
            ->call('preview')->assertHasErrors(['previewDate']);
        $screen->set('previewDate', '2026-11-11')->set('previewCategory', 'care')
            ->call('preview')->assertHasErrors(['previewCategory'])->assertSet('previewResult', null);
    }

    public function test_daily_insight_preview_rechecks_license_before_calculation(): void
    {
        $this->mock(InsightCategoryCatalog::class)->shouldReceive('availableForInstallation')->andReturn(null);
        Livewire::actingAs($this->admin())->test(InsightSharing::class)
            ->set('previewCategory', 'patients')
            ->call('preview')
            ->assertHasErrors(['previewCategory'])
            ->assertSet('previewResult', null);
    }

    public function test_maternity_preview_is_shown_only_for_the_enabled_module_and_stays_local(): void
    {
        config(['app.timezone' => 'UTC']);
        $this->travelTo(now()->setDate(2026, 11, 12)->startOfDay()->addHours(12));
        Schema::create('patients', function (Blueprint $table): void {
            $table->id();
            $table->softDeletes();
        });
        Schema::create('deliveries', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('patient_id');
            $table->dateTime('delivery_date_time');
            $table->softDeletes();
        });
        foreach (range(1, 5) as $id) {
            DB::table('patients')->insert(['id' => $id]);
            DB::table('deliveries')->insert(['patient_id' => $id, 'delivery_date_time' => '2026-11-11 12:00:00']);
        }
        DB::table('deliveries')->insert(['patient_id' => 1, 'delivery_date_time' => '2026-11-11 13:00:00']);
        $categories = app(InsightCategoryCatalog::class)->forModules(['maternity']);
        $this->mock(InsightCategoryCatalog::class)->shouldReceive('availableForInstallation')
            ->andReturnUsing(function () use (&$categories) { return $categories; });
        $screen = Livewire::actingAs($this->admin())->test(InsightSharing::class)
            ->assertSee('Labour episodes are separate from services named labor')
            ->set('previewCategory', 'deliveries')
            ->call('preview')->assertHasNoErrors()->assertSet('previewResult.value', 6)
            ->assertSee('No categories can be enabled');
        $screen->set('previewDistinctPatients', true)->assertSet('previewResult', null)
            ->call('preview')->assertHasNoErrors()->assertSet('previewResult.value', 5)
            ->assertSet('previewResult.unit', 'patients')
            ->assertSee('not a differentially private release');
        $categories = [];
        $screen->call('$refresh')->assertSet('previewResult', null)
            ->set('previewCategory', 'deliveries')->call('preview')
            ->assertHasErrors(['previewCategory']);
    }

    public function test_operational_retry_screen_is_gated_and_audited(): void
    {
        (require database_path('migrations/2026_11_11_000010_create_operational_sync_outbox.php'))->up();
        $eventId = (string) \Illuminate\Support\Str::uuid();
        DB::table('operational_sync_outbox')->insert([
            'id' => $eventId, 'sequence' => 1, 'payload_json' => '{"secret":"never display"}',
            'payload_sha256' => str_repeat('a', 64), 'occurred_at' => now(), 'status' => 'failed',
            'attempts' => 10, 'last_error_code' => 'remote_rejected', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $admin = $this->admin();
        Livewire::actingAs($admin)->test(InstallationSetup::class)
            ->assertDontSee('Operational sync')->call('retryOperationalEvent', $eventId)->assertNotFound();
        config(['operational_sync.management_enabled' => true]);
        Livewire::actingAs($admin)->test(InstallationSetup::class)
            ->assertSeeHtml('id="operational-sync"')->assertSee('Platform under Operational sync')
            ->assertSee('Failed: 1')->assertSee('remote_rejected')->assertDontSee('never display')
            ->call('retryOperationalEvent', $eventId)->assertHasNoErrors()->assertSee('Retry requested')
            ->assertSee('Failed: 0');
        $this->assertDatabaseHas('operational_sync_outbox', ['id' => $eventId, 'status' => 'pending', 'attempts' => 0]);
        $this->assertDatabaseHas('audit_logs', ['actor_id' => $admin->id, 'action' => 'operational_sync.retry_requested']);
    }

    public function test_operational_enrollment_requires_admin_and_does_not_flash_secret_on_invalid_input(): void
    {
        $admin = $this->admin();
        $url = route('admin.installation.operational-sync.enroll');
        $this->actingAs($admin)->post($url, ['sync_code' => 'sensitive-invalid-value'])->assertNotFound();
        config(['operational_sync.management_enabled' => true]);
        $this->actingAs($admin)->post($url, ['sync_code' => 'sensitive-invalid-value'])
            ->assertRedirect(route('admin.installation'))->assertSessionHasErrors('sync_code');
        $this->assertNull(session()->getOldInput('sync_code'));
        $admin->is_installation_admin = false;
        $admin->saveQuietly();
        $this->actingAs($admin)->post($url, ['sync_code' => str_repeat('c', 43)])->assertForbidden();
    }

    public function test_super_admin_can_save_a_package_and_explicit_module_selection(): void
    {
        Livewire::actingAs($this->admin())->test(InstallationSetup::class)
            ->set('clientName', 'Test Pharmacy')->set('plan', 'pharmacy')
            ->set('modules', ['core', 'access_control', 'pharmacy', 'billing'])
            ->call('save')->assertHasNoErrors()->assertSee('Installation saved');
        $service = app(LicenseService::class);
        $this->assertTrue($service->moduleEnabled('pharmacy'));
        $this->assertFalse($service->moduleEnabled('finance'));
        $this->assertFalse($service->moduleEnabled('maternity'));
        $this->assertDatabaseHas('audit_logs', ['action' => 'installation.configured']);
        $this->assertDatabaseHas('client_enabled_modules', ['module_name' => 'finance', 'is_enabled' => false]);
    }

    public function test_dependencies_and_unknown_modules_are_rejected(): void
    {
        Livewire::actingAs($this->admin())->test(InstallationSetup::class)
            ->set('modules', ['core', 'access_control', 'doctor'])->call('save')->assertHasErrors('modules')
            ->set('modules', ['core', 'unknown'])->call('save')->assertHasErrors('modules.1');
        $this->assertDatabaseCount('client_licenses', 0);
    }

    public function test_branch_management_requires_enterprise(): void
    {
        Livewire::actingAs($this->admin())->test(InstallationSetup::class)
            ->set('plan', 'hospital')->set('modules', ['core', 'access_control', 'branch_management'])
            ->call('save')->assertHasErrors('modules');
    }

    public function test_kernelbridge_managed_installation_rejects_plan_and_package_edits(): void
    {
        config(['central_licensing.enabled' => true]);
        $this->expectException(HttpException::class);

        (new InstallationSetup)->updatedPlan();
    }

    public function test_kernelbridge_managed_installation_rejects_package_selection_edits(): void
    {
        config(['central_licensing.enabled' => true]);
        $this->expectException(HttpException::class);

        (new InstallationSetup)->updatedSelectedPackages();
    }

    public function test_expired_inactive_and_future_licenses_never_restore_hospital_access(): void
    {
        $license = $this->license(['plan' => 'hospital', 'expires_at' => now()->subDay()]);
        $service = app(LicenseService::class);
        $this->assertFalse($service->moduleEnabled('pharmacy'));
        $license->update(['expires_at' => null, 'is_active' => false]);
        $this->assertFalse($service->moduleEnabled('pharmacy'));
        $license->update(['is_active' => true, 'starts_at' => now()->addDay()]);
        $this->assertFalse($service->moduleEnabled('pharmacy'));
        $this->assertTrue($service->moduleEnabled('core'));
    }

    public function test_explicit_all_disabled_does_not_restore_plan(): void
    {
        $this->license()->enabledModules()->create(['module_name' => 'pharmacy', 'is_enabled' => false]);
        $this->assertFalse(app(LicenseService::class)->moduleEnabled('pharmacy'));
    }

    public function test_latest_inactive_license_does_not_reactivate_an_older_license(): void
    {
        $this->license(['plan' => 'enterprise_hospital']);
        $this->license(['is_active' => false]);
        $this->assertFalse(app(LicenseService::class)->moduleEnabled('maternity'));
    }

    public function test_direct_get_and_post_are_blocked_even_for_super_admin(): void
    {
        $this->license();
        Route::match(['GET', 'POST'], '/installation-test-lab', fn () => 'allowed')
            ->middleware(EnsureInstallationModules::class)->name('lab.installation-test');
        $this->actingAs($this->admin())->get('/installation-test-lab')->assertForbidden();
        $this->post('/installation-test-lab')->assertForbidden();
    }

    public function test_routes_and_livewire_updates_have_package_middleware(): void
    {
        foreach (['admin.installation', 'lab.requests.index', 'pharmacy.medicines.index', 'patient.prescription.create'] as $name) {
            $this->assertContains(EnsureInstallationModules::class, Route::getRoutes()->getByName($name)->gatherMiddleware());
        }
        $this->assertContains(EnsureInstallationModules::class, app(PersistentMiddleware::class)->getPersistentMiddleware());
    }

    public function test_revoked_super_admin_cannot_save_an_open_screen(): void
    {
        $user = $this->admin();
        $screen = Livewire::actingAs($user)->test(InstallationSetup::class);
        $user->is_installation_admin = false;
        $user->saveQuietly();
        $screen->call('save')->assertForbidden();
        $this->assertDatabaseCount('client_licenses', 0);
    }

    public function test_report_datasets_respect_package(): void
    {
        $license = $this->license();
        $service = app(LicenseService::class);
        $this->assertFalse($service->reportTableEnabled('deliveries'));
        $this->assertFalse($service->reportTableEnabled('vital_signs'));
        $license->update(['plan' => 'maternity_clinic']);
        $this->assertTrue($service->reportTableEnabled('deliveries'));
        $this->assertFalse($service->reportTableEnabled('investigation_requests'));
    }

    public function test_branding_saves_separately_and_escapes_public_text(): void
    {
        $this->license();
        Livewire::actingAs($this->admin())->test(InstallationSetup::class)
            ->set('brandName', 'Test Pharmacy')->set('welcomeHeading', '<script>alert(1)</script>')
            ->set('welcomeStatement', 'Welcome to our team')->set('welcomeTemplate', 'pharmacy')
            ->call('saveBranding')->assertHasNoErrors()->assertSee('System branding saved');
        $this->assertDatabaseHas('system_settings', ['id' => 1, 'brand_name' => 'Test Pharmacy']);
        $this->assertSame('pharmacy', app(LicenseService::class)->currentPlan());
        $this->assertDatabaseHas('audit_logs', ['action' => 'system.branding_updated']);
        auth()->logout();
        $this->get('/login')->assertOk()->assertSee('Login to Test Pharmacy');
        $this->get('/')->assertOk()->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false)
            ->assertDontSee('<script>alert(1)</script>', false);
    }

    public function test_branding_logo_upload_delivery_replacement_and_removal(): void
    {
        Storage::fake('local');
        $screen = Livewire::actingAs($this->admin())->test(InstallationSetup::class)
            ->set('logo', UploadedFile::fake()->image('logo.png', 120, 120))
            ->call('saveBranding')->assertHasNoErrors();
        $old = SystemSetting::find(1)->logo_path;
        Storage::disk('local')->assertExists($old);
        $this->get('/branding/logo')->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $screen->set('logo', UploadedFile::fake()->image('new.png', 120, 120))
            ->call('saveBranding')->assertHasNoErrors();
        Storage::disk('local')->assertMissing($old);
        $screen->set('removeLogo', true)->call('saveBranding')->assertHasNoErrors();
        $this->assertNull(SystemSetting::find(1)->logo_path);
        $this->get('/branding/logo')->assertNotFound();
    }

    public function test_invalid_branding_template_and_logo_are_rejected(): void
    {
        Livewire::actingAs($this->admin())->test(InstallationSetup::class)
            ->set('welcomeTemplate', '../../private')->call('saveBranding')->assertHasErrors('welcomeTemplate')
            ->set('logo', UploadedFile::fake()->create('logo.svg', 1, 'image/svg+xml'))
            ->assertHasErrors('logo');
        $this->assertDatabaseCount('system_settings', 0);
    }

    public function test_automatic_template_tracks_package_and_all_six_render(): void
    {
        $license = $this->license();
        $branding = app(SystemBranding::class);
        foreach (array_keys(config('welcome_templates')) as $key) {
            $license->update(['plan' => $key]);
            $this->assertSame($key, $branding->template()['key']);
            SystemSetting::updateOrCreate(['id' => 1], ['brand_name' => 'Test Brand', 'welcome_template' => 'auto']);
            $this->get('/')->assertOk()->assertSee('data-template="'.$key.'"', false);
        }
        $this->assertSame('pharmacy', $branding->template('pharmacy')['key']);
    }

    public function test_seven_presets_and_shared_routes_are_consistent(): void
    {
        $service = app(LicenseService::class);
        $this->assertCount(7, config('mediflow_modules.plans'));
        foreach (array_keys(config('mediflow_modules.plans')) as $plan) {
            $modules = $service->planModules($plan);
            foreach ($modules as $module) {
                foreach (config("mediflow_modules.dependencies.$module", []) as $dependency) {
                    $this->assertContains($dependency, $modules, "$plan: $module requires $dependency");
                }
            }
        }
        $this->assertNotContains('branch_management', $service->planModules('hospital'));
        $this->assertContains('branch_management', $service->planModules('enterprise_hospital'));
        $this->license(['plan' => 'diagnostic_center']);
        $this->assertTrue($service->routeEnabled('patient.investigation.create'));
        $this->assertFalse($service->routeEnabled('patient.prescription.create'));
        $this->assertFalse($service->routeEnabled('patient.drugchart.record'));
        $this->assertFalse($service->routeEnabled(null, 'api/v1/sync/records'));
    }

    public function test_specialist_companions_preserve_explicit_module_choices(): void
    {
        Livewire::actingAs($this->admin())->test(InstallationSetup::class)->set('plan', 'specialist')
            ->set('selectedPackages', ['specialist', 'pharmacy'])->call('save')->assertHasNoErrors();
        $license = app(LicenseService::class);
        $this->assertSame(['specialist', 'pharmacy'], $license->selectedPackages());
        $this->assertTrue($license->moduleEnabled('specialist'));
        $this->assertTrue($license->moduleEnabled('pharmacy'));
        $this->assertFalse($license->routeEnabled('patient.admission.create'));
        $this->assertTrue($license->routeEnabled('specialist.index'));
    }

    public function test_enterprise_companion_keeps_primary_branding(): void
    {
        Livewire::actingAs($this->admin())->test(InstallationSetup::class)->set('plan', 'specialist')
            ->set('selectedPackages', ['specialist', 'enterprise_hospital'])->call('save')->assertHasNoErrors();
        $this->assertSame('specialist', app(LicenseService::class)->currentPlan());
        $this->assertTrue(app(LicenseService::class)->moduleEnabled('branch_management'));
    }

    public function test_explicit_enterprise_does_not_silently_enable_specialist(): void
    {
        $license = $this->license(['plan' => 'enterprise_hospital']);
        $license->enabledModules()->create(['module_name' => 'core', 'is_enabled' => true]);
        $this->assertFalse(app(LicenseService::class)->moduleEnabled('specialist'));
    }

    public function test_explicit_registration_and_password_preservation(): void
    {
        $screen = Livewire::actingAs($this->admin())->test(InstallationSetup::class)
            ->set('brandName', 'Care Network')->call('saveBranding')->assertHasNoErrors();
        $this->assertDatabaseCount('users', 1);
        $screen->set('adminName', 'Practice Administrator')->set('adminEmail', 'owner@example.test')
            ->set('adminPassword', 'chosen-password')->set('adminPassword_confirmation', 'chosen-password')
            ->call('saveAdministrator')->assertHasNoErrors()->assertSee('Administrator registered.')
            ->assertSet('adminPassword', '')->assertSet('adminPassword_confirmation', '');
        $admin = User::where('email', 'owner@example.test')->firstOrFail();
        $this->assertTrue($admin->hasRole('administrator'));
        $this->assertFalse($admin->is_installation_admin);
        $this->assertTrue(Hash::check('chosen-password', $admin->password));
        $screen->set('brandName', 'Another Brand')->call('saveBranding')->assertHasNoErrors();
        $this->assertSame('owner@example.test', $admin->fresh()->email);
        $screen->set('adminEmail', 'updated@example.test')->call('saveAdministrator')->assertHasNoErrors();
        $this->assertTrue(Hash::check('chosen-password', $admin->fresh()->password));
        $this->assertSame('updated@example.test', $admin->fresh()->email);
        $screen->set('adminPassword', 'replacement-password')->set('adminPassword_confirmation', 'replacement-password')->call('saveAdministrator')->assertHasNoErrors()->assertSet('adminPassword', '');
        $this->assertTrue(Hash::check('replacement-password', $admin->fresh()->password));
        $this->assertDatabaseCount('users', 2);
    }

    public function test_registration_validates_password_confirmation_and_email_collision(): void
    {
        $owner = $this->admin();
        SystemSetting::create(['id' => 1, 'brand_name' => 'Care Network']);
        $screen = Livewire::actingAs($owner)->test(InstallationSetup::class)
            ->set('adminName', 'Administrator')->set('adminEmail', $owner->email)
            ->set('adminPassword', 'chosen-password')->set('adminPassword_confirmation', 'different-password')
            ->call('saveAdministrator')->assertHasErrors(['adminEmail', 'adminPassword'])->assertSet('adminPassword', '');
        $this->assertDatabaseCount('users', 1);
        $screen->set('adminEmail', 'new@example.test')->set('adminPassword', 'admin')->set('adminPassword_confirmation', 'admin')
            ->call('saveAdministrator')->assertHasErrors('adminPassword');
    }

    public function test_branding_no_longer_requires_domain_characters_or_creates_users(): void
    {
        Livewire::actingAs($this->admin())->test(InstallationSetup::class)->set('brandName', '!!!')->call('saveBranding')->assertHasNoErrors();
        $this->assertDatabaseCount('users', 1);
    }

    public function test_registration_requires_saved_branding(): void
    {
        Livewire::actingAs($this->admin())->test(InstallationSetup::class)
            ->set('adminName', 'Administrator')->set('adminEmail', 'new@example.test')
            ->set('adminPassword', 'chosen-password')->set('adminPassword_confirmation', 'chosen-password')
            ->call('saveAdministrator')->assertHasErrors('adminEmail');
        $this->assertDatabaseCount('users', 1);
    }

    public function test_non_super_admin_cannot_register_an_administrator_through_service(): void
    {
        $this->actingAs($this->admin(false));
        $this->expectException(HttpException::class);
        app(InstallationAdministrator::class)->save(['adminName' => 'Unauthorized', 'adminEmail' => 'new@example.test', 'adminPassword' => 'chosen-password', 'adminPassword_confirmation' => 'chosen-password']);
    }

    public function test_welcome_artwork_and_colors_can_be_saved_replaced_and_removed(): void
    {
        Storage::fake('local');
        $component = Livewire::actingAs($this->admin())->test(InstallationSetup::class)
            ->set('brandName', 'Artwork Clinic')
            ->set('welcomeColors.accent', '#123456')->set('welcomeColors.background', '#fafafa')
            ->set('artwork.hero', UploadedFile::fake()->image('hero.png', 100, 100))
            ->set('artwork.background', UploadedFile::fake()->image('background.jpg', 100, 100))
            ->set('artwork.supporting', UploadedFile::fake()->image('support.png', 100, 100))
            ->set('artworkAlt.hero', 'Our care team')->call('saveBranding')->assertHasNoErrors();
        $appearance = SystemSetting::find(1)->welcome_appearance;
        $old = $appearance['images']['hero']['path'];
        Storage::disk('local')->assertExists($old);
        $this->assertSame('#123456', $appearance['colors']['accent']);
        $this->get('/')->assertOk()->assertSee('Our care team')->assertSee('--brand-accent: #123456', false);
        $this->get(app(SystemBranding::class)->imageUrl('hero'))->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->get('/branding/image/unknown')->assertNotFound();
        $component->set('artwork.hero', UploadedFile::fake()->image('replacement.jpg'))
            ->call('saveBranding')->assertHasNoErrors();
        Storage::disk('local')->assertMissing($old);
        $replacement = SystemSetting::find(1)->welcome_appearance['images']['hero']['path'];
        $component->set('removeArtwork.hero', true)->set('welcomeColors.accent', '')
            ->call('saveBranding')->assertHasNoErrors();
        Storage::disk('local')->assertMissing($replacement);
        $this->get('/branding/image/hero')->assertNotFound();
        $this->assertNull(app(SystemBranding::class)->imageUrl('hero'));
        $this->assertSame('', SystemSetting::find(1)->welcome_appearance['colors']['accent']);
    }

    public function test_invalid_appearance_input_is_rejected_and_preview_colors_are_safe(): void
    {
        Storage::fake('local');
        Livewire::actingAs($this->admin())->test(InstallationSetup::class)
            ->set('welcomeColors.accent', 'red;display:none')->call('saveBranding')
            ->assertHasErrors(['welcomeColors.accent'])
            ->set('welcomeColors.accent', '#123456')
            ->set('artwork.hero', UploadedFile::fake()->create('script.svg', 10, 'image/svg+xml'))
            ->call('saveBranding')->assertHasErrors(['artwork.hero']);
        $template = app(SystemBranding::class)->template('pharmacy');
        $this->assertSame($template['accent'], app(SystemBranding::class)->colors($template, ['accent' => 'red;display:none'])['accent']);
        $this->assertNull(SystemSetting::find(1));
    }
}
