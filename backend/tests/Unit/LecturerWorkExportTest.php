<?php

namespace Tests\Unit;

use App\Exports\LecturerWorkExport;
use Illuminate\Support\Collection;
use PHPUnit\Framework\TestCase;

class LecturerWorkExportTest extends TestCase
{
    public function test_template_includes_coauthor_column_and_format_instructions(): void
    {
        $rows = (new LecturerWorkExport(template: true))->array();

        $this->assertContains('co_authors', $rows[0]);
        $this->assertSame('dosen:NIDN;mahasiswa:NIM', end($rows[1]));
        $this->assertStringContainsString('co_authors', implode(' ', array_map(
            fn (array $row) => implode(' ', $row),
            array_slice($rows, 3)
        )));
    }

    public function test_export_serializes_lecturer_and_student_coauthors_as_import_identifiers(): void
    {
        $work = (object) [
            'lecturer' => (object) ['nidn' => '0987654321'],
            'coAuthors' => new Collection([
                (object) [
                    'lecturer' => (object) ['nidn' => '1234567890'],
                    'student' => null,
                ],
                (object) [
                    'lecturer' => null,
                    'student' => (object) ['nim' => '2026123456'],
                ],
            ]),
            'type' => 'buku',
            'title' => 'Karya bersama',
            'year' => 2026,
            'description' => null,
            'keywords' => null,
            'publisher' => null,
            'isbn_issn' => null,
            'hki_number' => null,
            'published_date' => null,
            'status' => 'draft',
            'repository_url' => null,
        ];

        $rows = (new LecturerWorkExport(new Collection([$work])))->array();

        $this->assertSame('dosen:1234567890;mahasiswa:2026123456', end($rows[1]));
    }

    public function test_identifier_columns_are_formatted_as_text(): void
    {
        $formats = (new LecturerWorkExport(template: true))->columnFormats();

        $this->assertSame('@', $formats['A']);
        $this->assertSame('@', $formats['M']);
    }

    public function test_export_falls_back_to_database_ids_when_an_author_has_no_public_identifier(): void
    {
        $work = (object) [
            'lecturer' => (object) ['nidn' => '0987654321'],
            'coAuthors' => new Collection([
                (object) [
                    'lecturer_id' => 22,
                    'lecturer' => (object) ['nidn' => null],
                    'student' => null,
                ],
                (object) [
                    'student_id' => 33,
                    'lecturer' => null,
                    'student' => (object) ['nim' => null],
                ],
            ]),
            'type' => 'buku',
            'title' => 'Karya bersama',
            'year' => 2026,
            'description' => null,
            'keywords' => null,
            'publisher' => null,
            'isbn_issn' => null,
            'hki_number' => null,
            'published_date' => null,
            'status' => 'draft',
            'repository_url' => null,
        ];

        $rows = (new LecturerWorkExport(new Collection([$work])))->array();

        $this->assertSame('dosen_id:22;mahasiswa_id:33', end($rows[1]));
    }
}
