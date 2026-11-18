<?php

use App\Support\RpkpsSubCpmkCode;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $legacyCodesByRpkps = [];

        DB::table('rpkps_cpmks')
            ->orderBy('rpkps_id')
            ->orderBy('order')
            ->orderBy('id')
            ->get()
            ->groupBy('rpkps_id')
            ->each(function ($cpmks, $rpkpsId) use (&$legacyCodesByRpkps): void {
                foreach ($cpmks->values() as $cpmkIndex => $cpmk) {
                    $subs = DB::table('rpkps_sub_cpmks')
                        ->where('rpkps_cpmk_id', $cpmk->id)
                        ->orderBy('order')
                        ->orderBy('id')
                        ->get();

                    foreach ($subs->values() as $subIndex => $sub) {
                        $newCode = RpkpsSubCpmkCode::make($cpmkIndex + 1, $subIndex + 1);
                        $legacyCodesByRpkps[$rpkpsId][$sub->code][] = $newCode;
                        DB::table('rpkps_sub_cpmks')->where('id', $sub->id)->update(['code' => $newCode]);
                    }
                }
            });

        foreach ($legacyCodesByRpkps as $rpkpsId => $legacyCodes) {
            $uniqueReplacements = collect($legacyCodes)
                ->filter(fn (array $newCodes): bool => count(array_unique($newCodes)) === 1)
                ->map(fn (array $newCodes): string => $newCodes[0])
                ->all();
            $this->replaceCodesInTextColumns((int) $rpkpsId, $uniqueReplacements);
        }
    }

    public function down(): void
    {
        $replacementsByRpkps = [];
        DB::table('rpkps_cpmks')
            ->orderBy('rpkps_id')
            ->orderBy('order')
            ->orderBy('id')
            ->get()
            ->groupBy('rpkps_id')
            ->each(function ($cpmks, $rpkpsId) use (&$replacementsByRpkps): void {
                foreach ($cpmks as $cpmk) {
                    $subs = DB::table('rpkps_sub_cpmks')
                        ->where('rpkps_cpmk_id', $cpmk->id)
                        ->orderBy('order')
                        ->orderBy('id')
                        ->get();

                    foreach ($subs->values() as $subIndex => $sub) {
                        $oldCode = 'SUB-'.str_pad((string) ($subIndex + 1), 2, '0', STR_PAD_LEFT);
                        $replacementsByRpkps[$rpkpsId][$sub->code] = $oldCode;
                        DB::table('rpkps_sub_cpmks')->where('id', $sub->id)->update([
                            'code' => $oldCode,
                        ]);
                    }
                }
            });
        foreach ($replacementsByRpkps as $rpkpsId => $replacements) {
            $this->replaceCodesInTextColumns((int) $rpkpsId, $replacements);
        }
    }

    private function replaceCodesInTextColumns(int $rpkpsId, array $replacements): void
    {
        if (!$replacements) {
            return;
        }

        $normalizedReplacements = [];
        foreach ($replacements as $oldCode => $newCode) {
            $normalizedReplacements[strtoupper((string) $oldCode)] = $newCode;
        }
        $pattern = '/(?<![\pL\pN_.-])(?:'.implode('|', array_map(
            fn (string $code): string => preg_quote($code, '/'),
            array_keys($normalizedReplacements)
        )).')(?![\pL\pN_.-])/iu';

        foreach (['rpkps_weekly_plans', 'rpkps_assessment_instruments'] as $table) {
            DB::table($table)->where('rpkps_id', $rpkpsId)->get()->each(
                function ($record) use ($table, $pattern, $normalizedReplacements): void {
                    $value = $record->sub_cpmk;
                    if (!is_string($value) || $value === '') {
                        return;
                    }

                    $value = preg_replace_callback($pattern, fn (array $match): string =>
                        $normalizedReplacements[strtoupper($match[0])] ?? $match[0],
                        $value
                    );

                    DB::table($table)->where('id', $record->id)->update(['sub_cpmk' => $value]);
                }
            );
        }
    }
};
