<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('krs', function (Blueprint $table) {
            $table->timestamp('kaprodi_approved_at')->nullable()->after('approved_at');
            $table->foreignId('kaprodi_approved_by')->nullable()->after('kaprodi_approved_at')->constrained('users')->nullOnDelete();
            $table->text('kaprodi_note')->nullable()->after('kaprodi_approved_by');
            $table->string('rejected_by_stage', 20)->nullable()->after('kaprodi_note');
        });
    }

    public function down(): void
    {
        Schema::table('krs', function (Blueprint $table) {
            $table->dropForeign(['kaprodi_approved_by']);
            $table->dropColumn([
                'kaprodi_approved_at',
                'kaprodi_approved_by',
                'kaprodi_note',
                'rejected_by_stage',
            ]);
        });
    }
};
