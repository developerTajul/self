<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use Framework\Database\Migration;
use Framework\Database\Schema\Blueprint;
use Framework\Database\Schema\Schema;

/**
 * Dead Letter Queue (DLQ) storage for permanently failed CRM sync jobs
 * after exhausting exponential backoff retries.
 *
 * Architectural notes:
 *  - Uses `dateTime` instead of `timestamp` to avoid implicit MariaDB/MySQL
 *    `ON UPDATE CURRENT_TIMESTAMP` mutations on `failed_at` when `retried_at`
 *    is later updated.
 *  - `error_message` uses `mediumText` so full upstream HTML/JSON error
 *    responses never fail insertion under MySQL strict mode.
 *  - `sync_event_id` is unique per failed event; replaying a DLQ item creates
 *    a fresh `sync_events` row with a new idempotency key, records its ID in
 *    `replayed_sync_event_id`, and sets `retried_at` so full audit traceability
 *    is preserved.
 */
final class CreateDeadLettersTable extends Migration
{
    public function up(): void
    {
        Schema::create('dead_letters', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sync_event_id')
                ->nullable()
                ->unique()
                ->constrained('sync_events')
                ->nullOnDelete();
            $table->foreignId('replayed_sync_event_id')
                ->nullable()
                ->constrained('sync_events')
                ->nullOnDelete();
            $table->string('provider', 50);
            $table->string('job_class', 255);
            $table->longText('payload_json');
            $table->mediumText('error_message');
            $table->integer('http_status_code')->nullable();
            $table->dateTime('failed_at');
            $table->dateTime('retried_at')->nullable();

            $table->index(['provider', 'retried_at'], 'dead_letters_provider_retried_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dead_letters');
    }
}