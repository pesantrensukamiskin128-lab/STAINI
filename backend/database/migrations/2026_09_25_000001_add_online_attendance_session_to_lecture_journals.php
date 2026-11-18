<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lecture_journals', function (Blueprint $table): void {
            $table->string('attendance_token_hash', 64)->nullable()->after('status');
            $table->timestamp('attendance_opens_at')->nullable()->after('attendance_token_hash');
            $table->timestamp('attendance_closes_at')->nullable()->after('attendance_opens_at');
            $table->index(['attendance_opens_at', 'attendance_closes_at']);
        });
    }

    public function down(): void
    {
        Schema::table('lecture_journals', function (Blueprint $table): void {
            $table->dropIndex(['attendance_opens_at', 'attendance_closes_at']);
            $table->dropColumn([
                'attendance_token_hash',
                'attendance_opens_at',
                'attendance_closes_at',
            ]);
        });
    }
};
