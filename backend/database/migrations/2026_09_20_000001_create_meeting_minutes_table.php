<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('meeting_minutes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->foreignId('event_id')->nullable()->constrained('events')->nullOnDelete();
            $table->foreignId('reviewer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('signer_id')->constrained('users')->restrictOnDelete();
            $table->string('number')->nullable()->unique();
            $table->string('title');
            $table->date('meeting_date');
            $table->string('location')->nullable();
            $table->text('identity')->nullable();
            $table->json('participants')->nullable();
            $table->text('agenda')->nullable();
            $table->longText('proceedings')->nullable();
            $table->longText('decisions')->nullable();
            $table->json('follow_ups')->nullable();
            $table->longText('closing')->nullable();
            $table->string('status')->default('DRAFT');
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamp('signed_at')->nullable();
            $table->text('revision_note')->nullable();
            $table->string('verification_token', 64)->unique();
            $table->timestamps();
            $table->index(['status', 'meeting_date']);
        });
    }

    public function down(): void { Schema::dropIfExists('meeting_minutes'); }
};
