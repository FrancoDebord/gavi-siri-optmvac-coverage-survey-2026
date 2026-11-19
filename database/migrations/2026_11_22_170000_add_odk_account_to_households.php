<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Compte ODK qui a envoyé la soumission (ODK Central : __system.submitterName ; Kobo : _submitted_by), et binôme
 * concerné par une query quand ce n'est pas celui de l'agent sélectionné (ex. compte ODK d'un autre binôme).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('households', function (Blueprint $table) {
            $table->string('submitter', 120)->nullable()->after('collector_id')->index();
        });
        Schema::table('data_queries', function (Blueprint $table) {
            $table->foreignId('team_id')->nullable()->after('collector_id')->constrained()->nullOnDelete();
        });

        // Ménages existants : compte lu dans la soumission brute (ou la copie source).
        DB::table('households')->select(['id', 'raw_data', 'source_data'])->orderBy('id')->chunkById(500, function ($rows) {
            foreach ($rows as $row) {
                $raw = json_decode((string) $row->raw_data, true) ?: [];
                $source = json_decode((string) $row->source_data, true) ?: [];
                $submitter = $raw['__system']['submitterName'] ?? $raw['_submitted_by'] ?? $source['submitter'] ?? null;
                if (is_string($submitter) && trim($submitter) !== '' && $submitter !== 'null') {
                    DB::table('households')->where('id', $row->id)->update(['submitter' => mb_substr(trim($submitter), 0, 120)]);
                }
            }
        });
    }

    public function down(): void
    {
        Schema::table('data_queries', function (Blueprint $table) {
            $table->dropConstrainedForeignId('team_id');
        });
        Schema::table('households', function (Blueprint $table) {
            $table->dropIndex(['submitter']);
            $table->dropColumn('submitter');
        });
    }
};
