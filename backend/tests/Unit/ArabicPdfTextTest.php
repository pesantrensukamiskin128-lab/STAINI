<?php

namespace Tests\Unit;

use App\Support\ArabicPdfText;
use PHPUnit\Framework\TestCase;

class ArabicPdfTextTest extends TestCase
{
    public function test_plain_text_is_untouched_apart_from_escaping(): void
    {
        $this->assertSame('Surat &lt;A&gt;', ArabicPdfText::html('Surat &lt;A&gt;'));
    }

    public function test_arabic_is_shaped_into_presentation_forms_in_visual_order(): void
    {
        $out = ArabicPdfText::html('<p>Halo بسم الله</p>');

        // ب س م  -> initial, medial, final; visual order is reversed.
        $this->assertStringContainsString('<span class="ar">', $out);
        $this->assertStringContainsString("\u{FEE2}\u{FEB4}\u{FE91}", $out);
        // ا ل ل ه: alef terisolasi, lam awal, lam tengah, ha akhir (urutan visual dibalik).
        $this->assertStringContainsString("\u{FEEA}\u{FEE0}\u{FEDF}\u{FE8D}", $out);
        $this->assertStringContainsString('Halo', $out);
        $this->assertDoesNotMatchRegularExpression('/[\x{0628}\x{0633}\x{0645}]/u', $out);
    }

    public function test_lam_alef_ligature(): void
    {
        $this->assertStringContainsString("\u{FEFB}", ArabicPdfText::text('لا'));
    }
}
