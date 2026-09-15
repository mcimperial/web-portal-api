<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('cm_principal', function (Blueprint $table) {
            // Stores any Excel/CSV columns that were not mapped to a known
            // database field during import, as human-readable delimited text
            // (e.g. "Column A: value || Column B: value"). Using plain
            // delimited text (instead of JSON) keeps CSV/Excel export simple
            // and avoids quoting/escaping issues.
            $table->text('unmapped_columns')->nullable()->after('notes');

            // Tracks which user performed the import that populated
            // unmapped_columns, so the data can be scoped/filtered per user.
            $table->unsignedBigInteger('unmapped_columns_by')->nullable()->after('unmapped_columns');

            $table->foreign('unmapped_columns_by')
                ->references('id')->on('users')
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('cm_principal', function (Blueprint $table) {
            $table->dropForeign(['unmapped_columns_by']);
            $table->dropColumn(['unmapped_columns', 'unmapped_columns_by']);
        });
    }
};
