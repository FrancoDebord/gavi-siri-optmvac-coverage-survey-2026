<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Binômes : deux agents de terrain (l1_fieldworkers) qui travaillent ensemble, d'après binomes.csv. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('teams', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique(); // codes des agents, ex. 27-48
            $table->string('name');
            $table->foreignId('health_zone_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });

        Schema::table('collectors', function (Blueprint $table) {
            $table->foreignId('team_id')->nullable()->after('health_zone_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('collectors', fn (Blueprint $table) => $table->dropConstrainedForeignId('team_id'));
        Schema::dropIfExists('teams');
    }
};
