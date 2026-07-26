<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('larena_queue_jobs', static function (Blueprint $table): void {
            $table->string('id', 32)->primary();
            $table->string('job_type', 100);
            $table->string('handler_ref', 150);
            $table->text('payload_json');
            $table->char('payload_sha256', 64);
            $table->string('idempotency_key', 128);
            $table->string('correlation_id', 64);
            $table->unsignedSmallInteger('priority');
            $table->string('status', 32);
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->unsignedSmallInteger('max_attempts');
            $table->unsignedInteger('timeout_seconds');
            $table->unsignedInteger('retry_delay_seconds');
            $table->unsignedInteger('lease_seconds');
            $table->dateTime('available_at', 6);
            $table->char('lease_owner_hash', 64)->nullable();
            $table->char('lease_token_hash', 64)->nullable();
            $table->dateTime('lease_expires_at', 6)->nullable();
            $table->dateTime('heartbeat_at', 6)->nullable();
            $table->dateTime('cancel_requested_at', 6)->nullable();
            $table->string('failure_reason', 100)->nullable();
            $table->text('result_json')->nullable();
            $table->dateTime('completed_at', 6)->nullable();
            $table->dateTime('created_at', 6);
            $table->dateTime('updated_at', 6);

            $table->unique(['job_type', 'idempotency_key'], 'larena_queue_jobs_idempotency_unique');
            $table->index(
                ['status', 'available_at', 'priority', 'created_at'],
                'larena_queue_jobs_claim_index',
            );
            $table->index('correlation_id', 'larena_queue_jobs_correlation_index');
        });

        Schema::create('larena_queue_attempts', static function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('job_id', 32);
            $table->unsignedSmallInteger('attempt_number');
            $table->string('status', 32);
            $table->dateTime('started_at', 6);
            $table->dateTime('finished_at', 6)->nullable();
            $table->string('failure_reason', 100)->nullable();
            $table->unique(['job_id', 'attempt_number'], 'larena_queue_attempts_job_attempt_unique');
            $table->foreign('job_id', 'larena_queue_attempts_job_foreign')
                ->references('id')
                ->on('larena_queue_jobs')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('larena_queue_attempts');
        Schema::dropIfExists('larena_queue_jobs');
    }
};
