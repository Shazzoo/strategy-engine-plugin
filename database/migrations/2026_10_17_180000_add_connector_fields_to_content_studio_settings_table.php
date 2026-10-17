<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Wat deze site nodig heeft om door de engine gebeld te worden.
 *
 * Het geheim krijgt de site één keer, als hij zichzelf aanmeldt met de
 * sync key en de client id die hier al stonden. Zolang het leeg is, draait
 * deze site nog op de oude manier: zelf elk kwartier ophalen.
 *
 * De cursor is hoe ver de site is met bijwerken - meer dan een plek in de
 * rij is het niet.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('content_studio_settings', function (Blueprint $table) {
            $table->string('cs_webhook_secret')->nullable()->after('cs_project_code');
            $table->string('cs_sync_cursor')->nullable()->after('cs_webhook_secret');
        });
    }

    public function down(): void
    {
        Schema::table('content_studio_settings', function (Blueprint $table) {
            $table->dropColumn(['cs_webhook_secret', 'cs_sync_cursor']);
        });
    }
};
