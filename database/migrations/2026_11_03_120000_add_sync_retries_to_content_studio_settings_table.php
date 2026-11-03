<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Artikelen die de site niet kon opslaan, met hoe vaak het al misging.
 *
 * De cursor schuift na elke pagina door, ook als er een artikel op die
 * pagina mislukte; zonder deze lijst kwam zo'n artikel nooit meer langs.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('content_studio_settings', 'cs_sync_retries')) {
            return;
        }

        Schema::table('content_studio_settings', function (Blueprint $table) {
            $table->json('cs_sync_retries')->nullable()->after('cs_sync_cursor');
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('content_studio_settings', 'cs_sync_retries')) {
            Schema::table('content_studio_settings', function (Blueprint $table) {
                $table->dropColumn('cs_sync_retries');
            });
        }
    }
};
