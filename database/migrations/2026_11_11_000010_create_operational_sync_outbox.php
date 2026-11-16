<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('operational_sync_connection', function (Blueprint $table): void {
            $table->unsignedTinyInteger('id')->primary();
            $table->uuid('installation_uuid')->nullable();
            $table->string('platform_api_url', 512)->nullable();
            $table->string('pending_platform_api_url', 512)->nullable();
            $table->text('encrypted_credential')->nullable();
            $table->text('encrypted_pending_code')->nullable();
            $table->text('encrypted_pending_proof')->nullable();
            $table->timestamps();
        });
        Schema::create('operational_sync_sequences', function (Blueprint $table): void {
            $table->unsignedTinyInteger('id')->primary();
            $table->unsignedBigInteger('last_sequence')->default(0);
        });
        Schema::create('operational_sync_outbox', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->unsignedBigInteger('sequence')->unique();
            $table->text('payload_json');
            $table->char('payload_sha256', 64);
            $table->timestamp('occurred_at');
            $table->string('status', 20)->index();
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('next_attempt_at')->nullable();
            $table->uuid('lease_token')->nullable();
            $table->timestamp('lease_expires_at')->nullable();
            $table->string('last_error_code', 40)->nullable();
            $table->timestamp('acknowledged_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('operational_sync_outbox');
        Schema::dropIfExists('operational_sync_sequences');
        Schema::dropIfExists('operational_sync_connection');
    }
};
