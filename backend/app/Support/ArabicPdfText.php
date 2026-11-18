<?php

namespace App\Support;

use DOMDocument;
use DOMText;
use DOMXPath;

/**
 * Dompdf tidak melakukan penyambungan huruf (shaping) maupun urutan kanan-ke-kiri.
 * Kelas ini mengubah teks Arab menjadi bentuk presentasi (FE70–FEFF) dalam urutan
 * visual sehingga dapat dirender dengan font Amiri di PDF.
 */
class ArabicPdfText
{
    private const ARABIC = '\x{0600}-\x{06FF}\x{0750}-\x{077F}';

    /** isolated, final, initial, medial (null = tidak ada bentuk tersebut) */
    private const FORMS = [
        0x0621 => [0xFE80, null, null, null],
        0x0622 => [0xFE81, 0xFE82, null, null],
        0x0623 => [0xFE83, 0xFE84, null, null],
        0x0624 => [0xFE85, 0xFE86, null, null],
        0x0625 => [0xFE87, 0xFE88, null, null],
        0x0626 => [0xFE89, 0xFE8A, 0xFE8B, 0xFE8C],
        0x0627 => [0xFE8D, 0xFE8E, null, null],
        0x0628 => [0xFE8F, 0xFE90, 0xFE91, 0xFE92],
        0x0629 => [0xFE93, 0xFE94, null, null],
        0x062A => [0xFE95, 0xFE96, 0xFE97, 0xFE98],
        0x062B => [0xFE99, 0xFE9A, 0xFE9B, 0xFE9C],
        0x062C => [0xFE9D, 0xFE9E, 0xFE9F, 0xFEA0],
        0x062D => [0xFEA1, 0xFEA2, 0xFEA3, 0xFEA4],
        0x062E => [0xFEA5, 0xFEA6, 0xFEA7, 0xFEA8],
        0x062F => [0xFEA9, 0xFEAA, null, null],
        0x0630 => [0xFEAB, 0xFEAC, null, null],
        0x0631 => [0xFEAD, 0xFEAE, null, null],
        0x0632 => [0xFEAF, 0xFEB0, null, null],
        0x0633 => [0xFEB1, 0xFEB2, 0xFEB3, 0xFEB4],
        0x0634 => [0xFEB5, 0xFEB6, 0xFEB7, 0xFEB8],
        0x0635 => [0xFEB9, 0xFEBA, 0xFEBB, 0xFEBC],
        0x0636 => [0xFEBD, 0xFEBE, 0xFEBF, 0xFEC0],
        0x0637 => [0xFEC1, 0xFEC2, 0xFEC3, 0xFEC4],
        0x0638 => [0xFEC5, 0xFEC6, 0xFEC7, 0xFEC8],
        0x0639 => [0xFEC9, 0xFECA, 0xFECB, 0xFECC],
        0x063A => [0xFECD, 0xFECE, 0xFECF, 0xFED0],
        0x0640 => [0x0640, 0x0640, 0x0640, 0x0640],
        0x0641 => [0xFED1, 0xFED2, 0xFED3, 0xFED4],
        0x0642 => [0xFED5, 0xFED6, 0xFED7, 0xFED8],
        0x0643 => [0xFED9, 0xFEDA, 0xFEDB, 0xFEDC],
        0x0644 => [0xFEDD, 0xFEDE, 0xFEDF, 0xFEE0],
        0x0645 => [0xFEE1, 0xFEE2, 0xFEE3, 0xFEE4],
        0x0646 => [0xFEE5, 0xFEE6, 0xFEE7, 0xFEE8],
        0x0647 => [0xFEE9, 0xFEEA, 0xFEEB, 0xFEEC],
        0x0648 => [0xFEED, 0xFEEE, null, null],
        0x0649 => [0xFEEF, 0xFEF0, null, null],
        0x064A => [0xFEF1, 0xFEF2, 0xFEF3, 0xFEF4],
        0x067E => [0xFB56, 0xFB57, 0xFB58, 0xFB59],
        0x0686 => [0xFB7A, 0xFB7B, 0xFB7C, 0xFB7D],
        0x0698 => [0xFB8A, 0xFB8B, null, null],
        0x06A9 => [0xFB8E, 0xFB8F, 0xFB90, 0xFB91],
        0x06AF => [0xFB92, 0xFB93, 0xFB94, 0xFB95],
        0x06CC => [0xFBFC, 0xFBFD, 0xFBFE, 0xFBFF],
    ];

    /** isolated, final */
    private const LAM_ALEF = [
        0x0622 => [0xFEF5, 0xFEF6],
        0x0623 => [0xFEF7, 0xFEF8],
        0x0625 => [0xFEF9, 0xFEFA],
        0x0627 => [0xFEFB, 0xFEFC],
    ];

    public static function containsArabic(string $text): bool
    {
        return (bool) preg_match('/[' . self::ARABIC . ']/u', $text);
    }

    /** Dompdf menulis cache metrik font ke storage/fonts. */
    public static function ensureFontDirectory(): void
    {
        $dir = storage_path('fonts');
        if (! is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
    }

    /** Teks polos (akan di-escape) -> HTML aman dengan teks Arab yang sudah dibentuk. */
    public static function text(?string $text): string
    {
        $text = (string) $text;
        if (! self::containsArabic($text)) {
            return e($text);
        }

        return self::convertText($text);
    }

    /** HTML dari editor -> HTML dengan seluruh node teks Arab dibentuk ulang. */
    public static function html(?string $html): string
    {
        $html = (string) $html;
        if (! self::containsArabic($html)) {
            return $html;
        }

        $dom = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8" ?><div id="ar-root">' . $html . '</div>', LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $xpath = new DOMXPath($dom);
        $root = $dom->getElementById('ar-root') ?? $xpath->query('//div[@id="ar-root"]')->item(0);
        if (! $root) {
            return $html;
        }

        $nodes = [];
        foreach ($xpath->query('.//text()', $root) as $node) {
            if ($node instanceof DOMText && self::containsArabic($node->data)) {
                $nodes[] = $node;
            }
        }

        foreach ($nodes as $node) {
            $fragment = $dom->createDocumentFragment();
            $fragment->appendXML(self::convertText($node->data));
            $node->parentNode->replaceChild($fragment, $node);
        }

        $out = '';
        foreach ($root->childNodes as $child) {
            $out .= $dom->saveHTML($child);
        }

        return $out;
    }

    private static function convertText(string $text): string
    {
        $pattern = '/[' . self::ARABIC . ']+(?:[\s\x{060C}\x{061B}\x{061F}.,:()\-\d]*[' . self::ARABIC . ']+)*/u';
        $parts = preg_split($pattern, $text, -1, PREG_SPLIT_OFFSET_CAPTURE);
        preg_match_all($pattern, $text, $matches);

        $esc = fn (string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_XML1, 'UTF-8');

        $result = '';
        foreach ($parts as $i => [$plain]) {
            $result .= $esc($plain);
            if (isset($matches[0][$i])) {
                $result .= '<span class="ar">' . $esc(self::shapeRun($matches[0][$i])) . '</span>';
            }
        }

        return $result;
    }

    /** Satu rangkaian Arab (boleh berisi spasi/angka) -> urutan visual. */
    private static function shapeRun(string $run): string
    {
        $tokens = preg_split('/(\s+)/u', $run, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);
        $shaped = [];
        foreach ($tokens as $token) {
            $shaped[] = preg_match('/^\s+$/u', $token) ? $token : self::shapeWord($token);
        }

        return implode('', array_reverse($shaped));
    }

    private static function shapeWord(string $word): string
    {
        $chars = preg_split('//u', $word, -1, PREG_SPLIT_NO_EMPTY);

        // Kelompokkan huruf dasar beserta tanda baca (harakat) di belakangnya.
        $clusters = [];
        foreach ($chars as $char) {
            $cp = mb_ord($char, 'UTF-8');
            $isMark = ($cp >= 0x064B && $cp <= 0x065F) || $cp === 0x0670;
            if ($isMark && $clusters) {
                $clusters[count($clusters) - 1]['marks'] .= $char;
            } else {
                $clusters[] = ['cp' => $cp, 'char' => $char, 'marks' => ''];
            }
        }

        $count = count($clusters);
        $out = [];
        for ($i = 0; $i < $count; $i++) {
            $cp = $clusters[$i]['cp'];
            $marks = $clusters[$i]['marks'];

            if (! isset(self::FORMS[$cp])) {
                // Angka dan tanda baca tetap, tetapi dibalik kembali agar terbaca benar setelah pembalikan akhir.
                $out[] = $clusters[$i]['char'] . $marks;
                continue;
            }

            $joinsPrev = $i > 0 && self::joinsNext($clusters[$i - 1]['cp']);

            // Ligatur lam + alef
            if ($cp === 0x0644 && $i + 1 < $count && isset(self::LAM_ALEF[$clusters[$i + 1]['cp']]) && $clusters[$i]['marks'] === '') {
                $lig = self::LAM_ALEF[$clusters[$i + 1]['cp']];
                $out[] = mb_chr($joinsPrev ? $lig[1] : $lig[0], 'UTF-8') . $clusters[$i + 1]['marks'];
                $i++;
                continue;
            }

            $joinsNextChar = self::joinsNext($cp) && $i + 1 < $count && isset(self::FORMS[$clusters[$i + 1]['cp']]);
            $forms = self::FORMS[$cp];
            $form = match (true) {
                $joinsPrev && $joinsNextChar => $forms[3] ?? $forms[1] ?? $forms[0],
                $joinsPrev => $forms[1] ?? $forms[0],
                $joinsNextChar => $forms[2] ?? $forms[0],
                default => $forms[0],
            };
            $out[] = mb_chr($form, 'UTF-8') . $marks;
        }

        // Balik per klaster, lalu balik kembali deretan angka agar tetap kiri-ke-kanan.
        $visual = implode('', array_reverse($out));

        return preg_replace_callback('/[\d\x{0660}-\x{0669}]+/u', fn ($m) => implode('', array_reverse(preg_split('//u', $m[0], -1, PREG_SPLIT_NO_EMPTY))), $visual);
    }

    /** Apakah huruf ini tersambung ke huruf sesudahnya (dual-joining). */
    private static function joinsNext(int $cp): bool
    {
        $forms = self::FORMS[$cp] ?? null;

        return $forms !== null && $forms[2] !== null;
    }
}
