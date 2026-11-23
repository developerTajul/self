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
 */
final class CreateSyncEventsTable extends Migration
{
    public function up(): void
    {
        Schema::create('sync_events', function (Blueprint $table) {
            $table->id();
            $table->string('idempotency_key', 191)->unique();
            $table->string('direction', 20)->index();
            $table->string('provider', 50)->index();
            $table->string('event_type', 100)->index();
            $table->longText('payload_json');
            $table->string('status', 30)->default('pending')->index();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sync_events');
    }
}