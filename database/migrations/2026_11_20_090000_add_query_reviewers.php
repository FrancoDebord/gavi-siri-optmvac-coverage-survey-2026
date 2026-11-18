<?php

use App\Models\AppSetting;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Validation confiée : le data manager ou l'administrateur confie la validation d'une query (réponse en attente)
 * à un superviseur ou à un compte « validateur », qui valide ou rouvre. Les corrections des agents ne sont plus
 * appliquées d'office : elles attendent la validation.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('data_queries', function (Blueprint $table) {
            $table->foreignId('reviewer_id')->nullable()->after('validated_by')->constrained('users')->nullOnDelete();
            $table->foreignId('review_assigned_by')->nullable()->after('reviewer_id')->constrained('users')->nullOnDelete();
            $table->timestamp('review_assigned_at')->nullable()->after('review_assigned_by');
            $table->index('reviewer_id');
        });
        DB::table('app_settings')->where('key', AppSetting::AUTO_APPLY)->update(['value' => json_encode(false), 'updated_at' => now()]);
        (new RolesAndPermissionsSeeder)->run();
    }

    public function down(): void
    {
        Schema::table('data_queries', function (Blueprint $table) {
            $table->dropIndex(['reviewer_id']);
            $table->dropConstrainedForeignId('reviewer_id');
            $table->dropConstrainedForeignId('review_assigned_by');
            $table->dropColumn('review_assigned_at');
        });
    }
};
