<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('outgoing_letters', 'carbon_copy')) {
            Schema::table('outgoing_letters', function (Blueprint $table) {
                $table->text('carbon_copy')->nullable()->after('recipient');
            });
        }
    }

    public function down(): void
    {
        Schema::table('outgoing_letters', function (Blueprint $table) {
            $table->dropColumn('carbon_copy');
        });
    }
};
