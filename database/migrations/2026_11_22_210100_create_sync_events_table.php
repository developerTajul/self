<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use Framework\Database\Migration;
use Framework\Database\Schema\Blueprint;
use Framework\Database\Schema\Schema;

/**
 * Idempotency ledger and audit log for all outbound and inbound CRM events.
 *
 * Enforces a database-level UNIQUE constraint on `idempotency_key` so
 * duplicate webhook deliveries or retried queue jobs never create duplicate
 * side effects.
 *
 * Architectural notes:
 *  - `idempotency_key` uses VARCHAR(191) to stay safely within utf8mb4
 *    unique index byte limits; any external key exceeding 191 chars must
 *    be SHA-256 hashed (64 chars) before persistence.
 *  - `next_attempt_at` is NOT NULL and must be initialized to UTC `now()` on
 *    insert so worker polling (`WHERE status = 'pending' AND next_attempt_at <= ?
 *    ORDER BY next_attempt_at, id`) executes a pure index range scan without `OR`.
 *  - `locked_at` records worker lease acquisition time so a periodic reaper
 *    can reclaim stuck `processing` rows if a worker crashes mid-execution.
 *  - `last_error` uses `mediumText` (matching `dead_letters.error_message`) so
 *    full upstream HTML/JSON error responses never fail updates under strict mode.
 */
final class CreateSyncEventsTable extends Migration
{
    public function up(): void
    {
        Schema::create('sync_events', function (Blueprint $table) {
            $table->id();
            $table->string('idempotency_key', 191)->unique();
            $table->string('direction', 20);
            $table->string('provider', 50);
            $table->string('event_type', 100);
            $table->longText('payload_json');
            $table->string('status', 30)->default('pending');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->dateTime('next_attempt_at');
            $table->dateTime('locked_at')->nullable();
            $table->mediumText('last_error')->nullable();
            $table->dateTime('processed_at')->nullable();
            $table->dateTime('created_at')->nullable()->index();
            $table->dateTime('updated_at')->nullable();

            $table->index(['status', 'next_attempt_at'], 'sync_events_status_next_attempt_idx');
            $table->index(['provider', 'status'], 'sync_events_provider_status_idx');
            $table->index(['event_type', 'status'], 'sync_events_event_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sync_events');
    }
}