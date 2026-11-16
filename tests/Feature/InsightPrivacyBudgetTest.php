<?php

namespace Tests\Feature;

use App\Services\InsightPrivacyBudget;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;

class InsightPrivacyBudgetTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
        (require database_path('migrations/2026_11_12_000011_create_insight_privacy_ledger.php'))->up();
    }

    public function test_unprovisioned_budget_fails_closed(): void
    {
        try {
            app(InsightPrivacyBudget::class)->reserve((string) Str::uuid(), str_repeat('a', 64), 1);
            $this->fail('An unprovisioned budget must fail closed.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('not been provisioned', $exception->getMessage());
        }
        $this->assertDatabaseCount('insight_privacy_reservations', 0);
    }

    public function test_idempotent_reservations_exhaust_budget_without_double_charging(): void
    {
        $this->provision(10);
        $service = app(InsightPrivacyBudget::class);
        $id = (string) Str::uuid();
        $hash = hash('sha256', 'synthetic-request');
        $this->assertSame('reserved', $service->reserve($id, $hash, 6));
        $this->assertSame('duplicate', $service->reserve($id, $hash, 6));
        $this->assertSame(6, (int) DB::table('insight_privacy_budget')->value('spent_units'));
        try {
            $service->reserve((string) Str::uuid(), $hash, 5);
            $this->fail('The budget cannot be overspent.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('exhausted', $exception->getMessage());
        }
        $this->assertSame('reserved', $service->reserve((string) Str::uuid(), $hash, 4));
        $this->assertSame(10, (int) DB::table('insight_privacy_budget')->value('spent_units'));
        $this->assertDatabaseCount('insight_privacy_reservations', 2);
    }

    public function test_conflicting_retries_never_consume_more_budget(): void
    {
        $this->provision(10);
        $service = app(InsightPrivacyBudget::class);
        $id = (string) Str::uuid();
        $hash = str_repeat('a', 64);
        $service->reserve($id, $hash, 2);
        foreach ([[str_repeat('b', 64), 2], [$hash, 3]] as [$newHash, $cost]) {
            try {
                $service->reserve($id, $newHash, $cost);
                $this->fail('Conflicting reservation must fail.');
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString('conflicts', $exception->getMessage());
            }
        }
        $this->assertSame(2, (int) DB::table('insight_privacy_budget')->value('spent_units'));
        $this->assertDatabaseCount('insight_privacy_reservations', 1);
    }

    public function test_invalid_inputs_and_corrupt_budget_fail_closed(): void
    {
        $this->provision(5);
        foreach ([['not-a-uuid', str_repeat('a', 64), 1], [(string) Str::uuid(), 'bad', 1], [(string) Str::uuid(), str_repeat('a', 64), 0]] as [$id, $hash, $cost]) {
            try {
                app(InsightPrivacyBudget::class)->reserve($id, $hash, $cost);
                $this->fail('Invalid reservation must fail.');
            } catch (InvalidArgumentException $exception) {
                $this->assertStringContainsString('Invalid', $exception->getMessage());
            }
        }
        DB::table('insight_privacy_budget')->where('id', 1)->update(['spent_units' => 6]);
        try {
            app(InsightPrivacyBudget::class)->reserve((string) Str::uuid(), str_repeat('a', 64), 1);
            $this->fail('Corrupt budget must fail.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('invalid', $exception->getMessage());
        }
        $this->assertDatabaseCount('insight_privacy_reservations', 0);
    }

    public function test_reservation_rejects_mismatched_balance_and_unexpected_budget_rows(): void
    {
        $this->provision(10);
        $service = app(InsightPrivacyBudget::class);
        $id = (string) Str::uuid();
        $hash = str_repeat('a', 64);
        $service->reserve($id, $hash, 3);
        DB::table('insight_privacy_budget')->where('id', 1)->update(['spent_units' => 2]);

        foreach ([$id, (string) Str::uuid()] as $requestId) {
            try {
                $service->reserve($requestId, $hash, 3);
                $this->fail('A mismatched ledger must fail closed, including on retries.');
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString('does not match', $exception->getMessage());
            }
        }
        $this->assertSame(2, (int) DB::table('insight_privacy_budget')->where('id', 1)->value('spent_units'));
        $this->assertDatabaseCount('insight_privacy_reservations', 1);
        DB::table('insight_privacy_budget')->where('id', 1)->update(['spent_units' => 3]);
        DB::table('insight_privacy_budget')->insert(['id' => 2, 'limit_units' => 10, 'spent_units' => 0]);
        try {
            $service->reserve((string) Str::uuid(), $hash, 1);
            $this->fail('Unexpected budget rows must fail closed.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('unexpected rows', $exception->getMessage());
        }
        $this->assertDatabaseCount('insight_privacy_reservations', 1);
        $this->assertSame(3, (int) DB::table('insight_privacy_budget')->where('id', 1)->value('spent_units'));
    }

    public function test_invalid_reservation_costs_are_rejected_even_when_their_sum_matches_the_budget(): void
    {
        $this->provision(10);
        DB::table('insight_privacy_budget')->where('id', 1)->update(['spent_units' => 3]);
        foreach ([-1, 4] as $cost) {
            DB::table('insight_privacy_reservations')->insert([
                'id' => (string) Str::uuid(), 'request_hash' => str_repeat('a', 64),
                'cost_units' => $cost, 'created_at' => now(),
            ]);
        }
        try {
            app(InsightPrivacyBudget::class)->reserve((string) Str::uuid(), str_repeat('b', 64), 1);
            $this->fail('Invalid reservation costs must fail closed even if they sum to the balance.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('invalid costs', $exception->getMessage());
        }
        $this->assertSame(3, (int) DB::table('insight_privacy_budget')->value('spent_units'));
        $this->assertDatabaseCount('insight_privacy_reservations', 2);
    }

    public function test_migration_rollback_refuses_to_delete_either_budget_or_reservation_data(): void
    {
        $migration = require database_path('migrations/2026_11_12_000011_create_insight_privacy_ledger.php');
        $this->provision(10);
        try {
            $migration->down();
            $this->fail('A provisioned budget must not be deleted on rollback.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('cannot be rolled back', $exception->getMessage());
        }
        $this->assertDatabaseCount('insight_privacy_budget', 1);
        DB::table('insight_privacy_budget')->delete();
        DB::table('insight_privacy_reservations')->insert([
            'id' => (string) Str::uuid(), 'request_hash' => str_repeat('a', 64),
            'cost_units' => 1, 'created_at' => now(),
        ]);
        try {
            $migration->down();
            $this->fail('Reservation history must not be deleted on rollback.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('cannot be rolled back', $exception->getMessage());
        }
        $this->assertDatabaseCount('insight_privacy_reservations', 1);
        DB::table('insight_privacy_reservations')->delete();
        $migration->down();
        $this->assertFalse(Schema::hasTable('insight_privacy_budget'));
        $this->assertFalse(Schema::hasTable('insight_privacy_reservations'));
    }

    private function provision(int $limit): void
    {
        DB::table('insight_privacy_budget')->insert([
            'id' => 1,
            'limit_units' => $limit,
            'spent_units' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
