<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\HonorController;
use App\Models\LectureJournal;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class LectureJournalReviewTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
        });
        Schema::create('courses', function (Blueprint $table): void {
            $table->id();
            $table->string('code');
            $table->string('name');
        });
        Schema::create('semesters', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
        });
        Schema::create('classes', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('course_id');
            $table->unsignedBigInteger('semester_id');
            $table->string('name');
        });
        Schema::create('lecturers', function (Blueprint $table): void {
            $table->id();
            $table->string('full_name')->nullable();
        });
        Schema::create('lecture_journals', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('class_id');
            $table->unsignedBigInteger('lecturer_id')->nullable();
            $table->unsignedInteger('meeting_number');
            $table->date('meeting_date');
            $table->string('topic');
            $table->text('description')->nullable();
            $table->text('learning_activity')->nullable();
            $table->string('status');
            $table->string('delivery_mode')->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->string('photo_path')->nullable();
            $table->string('review_status')->default('NOT_SUBMITTED');
            $table->unsignedBigInteger('reviewed_by')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_note')->nullable();
            $table->timestamps();
        });

        DB::table('users')->insert(['id' => 99, 'name' => 'Admin Keuangan']);
        DB::table('courses')->insert(['id' => 1, 'code' => 'MK001', 'name' => 'Mata Kuliah']);
        DB::table('semesters')->insert(['id' => 1, 'name' => '2026/2027 Ganjil']);
        DB::table('classes')->insert(['id' => 1, 'course_id' => 1, 'semester_id' => 1, 'name' => 'A']);
        DB::table('lecturers')->insert(['id' => 1, 'full_name' => 'Dosen Satu']);
        DB::table('lecture_journals')->insert([
            'id' => 1,
            'class_id' => 1,
            'lecturer_id' => 1,
            'meeting_number' => 1,
            'meeting_date' => '2026-10-05',
            'topic' => 'Materi pertemuan',
            'description' => 'Uraian pembelajaran',
            'status' => 'COMPLETED',
            'delivery_mode' => 'ONSITE',
            'photo_path' => 'journals/proof.jpg',
            'review_status' => 'PENDING',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_finance_reviewer_can_inspect_photo_and_approve_pending_journal(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('journals/proof.jpg', 'photo');

        $response = app(HonorController::class)->lectureJournalReviews(Request::create('/api/finance/honor/journal-reviews'));
        $journalData = $response->getData(true)['data'][0];
        $this->assertStringContainsString('journals/proof.jpg', $journalData['photo_url']);
        $this->assertSame('Mata Kuliah', $journalData['class_']['course']['name']);

        $request = Request::create('/api/finance/honor/journal-reviews/1', 'PATCH', ['status' => 'APPROVED']);
        $request->setUserResolver(function (): User {
            $user = new User();
            $user->id = 99;
            return $user;
        });
        $result = app(HonorController::class)->reviewLectureJournal($request, LectureJournal::findOrFail(1));

        $this->assertSame('APPROVED', $result->getData(true)['data']['review_status']);
        $this->assertDatabaseHas('lecture_journals', [
            'id' => 1,
            'review_status' => 'APPROVED',
            'reviewed_by' => 99,
        ]);
    }

    public function test_rejected_journal_review_requires_a_note(): void
    {
        $request = Request::create('/api/finance/honor/journal-reviews/1', 'PATCH', ['status' => 'REJECTED']);

        $this->expectException(ValidationException::class);
        app(HonorController::class)->reviewLectureJournal($request, LectureJournal::findOrFail(1));
    }
}
