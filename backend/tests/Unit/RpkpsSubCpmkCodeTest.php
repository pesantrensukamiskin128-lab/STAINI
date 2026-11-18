<?php

namespace Tests\Unit;

use App\Exports\RpkpsWorkbookExport;
use App\Models\Rpkps;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class RpkpsSubCpmkCodeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('rpkps', function (Blueprint $table): void {
            $table->id();
        });
        Schema::create('rpkps_cpmks', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('rpkps_id');
            $table->string('code');
            $table->text('description');
            $table->integer('order');
            $table->timestamps();
        });
        Schema::create('rpkps_sub_cpmks', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('rpkps_cpmk_id');
            $table->string('code');
            $table->text('description');
            $table->integer('order');
            $table->timestamps();
        });
        foreach (['rpkps_weekly_plans', 'rpkps_assessment_instruments'] as $tableName) {
            Schema::create($tableName, function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('rpkps_id');
                $table->text('sub_cpmk')->nullable();
            });
        }

        DB::table('rpkps')->insert(['id' => 1]);
        DB::table('rpkps_cpmks')->insert([
            ['id' => 1, 'rpkps_id' => 1, 'code' => 'CPMK-01', 'description' => 'Satu', 'order' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 2, 'rpkps_id' => 1, 'code' => 'CPMK-02', 'description' => 'Dua', 'order' => 2, 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::table('rpkps_sub_cpmks')->insert([
            ['id' => 11, 'rpkps_cpmk_id' => 1, 'code' => 'OLD-A', 'description' => 'A', 'order' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 12, 'rpkps_cpmk_id' => 1, 'code' => 'OLD-B', 'description' => 'B', 'order' => 2, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 21, 'rpkps_cpmk_id' => 2, 'code' => 'OLD-C', 'description' => 'C', 'order' => 1, 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::table('rpkps_weekly_plans')->insert(['rpkps_id' => 1, 'sub_cpmk' => 'OLD-A|OLD-C']);
        DB::table('rpkps_assessment_instruments')->insert(['rpkps_id' => 1, 'sub_cpmk' => 'OLD-B']);
    }

    protected function tearDown(): void
    {
        foreach (['rpkps_assessment_instruments', 'rpkps_weekly_plans', 'rpkps_sub_cpmks', 'rpkps_cpmks', 'rpkps'] as $table) {
            Schema::dropIfExists($table);
        }

        parent::tearDown();
    }

    public function test_renumbering_preserves_sub_cpmk_ids_and_updates_text_references(): void
    {
        $rpkps = Rpkps::findOrFail(1);
        $rpkps->refreshSubCpmkCodes();

        $this->assertSame([
            11 => 'Sub-1.1',
            12 => 'Sub-1.2',
            21 => 'Sub-2.1',
        ], DB::table('rpkps_sub_cpmks')->orderBy('id')->pluck('code', 'id')->all());
        $this->assertSame('Sub-1.1|Sub-2.1', DB::table('rpkps_weekly_plans')->value('sub_cpmk'));
        $this->assertSame('Sub-1.2', DB::table('rpkps_assessment_instruments')->value('sub_cpmk'));
    }

    public function test_ambiguous_legacy_codes_are_not_guessed(): void
    {
        DB::table('rpkps_sub_cpmks')->whereIn('id', [11, 21])->update(['code' => 'SUB-01']);
        DB::table('rpkps_weekly_plans')->update(['sub_cpmk' => 'SUB-01']);

        Rpkps::findOrFail(1)->refreshSubCpmkCodes();

        $this->assertSame('SUB-01', DB::table('rpkps_weekly_plans')->value('sub_cpmk'));
        $this->assertSame('Sub-1.1', DB::table('rpkps_sub_cpmks')->where('id', 11)->value('code'));
        $this->assertSame('Sub-2.1', DB::table('rpkps_sub_cpmks')->where('id', 21)->value('code'));
    }

    public function test_import_template_uses_generated_codes_and_merge_instructions(): void
    {
        $sheets = collect((new RpkpsWorkbookExport(null, true))->sheets())->keyBy(
            fn ($sheet) => $sheet->title()
        );

        $this->assertSame('Sub-1.1', $sheets['SUB_CPMK']->array()[1][1]);
        $this->assertSame('Sub-1.1', $sheets['RENCANA_MINGGUAN']->array()[1][1]);
        $this->assertStringContainsString('mempertahankan baris RPS', $sheets['PETUNJUK']->array()[4][0]);
    }
}
