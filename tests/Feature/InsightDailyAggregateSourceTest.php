<?php

namespace Tests\Feature;

use App\Services\InsightDailyAggregateSource;
use App\Services\InsightCategoryCatalog;
use App\Services\InsightLocalPreview;
use App\Services\InsightMetricCatalog;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;

class InsightDailyAggregateSourceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:', 'app.timezone' => 'UTC']);
        Schema::create('patients', function (Blueprint $table): void {
            $table->id();
            $table->dateTime('registration_date');
            $table->softDeletes();
        });
        Schema::create('patient_visits', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('patient_id');
            $table->dateTime('visit_date');
            $table->softDeletes();
        });
        Schema::create('prescriptions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('patient_visit_id');
        });
        Schema::create('prescription_items', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('prescription_id');
        });
        Schema::create('drug_charts', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('prescription_item_id');
            $table->timestamp('created_at');
        });
        Schema::create('patient_admissions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('patient_id');
            $table->dateTime('admission_date');
            $table->softDeletes();
        });
        foreach (['labours' => 'labour_onset_time', 'deliveries' => 'delivery_date_time', 'postnatal_examinations' => 'examination_date_time'] as $tableName => $dateColumn) {
            Schema::create($tableName, function (Blueprint $table) use ($dateColumn): void {
                $table->id();
                $table->unsignedBigInteger('patient_id');
                $table->dateTime($dateColumn)->nullable();
                $table->softDeletes();
            });
        }
        $this->travelTo(now()->setDate(2026, 11, 12)->startOfDay()->addHours(12));
    }

    public function test_new_patients_are_suppressed_below_five_and_no_identifiers_are_returned(): void
    {
        $source = app(InsightDailyAggregateSource::class);
        foreach (range(1, 4) as $id) {
            DB::table('patients')->insert(['id' => $id, 'registration_date' => '2026-11-11 12:00:00']);
        }
        $this->assertSame(['category' => 'patients', 'date' => '2026-11-11', 'status' => 'suppressed', 'value' => null], $source->calculate('patients', '2026-11-11'));
        DB::table('patients')->insert(['id' => 5, 'registration_date' => '2026-11-11 23:59:59']);
        DB::table('patients')->insert(['id' => 6, 'registration_date' => '2026-11-12 00:00:00']);
        $this->assertSame(['category' => 'patients', 'date' => '2026-11-11', 'status' => 'available', 'value' => 5], $source->calculate('patients', '2026-11-11'));
        DB::table('patients')->where('id', 5)->update(['deleted_at' => now()]);
        $this->assertSame('suppressed', $source->calculate('patients', '2026-11-11')['status']);
    }

    public function test_visits_require_five_distinct_patients_not_five_visits(): void
    {
        foreach (range(1, 5) as $id) {
            DB::table('patients')->insert(['id' => $id, 'registration_date' => '2026-01-01 00:00:00']);
        }
        foreach ([1, 1, 1, 2, 3, 4] as $id) {
            DB::table('patient_visits')->insert(['patient_id' => $id, 'visit_date' => '2026-11-11 10:00:00']);
        }
        $source = app(InsightDailyAggregateSource::class);
        $this->assertNull($source->calculate('visits', '2026-11-11')['value']);
        $this->assertNull($source->calculateDistinctPatients('visits', '2026-11-11')['value']);
        DB::table('patient_visits')->insert(['patient_id' => 5, 'visit_date' => '2026-11-11 11:00:00']);
        $this->assertSame(['category' => 'visits', 'date' => '2026-11-11', 'status' => 'available', 'value' => 7], $source->calculate('visits', '2026-11-11'));
        $this->assertSame(['category' => 'visits', 'date' => '2026-11-11', 'status' => 'available', 'value' => 5], $source->calculateDistinctPatients('visits', '2026-11-11'));
        DB::table('patients')->where('id', 5)->update(['deleted_at' => now()]);
        $this->assertSame('suppressed', $source->calculate('visits', '2026-11-11')['status']);
    }

    public function test_current_day_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        app(InsightDailyAggregateSource::class)->calculate('patients', '2026-11-12');
    }

    public function test_drug_charts_are_counted_by_creation_date_and_distinct_patients(): void
    {
        foreach (range(1, 5) as $id) {
            DB::table('patients')->insert(['id' => $id, 'registration_date' => '2026-01-01 00:00:00']);
            DB::table('patient_visits')->insert(['id' => $id, 'patient_id' => $id, 'visit_date' => '2026-01-01 10:00:00']);
            DB::table('prescriptions')->insert(['id' => $id, 'patient_visit_id' => $id]);
            DB::table('prescription_items')->insert(['id' => $id, 'prescription_id' => $id]);
        }
        foreach ([1, 1, 2, 3, 4] as $id) {
            DB::table('drug_charts')->insert(['prescription_item_id' => $id, 'created_at' => '2026-11-11 12:00:00']);
        }
        $source = app(InsightDailyAggregateSource::class);
        $this->assertSame('suppressed', $source->calculate('care', '2026-11-11')['status']);
        DB::table('drug_charts')->insert(['prescription_item_id' => 5, 'created_at' => '2026-11-11 23:59:59']);
        DB::table('drug_charts')->insert(['prescription_item_id' => 5, 'created_at' => '2026-11-12 00:00:00']);
        $this->assertSame(['category' => 'care', 'date' => '2026-11-11', 'status' => 'available', 'value' => 6], $source->calculate('care', '2026-11-11'));
        $this->assertSame(5, $source->calculateDistinctPatients('care', '2026-11-11')['value']);
        DB::table('patient_visits')->where('id', 5)->update(['deleted_at' => now()]);
        $this->assertSame('suppressed', $source->calculate('care', '2026-11-11')['status']);
    }

    public function test_unknown_category_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        app(InsightDailyAggregateSource::class)->calculate('prescriptions', '2026-11-11');
    }

    public function test_admissions_use_patient_admission_date_and_distinct_non_deleted_patients(): void
    {
        foreach (range(1, 5) as $id) {
            DB::table('patients')->insert(['id' => $id, 'registration_date' => '2026-01-01 00:00:00']);
            DB::table('patient_admissions')->insert(['patient_id' => $id, 'admission_date' => '2026-11-11 12:00:00']);
        }
        DB::table('patient_admissions')->insert(['patient_id' => 1, 'admission_date' => '2026-11-11 23:59:59']);
        DB::table('patient_admissions')->insert(['patient_id' => 1, 'admission_date' => '2026-11-12 00:00:00']);
        $source = app(InsightDailyAggregateSource::class);
        $this->assertSame(['category' => 'admissions', 'date' => '2026-11-11', 'status' => 'available', 'value' => 6], $source->calculate('admissions', '2026-11-11'));
        $this->assertSame(5, $source->calculateDistinctPatients('admissions', '2026-11-11')['value']);
        DB::table('patient_admissions')->where('patient_id', 5)->update(['deleted_at' => now()]);
        $this->assertSame('suppressed', $source->calculate('admissions', '2026-11-11')['status']);
        DB::table('patient_admissions')->where('patient_id', 5)->update(['deleted_at' => null]);
        DB::table('patients')->where('id', 5)->update(['deleted_at' => now()]);
        $this->assertSame('suppressed', $source->calculate('admissions', '2026-11-11')['status']);
    }

    public function test_maternity_event_counts_use_event_dates_and_suppress_small_groups(): void
    {
        foreach (range(1, 5) as $id) {
            DB::table('patients')->insert(['id' => $id, 'registration_date' => '2026-01-01 00:00:00']);
        }
        $cases = [
            'labour_episodes' => ['labours', 'labour_onset_time'],
            'deliveries' => ['deliveries', 'delivery_date_time'],
            'postnatal_examinations' => ['postnatal_examinations', 'examination_date_time'],
        ];
        $source = app(InsightDailyAggregateSource::class);
        foreach ($cases as $category => [$table, $dateColumn]) {
            $this->assertSame('suppressed', $source->calculate($category, '2026-11-11')['status']);
            foreach ([1, 1, 2, 3, 4] as $patientId) {
                DB::table($table)->insert(['patient_id' => $patientId, $dateColumn => '2026-11-11 00:00:00']);
            }
            $this->assertNull($source->calculate($category, '2026-11-11')['value']);
            DB::table($table)->insert(['patient_id' => 5, $dateColumn => '2026-11-11 23:59:59']);
            DB::table($table)->insert(['patient_id' => 5, $dateColumn => '2026-11-12 00:00:00']);
            DB::table($table)->insert(['patient_id' => 5, $dateColumn => null]);
            $this->assertSame(['category' => $category, 'date' => '2026-11-11', 'status' => 'available', 'value' => 6], $source->calculate($category, '2026-11-11'));
            $this->assertSame(5, $source->calculateDistinctPatients($category, '2026-11-11')['value']);
            DB::table($table)->where('patient_id', 5)->update(['deleted_at' => now()]);
            $this->assertSame('suppressed', $source->calculate($category, '2026-11-11')['status']);
            DB::table($table)->where('patient_id', 5)->update(['deleted_at' => null]);
            DB::table('patients')->where('id', 5)->update(['deleted_at' => now()]);
            $this->assertSame('suppressed', $source->calculate($category, '2026-11-11')['status']);
            DB::table('patients')->where('id', 5)->update(['deleted_at' => null]);
        }
    }

    public function test_local_day_boundaries_follow_the_configured_hospital_timezone(): void
    {
        config(['app.timezone' => 'Africa/Lagos']);
        foreach (range(1, 5) as $id) {
            DB::table('patients')->insert(['id' => $id, 'registration_date' => '2026-11-11 00:00:00']);
        }
        DB::table('patients')->insert(['id' => 6, 'registration_date' => '2026-11-12 00:00:00']);
        $this->assertSame(5, app(InsightDailyAggregateSource::class)->calculate('patients', '2026-11-11')['value']);
    }

    public function test_metric_definitions_have_unique_stable_keys_and_supported_module_gates(): void
    {
        $catalog = app(InsightMetricCatalog::class);
        $definitions = $catalog->definitions();
        $this->assertSame($sourceKeys = app(InsightDailyAggregateSource::class)->supportedCategories(), array_keys($definitions));
        $metricKeys = array_column($definitions, 'metric_key');
        $this->assertCount(count(array_unique($metricKeys)), $metricKeys);
        $this->assertSame(1, InsightMetricCatalog::SCHEMA_VERSION);
        $categoryCatalog = app(InsightCategoryCatalog::class);
        foreach ($sourceKeys as $key) {
            $definition = $catalog->definition($key);
            $this->assertSame('count', $definition['unit']);
            $this->assertNotContains($key, array_column($categoryCatalog->forModules([]), 'key'));
            $this->assertContains($key, array_column($categoryCatalog->forModules([$definition['module']]), 'key'));
        }
    }

    public function test_local_preview_returns_versioned_aggregate_and_only_explicit_metadata(): void
    {
        foreach (range(1, 5) as $id) {
            DB::table('patients')->insert(['id' => $id, 'registration_date' => '2026-11-11 12:00:00']);
        }
        $this->mock(InsightCategoryCatalog::class)->shouldReceive('availableForInstallation')->andReturn([
            ['key' => 'patients', 'label' => 'Patients', 'description' => 'Registrations'],
        ]);
        $result = app(InsightLocalPreview::class)->calculate('patients', '2026-11-11');
        $this->assertSame([
            'category' => 'patients', 'date' => '2026-11-11', 'status' => 'available', 'value' => 5,
            'label' => 'Patients', 'metric_key' => 'patient_records.registrations', 'schema_version' => 1,
            'unit' => 'count', 'source' => 'patients', 'event_time' => 'registration_date', 'filters' => 'Exclude deleted patients.',
        ], $result);
        $bounded = app(InsightLocalPreview::class)->calculate('patients', '2026-11-11', true);
        $this->assertSame(5, $bounded['value']);
        $this->assertSame('patients', $bounded['unit']);
    }

    public function test_local_preview_rejects_unlicensed_and_unsupported_metrics(): void
    {
        $this->mock(InsightCategoryCatalog::class)->shouldReceive('availableForInstallation')->andReturn([]);
        try {
            app(InsightLocalPreview::class)->calculate('patients', '2026-11-11');
            $this->fail('A metric outside the licensed modules must not be calculated.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('not available', $exception->getMessage());
        }
        $this->expectException(InvalidArgumentException::class);
        app(InsightLocalPreview::class)->calculate('prescriptions', '2026-11-11');
    }

    public function test_local_preview_fails_when_license_cannot_be_verified(): void
    {
        $this->mock(InsightCategoryCatalog::class)->shouldReceive('availableForInstallation')->andReturn(null);
        $this->expectException(RuntimeException::class);
        app(InsightLocalPreview::class)->calculate('patients', '2026-11-11');
    }
}
