<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How many times a job's handler answered «not yet» (QueueJobResult::retryLater): deferrals do not spend
 * attempts, so they are counted apart and bounded by the descriptor's max deferrals.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('larena_queue_jobs', static function (Blueprint $table): void {
            $table->unsignedInteger('deferral_count')->default(0)->after('max_attempts');
        });
    }

    public function down(): void
    {
        Schema::table('larena_queue_jobs', static function (Blueprint $table): void {
            $table->dropColumn('deferral_count');
        });
    }
};
