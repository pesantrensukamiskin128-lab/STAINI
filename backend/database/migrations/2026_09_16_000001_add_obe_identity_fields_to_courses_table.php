<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('courses', function (Blueprint $table): void {
            $table->string('course_group', 150)->nullable()->after('name');
            $table->unsignedTinyInteger('theory_credits')->default(0)->after('credits');
            $table->unsignedTinyInteger('practical_credits')->default(0)->after('theory_credits');
        });

        // Data lama hanya menyimpan total SKS. Pertahankan maknanya sebagai SKS teori
        // sampai pembagian teori/praktik diperbarui oleh pengelola mata kuliah.
        DB::table('courses')->update([
            'theory_credits' => DB::raw('credits'),
            'practical_credits' => 0,
        ]);
    }

    public function down(): void
    {
        Schema::table('courses', function (Blueprint $table): void {
            $table->dropColumn(['course_group', 'theory_credits', 'practical_credits']);
        });
    }
};
