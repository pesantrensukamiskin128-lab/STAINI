<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rpkps_references', function (Blueprint $table): void {
            $table->string('category', 100)->change();
        });
    }

    public function down(): void
    {
        Schema::table('rpkps_references', function (Blueprint $table): void {
            $table->enum('category', [
                'Buku', 'Jurnal', 'Peraturan', 'Fatwa', 'Putusan',
                'Website', 'Artikel', 'Modul', 'Video', 'E-book', 'Lainnya',
            ])->default('Buku')->change();
        });
    }
};
