<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('insight_privacy_budget', function (Blueprint $table): void {
            $table->unsignedTinyInteger('id')->primary();
            $table->unsignedBigInteger('limit_units');
            $table->unsignedBigInteger('spent_units')->default(0);
            $table->timestamps();
        });
        Schema::create('insight_privacy_reservations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->char('request_hash', 64);
            $table->unsignedBigInteger('cost_units');
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        if (DB::table('insight_privacy_budget')->exists() || DB::table('insight_privacy_reservations')->exists()) {
            throw new RuntimeException('A privacy ledger containing accounting data cannot be rolled back. Preserve it for reviewed recovery.');
        }
        Schema::dropIfExists('insight_privacy_reservations');
        Schema::dropIfExists('insight_privacy_budget');
    }
};
