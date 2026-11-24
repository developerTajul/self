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
 *  - `name` is nullable because partial-contact sources (e.g., WhatsApp
 *    phone-only or Facebook email-only webhooks) may arrive before a full
 *    name is enriched.
 *  - `email` (191) and `phone` (50) are declared `->unique()` separately
 *    (never chained with `->index()` and never combined into a composite
 *    unique key). Application ingestion MUST normalize empty strings `''` to
 *    `null`, lowercase+trim emails, format phones to E.164, and resolve
 *    cross-record collisions (e.g., email matches Lead A while phone matches
 *    Lead B) via deterministic domain merge before persistence.
 *  - `['status', 'source']` uses a composite index to accelerate both
 *    status-only queries and combined dashboard filters without index merges.
 *  - `meta_json` stores source-specific attributes as `longText` for broad
 *    MariaDB/MySQL compatibility; application layer validates JSON structure.
 *  - All `dateTime` columns store UTC timestamps managed explicitly by the
 *    application repository layer.
 */
final class CreateLeadsTable extends Migration
{
    public function up(): void
    {
        Schema::create('leads', function (Blueprint $table) {
            $table->id();
            $table->string('uuid', 36)->unique();
            $table->string('name')->nullable();
            $table->string('email', 191)->nullable()->unique();
            $table->string('phone', 50)->nullable()->unique();
            $table->string('company_name')->nullable();
            $table->string('website_url')->nullable();
            $table->string('status', 50)->default('new');
            $table->string('source', 50);
            $table->longText('meta_json')->nullable();

            $table->foreignId('assigned_to')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();

            $table->index(['status', 'source'], 'leads_status_source_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leads');
    }
}