<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use Framework\Database\Migration;
use Framework\Database\Schema\Blueprint;
use Framework\Database\Schema\Schema;

/**
 * Dead Letter Queue (DLQ) storage for permanently failed CRM sync jobs
 * after exhausting exponential backoff retries.
 */
final class CreateDeadLettersTable extends Migration
{
    public function up(): void
    {
        Schema::create('dead_letters', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sync_event_id')
                ->nullable()
                ->constrained('sync_events')
                ->nullOnDelete();
            $table->string('provider', 50)->index();
            $table->string('job_class', 255);
            $table->longText('payload_json');
            $table->text('error_message');
            $table->unsignedSmallInteger('http_status_code')->nullable();
            $table->timestamp('failed_at');
            $table->timestamp('retried_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dead_letters');
    }
}