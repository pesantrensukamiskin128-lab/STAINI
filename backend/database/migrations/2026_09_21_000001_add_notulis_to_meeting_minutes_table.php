<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('meeting_minutes', function (Blueprint $table) {
            $table->foreignId('notulis_id')->nullable()->after('signer_id')
                ->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('meeting_minutes', function (Blueprint $table) {
            $table->dropConstrainedForeignId('notulis_id');
        });
    }
};
