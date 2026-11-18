<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\LecturerWorkController;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class LecturerWorkAuthorSearchTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('students');
        Schema::dropIfExists('lecturers');
        Schema::create('lecturers', function ($table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('full_name');
            $table->string('degree_front')->nullable();
            $table->string('degree_back')->nullable();
            $table->string('nidn')->nullable();
        });
        Schema::create('students', function ($table) {
            $table->id();
            $table->string('name');
            $table->string('nim')->nullable();
        });

        $user = new User();
        $user->id = 10;
        $user->email = 'owner@example.test';
        Auth::setUser($user);
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('students');
        Schema::dropIfExists('lecturers');

        parent::tearDown();
    }

    public function test_search_returns_matching_lecturers_and_students_but_excludes_the_owner(): void
    {
        DB::table('lecturers')->insert([
            ['user_id' => 10, 'full_name' => 'Dosen Kolaborator Pemilik', 'nidn' => '100'],
            ['user_id' => null, 'full_name' => 'Dosen Kolaborator', 'nidn' => '200'],
        ]);
        DB::table('students')->insert([
            ['name' => 'Mahasiswa Kolaborator', 'nim' => '300'],
        ]);

        $request = Request::create('/api/lecturer-works/authors/search', 'GET', ['search' => 'Kolaborator']);
        $response = app(LecturerWorkController::class)->searchAuthors($request)->getData(true);

        $this->assertSame([
            [
                'type' => 'lecturer',
                'id' => 2,
                'name' => 'Dosen Kolaborator',
                'identifier' => '200',
            ],
            [
                'type' => 'student',
                'id' => 1,
                'name' => 'Mahasiswa Kolaborator',
                'identifier' => '300',
            ],
        ], $response['data']);
    }
}
