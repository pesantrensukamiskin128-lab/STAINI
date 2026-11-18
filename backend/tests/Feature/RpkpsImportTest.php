<?php

namespace Tests\Feature;

use App\Exports\RpkpsWorkbookExport;
use App\Http\Controllers\Api\RpkpsController;
use App\Models\Rpkps;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Maatwebsite\Excel\Facades\Excel;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;
use Maatwebsite\Excel\Concerns\WithTitle;
use Mockery;
use Tests\TestCase;

class RpkpsImportTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('rpkps', function (Blueprint $table): void {
            $table->id();
            $table->string('code');
            $table->string('status');
            $table->string('verification_code')->nullable();
            $table->timestamps();
        });
        Schema::create('learning_outcomes', function (Blueprint $table): void {
            $table->id();
            $table->string('code');
        });
        Schema::create('rpkps_cpls', function (Blueprint $table): void {
            $table->unsignedBigInteger('rpkps_id');
            $table->unsignedBigInteger('learning_outcome_id');
            $table->timestamps();
        });
        Schema::create('rpkps_cpmks', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('rpkps_id');
            $table->string('code');
            $table->text('description');
            $table->integer('order');
            $table->timestamps();
        });
        Schema::create('rpkps_assessments', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('rpkps_id');
            $table->string('name');
            $table->integer('weight');
            $table->text('description')->nullable();
            $table->integer('order');
            $table->timestamps();
        });
        Schema::create('rpkps_weekly_plans', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('rpkps_id');
            $table->integer('week_number');
            $table->string('sub_cpmk')->nullable();
            $table->text('indicators')->nullable();
            $table->text('learning_material')->nullable();
            $table->json('methods')->nullable();
            $table->text('offline_learning')->nullable();
            $table->text('online_learning')->nullable();
            $table->text('lecturer_activity')->nullable();
            $table->text('student_activity')->nullable();
            $table->text('assessment_form')->nullable();
            $table->text('assessment_criteria')->nullable();
            $table->text('media')->nullable();
            $table->string('duration')->nullable();
            $table->integer('weight');
            $table->timestamps();
        });
        Schema::create('rpkps_references', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('rpkps_id');
            $table->enum('type', ['Utama', 'Pendukung']);
            $table->string('category', 100);
            $table->string('title');
            $table->string('author')->nullable();
            $table->string('year')->nullable();
            $table->string('publisher')->nullable();
            $table->string('isbn_doi')->nullable();
            $table->string('url')->nullable();
            $table->integer('order');
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        foreach ([
            'rpkps_weekly_plans',
            'rpkps_references',
            'rpkps_assessments',
            'rpkps_cpmks',
            'rpkps_cpls',
            'learning_outcomes',
            'rpkps',
        ] as $table) {
            Schema::dropIfExists($table);
        }

        parent::tearDown();
    }

    public function test_import_defaults_an_empty_weekly_weight_to_zero(): void
    {
        $rpkps = Rpkps::create(['code' => 'RPS-1', 'status' => 'DRAFT']);
        $admin = Mockery::mock(User::class);
        $admin->shouldReceive('hasAnyRole')
            ->with(['SUPER_ADMIN', 'ADMIN_AKADEMIK'])
            ->andReturn(true);

        $request = Request::create('/rpkps/1/import-excel', 'POST');
        $request->setUserResolver(fn () => $admin);
        app('request')->setUserResolver(fn () => $admin);
        $request->files->set('file', UploadedFile::fake()->createWithContent(
            'rps.xlsx',
            Excel::raw(new class implements WithMultipleSheets {
                public function sheets(): array
                {
                    return [
                        new class implements FromArray, WithTitle {
                            public function array(): array
                            {
                                return [
                                    ['week_number', 'sub_cpmk', 'methods', 'weight'],
                                    [1, '', 'Ceramah|Diskusi', null],
                                    [2, '', 'Tanya jawab, studi kasus', 5],
                                ];
                            }

                            public function title(): string
                            {
                                return 'RENCANA_MINGGUAN';
                            }
                        },
                        new class implements FromArray, WithTitle {
                            public function array(): array
                            {
                                return [
                                    ['type', 'category', 'title', 'author', 'year', 'publisher', 'isbn_doi', 'url', 'order'],
                                    ['Dokumen Kurikulum', 'Utama', 'Kurikulum PAI', 'STAI', 2018, null, null, null, 1],
                                ];
                            }

                            public function title(): string
                            {
                                return 'REFERENSI';
                            }
                        },
                    ];
                }
            }, \Maatwebsite\Excel\Excel::XLSX)
        ));

        $response = app(RpkpsController::class)->importExcel($request, $rpkps);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(0, (int) DB::table('rpkps_weekly_plans')->value('weight'));
        $this->assertSame(['Ceramah', 'Diskusi'], json_decode(DB::table('rpkps_weekly_plans')->where('week_number', 1)->value('methods'), true));
        $this->assertSame(['Tanya jawab', 'studi kasus'], json_decode(DB::table('rpkps_weekly_plans')->where('week_number', 2)->value('methods'), true));
        $this->assertDatabaseHas('rpkps_references', [
            'type' => 'Utama',
            'category' => 'Dokumen Kurikulum',
            'title' => 'Kurikulum PAI',
        ]);
    }

    public function test_template_documents_current_rps_fields_and_relationships(): void
    {
        $sheets = collect((new RpkpsWorkbookExport(template: true))->sheets())
            ->keyBy(fn ($sheet) => $sheet->title());

        $this->assertSame([
            'week_number', 'sub_cpmk', 'indicators', 'learning_material', 'methods',
            'offline_learning', 'online_learning', 'lecturer_activity', 'student_activity',
            'assessment_form', 'assessment_criteria', 'media', 'duration', 'weight',
        ], $sheets['RENCANA_MINGGUAN']->array()[0]);
        $this->assertSame([
            'type', 'category', 'title', 'author', 'year', 'publisher', 'isbn_doi', 'url', 'order',
        ], $sheets['REFERENSI']->array()[0]);
        $this->assertSame('Ceramah|Diskusi', $sheets['RENCANA_MINGGUAN']->array()[1][4]);
        $this->assertStringContainsString('maksimal 100 karakter', implode(' ', array_map(
            fn ($row) => (string) ($row[0] ?? ''),
            $sheets['PETUNJUK']->array()
        )));
    }
}
