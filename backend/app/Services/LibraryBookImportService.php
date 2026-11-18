<?php

namespace App\Services;

use App\Models\LibraryBook;
use App\Models\StudyProgram;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use ZipArchive;

class LibraryBookImportService
{
    private const MAX_ARCHIVE_ENTRIES = 10000;
    private const MAX_UNCOMPRESSED_BYTES = 536870912;

    public function import(UploadedFile $archive, int $userId): array
    {
        if (!class_exists(ZipArchive::class)) {
            throw ValidationException::withMessages(['file' => 'Ekstensi ZIP PHP belum tersedia pada server.']);
        }

        $zip = new ZipArchive();
        if ($zip->open($archive->getRealPath()) !== true) {
            throw ValidationException::withMessages(['file' => 'File arsip buku tidak dapat dibuka.']);
        }

        try {
            $entries = $this->indexEntries($zip);
            $csvPath = $entries['books.csv'] ?? null;
            if ($csvPath === null) {
                throw ValidationException::withMessages(['file' => 'Arsip harus berisi books.csv pada folder utama.']);
            }

            $csv = $this->readEntry($zip, $csvPath, 25 * 1024 * 1024);
            $rows = $this->parseRows($csv);
            $imported = 0;
            $skipped = 0;
            $warnings = [];
            $createdPaths = [];

            try {
                DB::transaction(function () use ($rows, $entries, $zip, $userId, &$imported, &$skipped, &$warnings, &$createdPaths): void {
                    foreach ($rows as $index => $row) {
                        $rowNumber = $index + 2;
                        $legacyId = $this->integerValue($row['legacy_id'] ?? $row['id'] ?? null);
                        if ($legacyId && LibraryBook::where('legacy_id', $legacyId)->exists()) {
                            $skipped++;
                            continue;
                        }

                        $programCode = trim((string) ($row['program_code'] ?? ''));
                        $programId = $programCode !== ''
                            ? StudyProgram::where('code', $programCode)->value('id')
                            : null;
                        if ($programCode !== '' && !$programId) {
                            $warnings[] = "Baris {$rowNumber}: program studi {$programCode} tidak ditemukan; buku diimpor tanpa program studi.";
                        }

                        $coverPath = $this->importAsset(
                            $zip,
                            $entries,
                            (string) ($row['cover_path'] ?? ''),
                            'covers',
                            ['jpg', 'jpeg', 'png', 'webp'],
                            $warnings,
                            $rowNumber,
                            $createdPaths
                        );
                        $digitalPath = $this->importAsset(
                            $zip,
                            $entries,
                            (string) ($row['digital_path'] ?? ''),
                            'books',
                            ['pdf'],
                            $warnings,
                            $rowNumber,
                            $createdPaths
                        );

                        LibraryBook::create([
                            'legacy_id' => $legacyId,
                            'accession_no' => $this->nullableString($row['accession_no'] ?? null),
                            'barcode' => $this->nullableString($row['barcode'] ?? null),
                            'title' => trim((string) $row['title']),
                            'subtitle' => $this->nullableString($row['subtitle'] ?? null),
                            'author' => trim((string) $row['author']),
                            'isbn' => $this->nullableString($row['isbn'] ?? null),
                            'publisher' => $this->nullableString($row['publisher'] ?? null),
                            'publish_year' => $this->integerValue($row['publish_year'] ?? null),
                            'language' => $this->nullableString($row['language'] ?? null) ?? 'Indonesia',
                            'category' => $this->nullableString($row['category'] ?? null),
                            'study_program_id' => $programId,
                            'classification' => $this->nullableString($row['classification'] ?? null),
                            'shelf' => $this->nullableString($row['shelf'] ?? null),
                            'description' => $this->nullableString($row['description'] ?? null),
                            'cover_path' => $coverPath,
                            'digital_path' => $digitalPath,
                            'access_type' => in_array($row['access_type'] ?? '', ['physical', 'digital', 'both'], true)
                                ? $row['access_type']
                                : 'physical',
                            'total_copies' => max(0, $this->integerValue($row['total_copies'] ?? 1) ?? 1),
                            'available_copies' => max(0, min(
                                max(0, $this->integerValue($row['total_copies'] ?? 1) ?? 1),
                                $this->integerValue($row['available_copies'] ?? $row['total_copies'] ?? 1) ?? 1
                            )),
                            'view_count' => max(0, $this->integerValue($row['view_count'] ?? 0) ?? 0),
                            'status' => in_array($row['status'] ?? '', ['published', 'draft', 'archived'], true)
                                ? $row['status']
                                : 'published',
                            'created_by' => $userId,
                        ]);
                        $imported++;
                    }
                });
            } catch (\Throwable $exception) {
                foreach ($createdPaths as [$disk, $path]) {
                    Storage::disk($disk)->delete($path);
                }
                throw $exception;
            }

            return compact('imported', 'skipped', 'warnings');
        } finally {
            $zip->close();
        }
    }

    private function indexEntries(ZipArchive $zip): array
    {
        if ($zip->numFiles > self::MAX_ARCHIVE_ENTRIES) {
            throw ValidationException::withMessages(['file' => 'Arsip berisi terlalu banyak file.']);
        }

        $entries = [];
        $totalBytes = 0;
        for ($index = 0; $index < $zip->numFiles; $index++) {
            $stat = $zip->statIndex($index);
            $name = str_replace('\\', '/', (string) ($stat['name'] ?? ''));
            $parts = array_values(array_filter(explode('/', $name), fn ($part) => $part !== ''));
            if (str_starts_with($name, '/') || preg_match('/^[A-Za-z]:/', $name)
                || in_array('..', $parts, true) || in_array('.', $parts, true)) {
                throw ValidationException::withMessages(['file' => 'Arsip berisi jalur file yang tidak aman.']);
            }
            if ($name === '' || str_ends_with($name, '/')) {
                continue;
            }

            $totalBytes += (int) ($stat['size'] ?? 0);
            if ($totalBytes > self::MAX_UNCOMPRESSED_BYTES) {
                throw ValidationException::withMessages(['file' => 'Ukuran total isi arsip melebihi 512 MB.']);
            }

            $normalized = strtolower($name);
            if (isset($entries[$normalized])) {
                throw ValidationException::withMessages(['file' => 'Arsip berisi jalur file duplikat.']);
            }
            $entries[$normalized] = $name;
        }

        return $entries;
    }

    private function parseRows(string $csv): array
    {
        $stream = fopen('php://temp', 'r+');
        fwrite($stream, $csv);
        rewind($stream);
        $headers = fgetcsv($stream);
        if (!$headers) {
            fclose($stream);
            throw ValidationException::withMessages(['file' => 'books.csv tidak memiliki header.']);
        }

        $headers = array_map(
            fn ($header) => strtolower(trim(preg_replace('/^\xEF\xBB\xBF/', '', (string) $header))),
            $headers
        );
        if (count($headers) !== count(array_unique($headers))) {
            fclose($stream);
            throw ValidationException::withMessages(['file' => 'Header books.csv mengandung kolom duplikat.']);
        }
        foreach (['title', 'author'] as $required) {
            if (!in_array($required, $headers, true)) {
                fclose($stream);
                throw ValidationException::withMessages(['file' => "Kolom {$required} wajib tersedia di books.csv."]);
            }
        }

        $rows = [];
        while (($values = fgetcsv($stream)) !== false) {
            if (count(array_filter($values, fn ($value) => trim((string) $value) !== '')) === 0) {
                continue;
            }
            $values = array_slice(array_pad($values, count($headers), null), 0, count($headers));
            $row = array_combine($headers, $values);
            if (trim((string) ($row['title'] ?? '')) === '' || trim((string) ($row['author'] ?? '')) === '') {
                fclose($stream);
                throw ValidationException::withMessages(['file' => 'Setiap baris buku wajib memiliki title dan author.']);
            }
            $rows[] = $row;
        }
        fclose($stream);

        return $rows;
    }

    private function importAsset(
        ZipArchive $zip,
        array $entries,
        string $sourcePath,
        string $folder,
        array $allowedExtensions,
        array &$warnings,
        int $rowNumber,
        array &$createdPaths
    ): ?string {
        $sourcePath = trim(str_replace('\\', '/', $sourcePath));
        if ($sourcePath === '') {
            return null;
        }

        $relative = preg_replace('#^(?:\./)?storage/uploads/#i', '', $sourcePath);
        $relative = ltrim((string) $relative, '/');
        $parts = explode('/', $relative);
        $filename = end($parts);
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        if (count($parts) < 2 || $parts[0] !== $folder || $filename === ''
            || in_array('..', $parts, true) || !in_array($extension, $allowedExtensions, true)) {
            $warnings[] = "Baris {$rowNumber}: jalur {$folder} tidak valid dan tidak diimpor.";
            return null;
        }

        $archivePath = null;
        foreach ([$relative, 'storage/uploads/' . $relative] as $candidate) {
            if (isset($entries[strtolower($candidate)])) {
                $archivePath = $entries[strtolower($candidate)];
                break;
            }
        }
        if (!$archivePath) {
            $warnings[] = "Baris {$rowNumber}: file {$filename} tidak ditemukan di arsip.";
            return null;
        }

        $maxBytes = $folder === 'books' ? 100 * 1024 * 1024 : 10 * 1024 * 1024;
        $contents = $this->readEntry($zip, $archivePath, $maxBytes);
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($contents);
        $allowedMimes = $folder === 'books'
            ? ['application/pdf']
            : ['image/jpeg', 'image/png', 'image/webp'];
        if (!in_array($mime, $allowedMimes, true)) {
            $warnings[] = "Baris {$rowNumber}: tipe file {$filename} tidak sesuai dan tidak diimpor.";
            return null;
        }

        $newPath = 'digilib/' . $folder . '/legacy-' . md5($relative . ':' . hash('sha256', $contents)) . '.' . $extension;
        $disk = $folder === 'books' ? 'local' : 'public';
        if (!Storage::disk($disk)->exists($newPath)) {
            if (!Storage::disk($disk)->put($newPath, $contents)) {
                throw new \RuntimeException("Gagal menyimpan file hasil impor: {$filename}");
            }
            $createdPaths[] = [$disk, $newPath];
        }

        return $newPath;
    }

    private function readEntry(ZipArchive $zip, string $path, int $maxBytes): string
    {
        $index = $zip->locateName($path);
        if ($index === false) {
            throw ValidationException::withMessages(['file' => 'File penting dalam arsip tidak ditemukan.']);
        }
        $stat = $zip->statIndex($index);
        if ((int) ($stat['size'] ?? 0) > $maxBytes) {
            throw ValidationException::withMessages(['file' => 'File di dalam arsip terlalu besar.']);
        }
        $stream = $zip->getStream($path);
        if (!$stream) {
            throw ValidationException::withMessages(['file' => 'File di dalam arsip tidak dapat dibaca.']);
        }
        $contents = stream_get_contents($stream);
        fclose($stream);
        if ($contents === false) {
            throw ValidationException::withMessages(['file' => 'File di dalam arsip gagal dibaca.']);
        }

        return $contents;
    }

    private function nullableString(mixed $value): ?string
    {
        $value = trim((string) $value);
        return $value === '' ? null : $value;
    }

    private function integerValue(mixed $value): ?int
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }
        if (!is_numeric($value) || (int) $value < 0) {
            return null;
        }

        return (int) $value;
    }
}
