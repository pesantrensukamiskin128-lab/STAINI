<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lecture_journals', function (Blueprint $table): void {
            $table->string('delivery_mode', 10)->nullable()->after('lecturer_id');
            $table->index(['delivery_mode', 'status', 'meeting_date'], 'lecture_journal_honor_source_idx');
        });
    }

    public function down(): void
    {
        Schema::table('lecture_journals', function (Blueprint $table): void {
            $table->dropIndex('lecture_journal_honor_source_idx');
            $table->dropColumn('delivery_mode');
        });
    }
};
