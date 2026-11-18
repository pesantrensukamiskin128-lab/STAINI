<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('library_books', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('legacy_id')->nullable()->unique();
            $table->string('accession_no', 60)->nullable()->unique();
            $table->string('barcode', 80)->nullable()->unique();
            $table->string('title');
            $table->string('subtitle')->nullable();
            $table->string('author');
            $table->string('isbn', 30)->nullable();
            $table->string('publisher', 150)->nullable();
            $table->unsignedSmallInteger('publish_year')->nullable();
            $table->string('language', 50)->default('Indonesia');
            $table->string('category', 120)->nullable();
            $table->foreignId('study_program_id')->nullable()->constrained('study_programs')->nullOnDelete();
            $table->string('classification', 60)->nullable();
            $table->string('shelf', 60)->nullable();
            $table->text('description')->nullable();
            $table->string('cover_path')->nullable();
            $table->string('digital_path')->nullable();
            $table->enum('access_type', ['physical', 'digital', 'both'])->default('physical');
            $table->unsignedInteger('total_copies')->default(1);
            $table->unsignedInteger('available_copies')->default(1);
            $table->unsignedInteger('view_count')->default(0);
            $table->enum('status', ['published', 'draft', 'archived'])->default('published');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['status', 'publish_year']);
            $table->index(['status', 'category']);
            $table->index(['study_program_id', 'status']);
            $table->fullText(['title', 'subtitle', 'author', 'isbn', 'description'], 'library_books_search');
        });

        Schema::create('library_loans', function (Blueprint $table) {
            $table->id();
            $table->string('loan_code', 40)->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('book_id')->constrained('library_books')->restrictOnDelete();
            $table->dateTime('borrowed_at');
            $table->dateTime('due_at');
            $table->dateTime('returned_at')->nullable();
            $table->enum('status', ['borrowed', 'overdue', 'returned', 'lost'])->default('borrowed');
            $table->unsignedTinyInteger('renewal_count')->default(0);
            $table->decimal('fine_amount', 12, 2)->default(0);
            $table->decimal('fine_paid', 12, 2)->default(0);
            $table->string('notes')->nullable();
            $table->foreignId('processed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['user_id', 'status']);
            $table->index(['status', 'due_at']);
        });

        Schema::create('library_reservations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('book_id')->constrained('library_books')->cascadeOnDelete();
            $table->unsignedInteger('queue_no');
            $table->dateTime('expires_at')->nullable();
            $table->enum('status', ['waiting', 'ready', 'collected', 'cancelled', 'expired'])->default('waiting');
            $table->timestamps();
            $table->index(['book_id', 'status', 'created_at']);
            $table->index(['user_id', 'status']);
        });

        Schema::create('library_favorites', function (Blueprint $table) {
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('book_id')->constrained('library_books')->cascadeOnDelete();
            $table->timestamps();
            $table->primary(['user_id', 'book_id']);
        });

        Schema::create('library_repository_items', function (Blueprint $table) {
            $table->id();
            $table->string('identifier', 80)->unique();
            $table->string('title');
            $table->string('authors', 500);
            $table->longText('abstract')->nullable();
            $table->string('keywords', 500)->nullable();
            $table->enum('item_type', ['thesis', 'article', 'research', 'community_service', 'book', 'proceeding', 'module', 'policy', 'other']);
            $table->foreignId('study_program_id')->nullable()->constrained('study_programs')->nullOnDelete();
            $table->unsignedSmallInteger('publication_year')->nullable();
            $table->string('language', 50)->default('Indonesia');
            $table->string('publisher', 200)->nullable();
            $table->string('file_path')->nullable();
            $table->unsignedBigInteger('file_size')->default(0);
            $table->enum('access_level', ['open', 'members', 'embargo', 'closed'])->default('open');
            $table->date('embargo_until')->nullable();
            $table->string('license_name', 100)->nullable();
            $table->enum('status', ['draft', 'submitted', 'revision', 'approved', 'published', 'rejected'])->default('submitted');
            $table->text('review_note')->nullable();
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('download_count')->default(0);
            $table->dateTime('published_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'item_type']);
            $table->index(['publication_year', 'study_program_id']);
            $table->fullText(['title', 'authors', 'abstract', 'keywords'], 'library_repository_search');
        });

        Schema::create('library_procurement_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('requester_id')->constrained('users')->cascadeOnDelete();
            $table->string('title');
            $table->string('author')->nullable();
            $table->string('isbn', 30)->nullable();
            $table->text('reason')->nullable();
            $table->enum('status', ['submitted', 'reviewed', 'approved', 'purchased', 'rejected'])->default('submitted');
            $table->text('admin_note')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['status', 'created_at']);
        });

        Schema::create('library_visits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('visitor_name', 150)->nullable();
            $table->string('purpose')->nullable();
            $table->date('visit_date');
            $table->dateTime('check_in_at');
            $table->dateTime('check_out_at')->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['visit_date', 'check_out_at']);
        });

        Schema::create('library_announcements', function (Blueprint $table) {
            $table->id();
            $table->string('title', 200);
            $table->text('body');
            $table->dateTime('starts_at')->nullable();
            $table->dateTime('ends_at')->nullable();
            $table->boolean('is_published')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('library_settings', function (Blueprint $table) {
            $table->string('setting_key', 100)->primary();
            $table->text('setting_value')->nullable();
            $table->timestamps();
        });

        Schema::create('library_activity_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action', 100);
            $table->string('entity_type', 80)->nullable();
            $table->string('entity_id', 80)->nullable();
            $table->string('description', 500)->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->timestamps();
            $table->index(['action', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('library_activity_logs');
        Schema::dropIfExists('library_settings');
        Schema::dropIfExists('library_announcements');
        Schema::dropIfExists('library_visits');
        Schema::dropIfExists('library_procurement_requests');
        Schema::dropIfExists('library_repository_items');
        Schema::dropIfExists('library_favorites');
        Schema::dropIfExists('library_reservations');
        Schema::dropIfExists('library_loans');
        Schema::dropIfExists('library_books');
    }
};
