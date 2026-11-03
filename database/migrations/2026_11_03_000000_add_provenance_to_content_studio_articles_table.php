<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * De herkomst van een artikel en wat de site er na publicatie van zag.
     *
     * provenance houdt het genormaliseerde object uit de Engine; versie,
     * revisie en vingerafdruk staan er los naast, zodat een query of een
     * beheerder ze kan lezen zonder JSON te ontleden.
     */
    private const COLUMNS = [
        'provenance' => ['json'],
        'provenance_version' => ['unsignedSmallInteger'],
        'body_revision' => ['string', 40],
        'disclosure_hash' => ['string', 40],
        'provenance_state' => ['string', 16],
        'provenance_errors' => ['text'],
        'disclosure_mode' => ['string', 8],
        'verification' => ['json'],
        'verification_status' => ['string', 32],
        'verified_at' => ['timestamp'],
        'reverify_count' => ['unsignedTinyInteger'],
    ];

    public function up(): void
    {
        Schema::table('content_studio_articles', function (Blueprint $table) {
            foreach (self::COLUMNS as $column => $definition) {
                if (Schema::hasColumn('content_studio_articles', $column)) {
                    continue;
                }

                $type = array_shift($definition);
                $field = $table->{$type}($column, ...$definition);

                match ($column) {
                    'reverify_count' => $field->default(0),
                    default => $field->nullable(),
                };
            }
        });
    }

    public function down(): void
    {
        Schema::table('content_studio_articles', function (Blueprint $table) {
            foreach (array_keys(self::COLUMNS) as $column) {
                if (Schema::hasColumn('content_studio_articles', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
