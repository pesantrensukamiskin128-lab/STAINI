<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assignments', function (Blueprint $table): void {
            $table->string('attachment_path')->nullable()->after('instructions');
            $table->string('attachment_url')->nullable()->after('attachment_path');
            $table->string('attachment_type', 100)->nullable()->after('attachment_url');
        });
    }

    public function down(): void
    {
        Schema::table('assignments', function (Blueprint $table): void {
            $table->dropColumn(['attachment_path', 'attachment_url', 'attachment_type']);
        });
    }
};
