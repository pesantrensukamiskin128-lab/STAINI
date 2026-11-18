<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\LecturerWorkController;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use ReflectionMethod;
use Tests\TestCase;

class LecturerWorkImportAuthorsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('students');
        Schema::dropIfExists('lecturers');
        Schema::create('lecturers', function ($table) {
            $table->id();
            $table->string('nidn')->nullable();
        });
        Schema::create('students', function ($table) {
            $table->id();
            $table->string('nim')->nullable();
        });

        DB::table('lecturers')->insert([
            ['id' => 1, 'nidn' => '00001234'],
            ['id' => 2, 'nidn' => '00005678'],
        ]);
        DB::table('students')->insert([
            ['id' => 1, 'nim' => '2026123456'],
        ]);
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('students');
        Schema::dropIfExists('lecturers');

        parent::tearDown();
    }

    public function test_import_resolves_coauthors_by_type_and_preserves_import_order(): void
    {
        $authors = $this->resolveCoAuthors('dosen:00005678;mahasiswa:2026123456', 1);

        $this->assertSame([
            ['type' => 'dosen', 'id' => 2],
            ['type' => 'mahasiswa', 'id' => 1],
        ], $authors);
    }

    public function test_import_resolves_authors_by_database_id_when_public_identifiers_are_missing(): void
    {
        $authors = $this->resolveCoAuthors('dosen_id:2;mahasiswa_id:1', 1);

        $this->assertSame([
            ['type' => 'dosen', 'id' => 2],
            ['type' => 'mahasiswa', 'id' => 1],
        ], $authors);
    }

    public function test_import_rejects_unknown_duplicate_and_primary_authors(): void
    {
        foreach ([
            ['dosen:99999999', 1],
            ['mahasiswa:2026123456;mahasiswa:2026123456', 1],
            ['dosen:00001234', 1],
        ] as [$value, $ownerId]) {
            try {
                $this->resolveCoAuthors($value, $ownerId);
                $this->fail("Expected validation to reject {$value}.");
            } catch (ValidationException $exception) {
                $this->assertNotEmpty($exception->errors()['file']);
            }
        }
    }

    private function resolveCoAuthors(string $value, int $ownerId): array
    {
        $method = new ReflectionMethod(LecturerWorkController::class, 'resolveImportedCoAuthors');

        return $method->invoke(app(LecturerWorkController::class), $value, $ownerId, 2);
    }
}
