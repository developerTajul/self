<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use Framework\Database\Migration;
use Framework\Database\Schema\Blueprint;
use Framework\Database\Schema\Schema;

/**
 * Squashed baseline: this file carries all columns and constraints required
 * for multi-source lead ingestion (Contact Form 7, WooCommerce, Inbound CRM
 * Webhooks) and external CRM cross-referencing, so a fresh database gets
 * the final shape in one step.
 *
 * Architectural notes:
 *  - `uuid` provides a non-sequential public identifier for external CRM
 *    custom properties (`leadbridge_external_id`) and API responses without
 *    exposing auto-increment primary keys.
 *  - `email` and `phone` are declared `->unique()` alone (never chained with
 *    `->index()`, which compiles to a non-unique index) to enforce database-level
 *    uniqueness and enable InnoDB row-level locking (`FOR UPDATE`).
 *  - `website_url` supports B2B lead enrichment alongside `company_name`.
 *  - `meta_json` stores event-specific or source-specific attributes (e.g.,
 *    CF7 `message` / `subject`, WooCommerce shipping notes) without bloating
 *    the core relational columns.
 *  - `assigned_to` is nullable because a lead arriving from a form, order,
 *    or webhook has no owner until claimed, and `nullOnDelete` frees leads
 *    rather than deleting them when a user is removed.
 */
final class CreateLeadsTable extends Migration
{
    public function up(): void
    {
        Schema::create('leads', function (Blueprint $table) {
            $table->id();
            $table->string('uuid', 36)->unique();
            $table->string('name');
            $table->string('email')->nullable()->unique();
            $table->string('phone')->nullable()->unique();
            $table->string('company_name')->nullable();
            $table->string('website_url')->nullable();
            $table->string('status')->default('new')->index();
            $table->string('source')->index();
            $table->longText('meta_json')->nullable();

            // No ->after() here: that modifier only compiles inside ALTER
            // TABLE, and this column is declared at create time.
            $table->foreignId('assigned_to')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leads');
    }
}