<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('graduation_registrations', function (Blueprint $table) {
            $table->text('impression_message')->nullable()->after('thesis_title');
            $table->string('payment_proof_path')->nullable()->after('impression_message');
            $table->string('photo_path')->nullable()->after('payment_proof_path');
        });
    }

    public function down(): void
    {
        Schema::table('graduation_registrations', function (Blueprint $table) {
            $table->dropColumn(['impression_message', 'payment_proof_path', 'photo_path']);
        });
    }
};
