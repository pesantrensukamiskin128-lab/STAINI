<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lecture_materials', function (Blueprint $table): void {
            $table->string('material_type', 20)->default('LINK')->after('description');
            $table->timestamp('available_at')->nullable()->after('is_published');
            $table->foreignId('created_by')->nullable()->after('available_at')->constrained('users')->nullOnDelete();
            $table->index(['class_id', 'is_published', 'available_at']);
        });
    }

    public function down(): void
    {
        Schema::table('lecture_materials', function (Blueprint $table): void {
            $table->dropIndex(['class_id', 'is_published', 'available_at']);
            $table->dropConstrainedForeignId('created_by');
            $table->dropColumn(['material_type', 'available_at']);
        });
    }
};
