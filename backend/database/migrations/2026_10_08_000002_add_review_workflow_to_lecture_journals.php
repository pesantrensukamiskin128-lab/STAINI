<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lecture_journals', function (Blueprint $table): void {
            $table->string('review_status', 20)->default('NOT_SUBMITTED');
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_note')->nullable();
            $table->index(['status', 'review_status', 'meeting_date'], 'lecture_journal_review_queue_idx');
        });

        DB::table('lecture_journals')
            ->where('status', 'COMPLETED')
            ->update(['review_status' => 'PENDING']);
    }

    public function down(): void
    {
        Schema::table('lecture_journals', function (Blueprint $table): void {
            $table->dropIndex('lecture_journal_review_queue_idx');
            $table->dropForeign(['reviewed_by']);
            $table->dropColumn(['review_status', 'reviewed_by', 'reviewed_at', 'review_note']);
        });
    }
};
