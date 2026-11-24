<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use Framework\Database\Migration;
use Framework\Database\Schema\Blueprint;
use Framework\Database\Schema\Schema;

/**
 * Polymorphic linker between local domain entities (`lead`, `order`) and
 * external CRM records (`contact`, `deal`) across multiple providers.
 *
 * Architectural notes:
 *  - `last_synced_hash` stores a SHA-256 digest of the canonical normalized
 *    payload so inbound CRM webhooks echoing the same state can be ignored
 *    without triggering an infinite sync loop.
 *  - `crm_mappings_remote_record_unique` enforces a 1:1 link per provider
 *    object so inbound webhooks deterministically resolve a single local entity.
 *  - Because default MySQL `utf8mb4_unicode_ci` collations are case-insensitive,
 *    providers with case-sensitive 15-char IDs (e.g., Salesforce) must be
 *    normalized to their canonical 18-char case-insensitive IDs prior to
 *    persistence, and compared strictly (`===`) in PHP.
 */
final class CreateCrmMappingsTable extends Migration
{
    public function up(): void
    {
        Schema::create('crm_mappings', function (Blueprint $table) {
            $table->id();
            $table->string('entity_type', 50);
            $table->unsignedBigInteger('local_entity_id');
            $table->string('provider', 50);
            $table->string('crm_object_type', 50);
            $table->string('crm_record_id', 100);
            $table->string('last_synced_hash', 64)->nullable();
            $table->dateTime('last_synced_at')->nullable();
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();

            $table->unique(
                ['entity_type', 'local_entity_id', 'provider', 'crm_object_type'],
                'crm_mappings_local_provider_object_unique'
            );
            $table->unique(
                ['provider', 'crm_object_type', 'crm_record_id'],
                'crm_mappings_remote_record_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_mappings');
    }
}