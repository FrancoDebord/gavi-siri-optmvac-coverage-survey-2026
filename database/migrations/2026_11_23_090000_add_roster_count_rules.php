<?php

use Database\Seeders\QualityRuleSeeder;
use Illuminate\Database\Migrations\Migration;

/**
 * Règles de conformité des rosters au nombre déclaré (ROSTER_MEMBERS, ROSTER_CAREGIVERS, ROSTER_CHILDREN) :
 * créées si elles manquent, sans toucher aux règles existantes. Les queries apparaissent au prochain contrôle qualité.
 */
return new class extends Migration
{
    public function up(): void
    {
        (new QualityRuleSeeder)->run();
    }

    public function down(): void
    {
        //
    }
};
