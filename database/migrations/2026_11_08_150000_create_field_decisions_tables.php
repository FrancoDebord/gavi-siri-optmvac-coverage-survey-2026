<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Registre des décisions prises sur le terrain, classées par catégorie (liste extensible). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('decision_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name', 80)->unique();
            $table->string('color', 20)->default('secondary');
            $table->unsignedSmallInteger('sort')->default(100);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('field_decisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('decision_category_id')->constrained()->restrictOnDelete();
            $table->date('decided_on');
            $table->date('effective_from')->nullable();
            $table->string('title', 200);
            $table->text('context')->nullable(); // situation, problème rencontré, justification
            $table->text('decision');
            $table->string('decided_by', 150)->nullable();
            // Portée : toutes les zones (vide), une zone, un village ou une grappe.
            $table->foreignId('health_zone_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('village_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('cluster_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status', 20)->default('en_vigueur'); // en_vigueur, remplacee, annulee
            $table->foreignId('replaced_by_id')->nullable()->constrained('field_decisions')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->index(['status', 'decided_on']);
        });

        $now = now();
        DB::table('decision_categories')->insert([
            ['name' => 'Décisions administratives', 'color' => 'primary', 'sort' => 10, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'Décisions de sampling', 'color' => 'success', 'sort' => 20, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'Décisions de collecte', 'color' => 'warning', 'sort' => 30, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'Décisions de lecture de carnet', 'color' => 'info', 'sort' => 40, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'Autre', 'color' => 'secondary', 'sort' => 900, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('field_decisions');
        Schema::dropIfExists('decision_categories');
    }
};
