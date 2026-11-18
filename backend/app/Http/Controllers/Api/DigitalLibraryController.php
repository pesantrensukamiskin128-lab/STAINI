<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AppNotification;
use App\Models\LibraryBook;
use App\Models\LibraryLoan;
use App\Models\LibraryRepositoryItem;
use App\Models\LibraryReservation;
use App\Services\LibraryBookImportService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DigitalLibraryController extends Controller
{
    private const DEFAULT_SETTINGS = [
        'library_name' => 'Perpustakaan Digital',
        'tagline' => 'Temukan ilmu. Tumbuhkan masa depan.',
        'loan_days' => '14',
        'loan_limit' => '3',
        'fine_per_day' => '1000',
        'repository_max_mb' => '25',
        'reservation_hold_hours' => '48',
    ];

    public function catalog(Request $request): JsonResponse
    {
        $perPage = min(max((int) $request->input('per_page', 12), 1), 50);
        $query = $this->catalogQuery($request);
        $books = $query->orderByDesc('created_at')->paginate($perPage);
        $member = Auth::guard('sanctum')->user();
        $favoriteIds = $member
            ? DB::table('library_favorites')->where('user_id', $member->id)->pluck('book_id')->map(fn ($id) => (int) $id)
            : collect();

        return response()->json([
            'data' => $books->getCollection()->map(fn (LibraryBook $book) => $this->bookPayload($book, $favoriteIds->contains($book->id))),
            'total' => $books->total(),
            'current_page' => $books->currentPage(),
            'last_page' => $books->lastPage(),
            'filters' => [
                'categories' => LibraryBook::where('status', 'published')->whereNotNull('category')->distinct()->orderBy('category')->pluck('category'),
                'years' => LibraryBook::where('status', 'published')->whereNotNull('publish_year')->distinct()->orderByDesc('publish_year')->pluck('publish_year'),
                'programs' => DB::table('study_programs')->join('library_books', 'library_books.study_program_id', '=', 'study_programs.id')
                    ->where('library_books.status', 'published')->select('study_programs.id', 'study_programs.code', 'study_programs.name')
                    ->distinct()->orderBy('study_programs.name')->get(),
            ],
        ]);
    }

    public function showBook(LibraryBook $book): JsonResponse
    {
        abort_unless($book->status === 'published', 404);
        $book->increment('view_count');
        $book->load('studyProgram:id,code,name');
        $member = Auth::guard('sanctum')->user();
        $favorite = $member
            && DB::table('library_favorites')->where('user_id', $member->id)->where('book_id', $book->id)->exists();

        return response()->json($this->bookPayload($book, $favorite));
    }

    public function downloadBook(LibraryBook $book): StreamedResponse|JsonResponse
    {
        abort_unless($book->status === 'published' && $book->digital_path, 404);
        $member = Auth::guard('sanctum')->user();
        if (!$member) {
            return response()->json(['message' => 'Login diperlukan untuk mengakses koleksi digital.'], 401);
        }
        abort_unless(Storage::disk('local')->exists($book->digital_path), 404, 'File digital tidak ditemukan.');

        return Storage::disk('local')->download($book->digital_path, Str::slug($book->title) . '.pdf');
    }

    public function dashboard(): JsonResponse
    {
        return response()->json([
            'books' => LibraryBook::where('status', 'published')->count(),
            'physical_copies' => LibraryBook::whereIn('access_type', ['physical', 'both'])->sum('total_copies'),
            'active_loans' => LibraryLoan::whereIn('status', ['borrowed', 'overdue'])->count(),
            'overdue_loans' => LibraryLoan::where('status', 'overdue')->count(),
            'pending_reservations' => LibraryReservation::whereIn('status', ['waiting', 'ready'])->count(),
            'repository_items' => LibraryRepositoryItem::where('status', 'published')->count(),
            'pending_repository' => LibraryRepositoryItem::whereIn('status', ['submitted', 'revision'])->count(),
            'procurement_requests' => DB::table('library_procurement_requests')->whereIn('status', ['submitted', 'reviewed'])->count(),
            'today_visits' => DB::table('library_visits')->whereDate('visit_date', today())->count(),
            'announcements' => DB::table('library_announcements')->where('is_published', true)
                ->where(fn ($query) => $query->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
                ->where(fn ($query) => $query->whereNull('ends_at')->orWhere('ends_at', '>=', now()))
                ->orderByDesc('created_at')->limit(5)->get(),
        ]);
    }

    public function books(Request $request): JsonResponse
    {
        $perPage = min(max((int) $request->input('per_page', 15), 1), 100);
        $query = LibraryBook::with('studyProgram:id,code,name')
            ->when($request->filled('search'), function (Builder $query) use ($request) {
                $term = '%' . addcslashes(trim($request->string('search')->toString()), '%_\\') . '%';
                $query->where(fn (Builder $query) => $query
                    ->where('title', 'like', $term)->orWhere('author', 'like', $term)
                    ->orWhere('isbn', 'like', $term)->orWhere('barcode', 'like', $term));
            })
            ->when($request->filled('status'), fn (Builder $query) => $query->where('status', $request->string('status')))
            ->orderByDesc('created_at');

        return response()->json($query->paginate($perPage));
    }

    public function storeBook(Request $request): JsonResponse
    {
        $book = LibraryBook::create($this->validatedBook($request) + ['created_by' => auth()->id()]);
        $this->log('book_created', 'book', $book->id, 'Menambahkan buku ' . $book->title);

        return response()->json(['message' => 'Buku berhasil ditambahkan.', 'data' => $book->load('studyProgram')], 201);
    }

    public function updateBook(Request $request, LibraryBook $book): JsonResponse
    {
        $validated = $this->validatedBook($request, $book);
        $activeLoans = $book->loans()->whereIn('status', ['borrowed', 'overdue'])->count();
        if ($validated['total_copies'] < $validated['available_copies'] + $activeLoans) {
            throw ValidationException::withMessages([
                'total_copies' => 'Total eksemplar tidak boleh lebih kecil dari eksemplar tersedia dan pinjaman aktif.',
            ]);
        }
        $book->update($validated);
        $this->log('book_updated', 'book', $book->id, 'Memperbarui buku ' . $book->title);

        return response()->json(['message' => 'Buku berhasil diperbarui.', 'data' => $book->fresh()->load('studyProgram')]);
    }

    public function destroyBook(LibraryBook $book): JsonResponse
    {
        if ($book->loans()->whereIn('status', ['borrowed', 'overdue'])->exists()) {
            throw ValidationException::withMessages(['book' => 'Buku masih memiliki pinjaman aktif.']);
        }
        $book->delete();
        foreach ([[$book->cover_path, 'public'], [$book->digital_path, 'local']] as [$path, $disk]) {
            if ($path && Storage::disk($disk)->exists($path)) {
                Storage::disk($disk)->delete($path);
            }
        }
        $this->log('book_deleted', 'book', $book->id, 'Menghapus buku ' . $book->title);

        return response()->json(['message' => 'Buku berhasil dihapus.']);
    }

    public function uploadBookFiles(Request $request, LibraryBook $book): JsonResponse
    {
        $request->validate([
            'cover' => 'nullable|image|mimes:jpg,jpeg,png,webp|max:5120',
            'digital_file' => 'nullable|file|mimes:pdf|max:102400',
        ]);
        $updates = [];
        foreach ([
            'cover' => ['cover_path', 'digilib/covers', 'public'],
            'digital_file' => ['digital_path', 'digilib/books', 'local'],
        ] as $field => [$column, $directory, $disk]) {
            if (!$request->hasFile($field)) {
                continue;
            }
            $newPath = $request->file($field)->store($directory, $disk);
            $oldPath = $book->{$column};
            $updates[$column] = $newPath;
            if ($oldPath && Storage::disk($disk)->exists($oldPath)) {
                Storage::disk($disk)->delete($oldPath);
            }
        }
        if ($updates) {
            $book->update($updates);
        }

        return response()->json(['message' => 'File buku berhasil diperbarui.', 'data' => $book->fresh()]);
    }

    public function importBooks(Request $request, LibraryBookImportService $importer): JsonResponse
    {
        $request->validate(['file' => 'required|file|mimes:zip|max:262144']);
        $result = $importer->import($request->file('file'), (int) auth()->id());
        $this->log('books_imported', 'book', null, "Impor buku: {$result['imported']} ditambahkan, {$result['skipped']} dilewati.");

        return response()->json([
            'message' => "{$result['imported']} buku diimpor; {$result['skipped']} duplikat dilewati.",
            ...$result,
        ]);
    }

    public function importTemplate(): StreamedResponse
    {
        $headers = [
            'id', 'accession_no', 'barcode', 'title', 'subtitle', 'author', 'isbn',
            'publisher', 'publish_year', 'language', 'category', 'program_code',
            'classification', 'shelf', 'description', 'cover_path', 'digital_path',
            'access_type', 'total_copies', 'available_copies', 'view_count', 'status',
        ];
        $example = [
            '1', 'AJL-2026-0001', '899000000001', 'Contoh Judul Buku', '', 'Nama Penulis',
            '978-623-000-000-0', 'Penerbit', '2026', 'Indonesia', 'Pendidikan', 'PAI',
            '297.77', 'A-01', 'Deskripsi buku', 'storage/uploads/covers/sampul.jpg',
            'storage/uploads/books/ebook.pdf', 'both', '5', '4', '0', 'published',
        ];

        return response()->streamDownload(function () use ($headers, $example) {
            $stream = fopen('php://output', 'w');
            fwrite($stream, "\xEF\xBB\xBF");
            fputcsv($stream, $headers);
            fputcsv($stream, $example);
            fclose($stream);
        }, 'template-impor-buku-digilib.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function myLoans(): JsonResponse
    {
        $this->refreshOverdueLoans(auth()->id());

        return response()->json(LibraryLoan::with('book:id,title,author,isbn,cover_path')
            ->where('user_id', auth()->id())->orderByDesc('borrowed_at')->paginate(20));
    }

    public function allLoans(Request $request): JsonResponse
    {
        $this->refreshOverdueLoans();
        $query = LibraryLoan::with(['book:id,title,author,barcode', 'user:id,name,email,username', 'processedBy:id,name'])
            ->when($request->filled('status'), fn (Builder $query) => $query->where('status', $request->string('status')))
            ->when($request->filled('search'), function (Builder $query) use ($request) {
                $term = '%' . addcslashes(trim($request->string('search')->toString()), '%_\\') . '%';
                $query->where(fn (Builder $query) => $query->whereHas('book', fn ($book) => $book->where('title', 'like', $term))
                    ->orWhereHas('user', fn ($user) => $user->where('name', 'like', $term)->orWhere('email', 'like', $term)));
            });

        return response()->json($query->orderByDesc('borrowed_at')->paginate(30));
    }

    public function checkout(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'book_id' => 'required|integer|exists:library_books,id',
            'user_id' => 'required|integer|exists:users,id',
            'notes' => 'nullable|string|max:255',
        ]);
        $this->refreshReservations((int) $validated['book_id']);

        $loan = DB::transaction(function () use ($validated) {
            $book = LibraryBook::whereKey($validated['book_id'])->lockForUpdate()->firstOrFail();
            $user = DB::table('users')->where('id', $validated['user_id'])->lockForUpdate()->first();
            if (!$user || !$user->is_active) {
                throw ValidationException::withMessages(['user_id' => 'Akun anggota tidak aktif.']);
            }
            if (!in_array($book->access_type, ['physical', 'both'], true) || $book->available_copies < 1 || $book->status !== 'published') {
                throw ValidationException::withMessages(['book_id' => 'Buku fisik sedang tidak tersedia untuk dipinjam.']);
            }
            $readyReservation = LibraryReservation::where('book_id', $book->id)->where('status', 'ready')->orderBy('queue_no')->first();
            if ($readyReservation) {
                throw ValidationException::withMessages(['book_id' => 'Eksemplar sedang ditahan untuk reservasi; proses reservasi melalui antrean.']);
            }
            $settings = $this->librarySettings();
            $activeLoans = LibraryLoan::where('user_id', $user->id)->whereIn('status', ['borrowed', 'overdue'])->count();
            if ($activeLoans >= (int) $settings['loan_limit']) {
                throw ValidationException::withMessages(['user_id' => 'Anggota telah mencapai batas maksimal pinjaman aktif.']);
            }
            if (LibraryLoan::where('book_id', $book->id)->where('user_id', $user->id)->whereIn('status', ['borrowed', 'overdue'])->exists()) {
                throw ValidationException::withMessages(['book_id' => 'Anggota masih meminjam buku ini.']);
            }

            $book->decrement('available_copies');
            $loan = LibraryLoan::create([
                'loan_code' => 'DL-' . now()->format('ymd') . '-' . strtoupper(Str::random(6)),
                'user_id' => $user->id,
                'book_id' => $book->id,
                'borrowed_at' => now(),
                'due_at' => now()->addDays((int) $settings['loan_days']),
                'status' => 'borrowed',
                'notes' => $validated['notes'] ?? null,
                'processed_by' => auth()->id(),
            ]);
            $this->log('loan_created', 'loan', $loan->id, 'Mencatat peminjaman ' . $loan->loan_code);

            return $loan;
        });
        $loan->loadMissing('book');
        AppNotification::send(
            (int) $loan->user_id,
            'Peminjaman Buku Dicatat',
            "Peminjaman \"{$loan->book?->title}\" berhasil dicatat hingga {$loan->due_at?->format('d/m/Y')}.",
            'success',
            '/perpustakaan/akun'
        );

        return response()->json(['message' => 'Peminjaman berhasil dicatat.', 'data' => $loan->load(['book', 'user'])], 201);
    }

    public function renewLoan(LibraryLoan $loan): JsonResponse
    {
        abort_unless($loan->user_id === auth()->id(), 403);
        if (!in_array($loan->status, ['borrowed', 'overdue'], true) || $loan->renewal_count >= 1) {
            throw ValidationException::withMessages(['loan' => 'Pinjaman tidak dapat diperpanjang lagi.']);
        }
        if ((float) $loan->fine_amount > (float) $loan->fine_paid) {
            throw ValidationException::withMessages(['loan' => 'Lunasi denda terlebih dahulu sebelum memperpanjang pinjaman.']);
        }
        if (LibraryReservation::where('book_id', $loan->book_id)->where('user_id', '!=', $loan->user_id)->whereIn('status', ['waiting', 'ready'])->exists()) {
            throw ValidationException::withMessages(['loan' => 'Buku telah dipesan oleh anggota lain.']);
        }

        $loan->update([
            'due_at' => $loan->due_at->copy()->addDays((int) $this->librarySettings()['loan_days']),
            'renewal_count' => $loan->renewal_count + 1,
            'status' => 'borrowed',
        ]);
        $this->log('loan_renewed', 'loan', $loan->id, 'Memperpanjang pinjaman ' . $loan->loan_code);
        $loan->loadMissing('book');
        AppNotification::send(
            (int) $loan->user_id,
            'Peminjaman Diperpanjang',
            "Batas pengembalian \"{$loan->book?->title}\" diperpanjang hingga {$loan->due_at->format('d/m/Y')}.",
            'info',
            '/perpustakaan/akun'
        );

        return response()->json(['message' => 'Pinjaman berhasil diperpanjang.', 'data' => $loan->fresh()]);
    }

    public function returnLoan(LibraryLoan $loan): JsonResponse
    {
        DB::transaction(function () use ($loan) {
            $loan = LibraryLoan::whereKey($loan->id)->lockForUpdate()->firstOrFail();
            if (!in_array($loan->status, ['borrowed', 'overdue'], true)) {
                throw ValidationException::withMessages(['loan' => 'Pinjaman ini sudah ditutup.']);
            }
            $book = LibraryBook::whereKey($loan->book_id)->lockForUpdate()->firstOrFail();
            $lateDays = max(0, now()->startOfDay()->diffInDays($loan->due_at->copy()->startOfDay(), false) * -1);
            $fine = $lateDays * (float) $this->librarySettings()['fine_per_day'];
            $loan->update(['status' => 'returned', 'returned_at' => now(), 'fine_amount' => $fine]);
            if ($book->available_copies >= $book->total_copies) {
                throw ValidationException::withMessages(['book' => 'Stok tersedia sudah mencapai total eksemplar; periksa kembali inventaris.']);
            }
            $book->increment('available_copies');
            $this->releaseNextReservation($loan->book_id);
            $this->log('loan_returned', 'loan', $loan->id, 'Mengembalikan pinjaman ' . $loan->loan_code);
        });

        $loan->refresh()->load(['book', 'user']);
        AppNotification::send(
            (int) $loan->user_id,
            'Buku Telah Dikembalikan',
            "Pengembalian \"{$loan->book?->title}\" telah dicatat.".((float) $loan->fine_amount > 0 ? ' Denda tercatat sebesar Rp '.number_format((float) $loan->fine_amount, 0, ',', '.').'.' : ''),
            (float) $loan->fine_amount > 0 ? 'warning' : 'success',
            '/perpustakaan/akun'
        );

        return response()->json(['message' => 'Pengembalian berhasil dicatat.', 'data' => $loan->fresh()->load(['book', 'user'])]);
    }

    public function markLost(LibraryLoan $loan): JsonResponse
    {
        DB::transaction(function () use ($loan) {
            $loan = LibraryLoan::whereKey($loan->id)->lockForUpdate()->firstOrFail();
            if (!in_array($loan->status, ['borrowed', 'overdue'], true)) {
                throw ValidationException::withMessages(['loan' => 'Status pinjaman tidak dapat diubah menjadi hilang.']);
            }
            $book = LibraryBook::whereKey($loan->book_id)->lockForUpdate()->firstOrFail();
            if ($book->total_copies < 1) {
                throw ValidationException::withMessages(['book' => 'Total eksemplar buku sudah nol.']);
            }
            $loan->update([
                'status' => 'lost',
                'fine_amount' => max((float) $loan->fine_amount, (float) $this->librarySettings()['fine_per_day']),
                'returned_at' => now(),
            ]);
            $book->decrement('total_copies');
            $this->releaseNextReservation($loan->book_id);
            $this->log('loan_lost', 'loan', $loan->id, 'Menandai pinjaman ' . $loan->loan_code . ' sebagai hilang.');
        });
        $loan->refresh()->load(['book', 'user']);
        AppNotification::send(
            (int) $loan->user_id,
            'Peminjaman Ditandai Hilang',
            "Peminjaman \"{$loan->book?->title}\" ditandai hilang. Silakan hubungi pustakawan untuk tindak lanjut.",
            'error',
            '/perpustakaan/akun'
        );

        return response()->json(['message' => 'Pinjaman ditandai hilang.', 'data' => $loan->fresh()]);
    }

    public function payFine(Request $request, LibraryLoan $loan): JsonResponse
    {
        $amount = round((float) $request->validate(['amount' => 'required|numeric|min:0.01'])['amount'], 2);
        DB::transaction(function () use ($loan, $amount) {
            $loan = LibraryLoan::whereKey($loan->id)->lockForUpdate()->firstOrFail();
            $unpaid = round((float) $loan->fine_amount - (float) $loan->fine_paid, 2);
            if ($amount > $unpaid) {
                throw ValidationException::withMessages(['amount' => 'Nominal pembayaran melebihi denda yang belum dibayar.']);
            }
            $loan->increment('fine_paid', $amount);
            $this->log('fine_paid', 'loan', $loan->id, 'Mencatat pembayaran denda ' . number_format($amount, 2, '.', ''));
        });
        $loan->refresh()->load(['book', 'user']);
        AppNotification::send(
            (int) $loan->user_id,
            'Pembayaran Denda Dicatat',
            'Pembayaran denda sebesar Rp '.number_format($amount, 0, ',', '.').' telah dicatat untuk peminjaman "'.$loan->book?->title.'".',
            'success',
            '/perpustakaan/akun'
        );

        return response()->json(['message' => 'Pembayaran denda berhasil dicatat.', 'data' => $loan->fresh()]);
    }

    public function myReservations(): JsonResponse
    {
        $this->refreshReservations();
        return response()->json(LibraryReservation::with('book:id,title,author,cover_path,available_copies')
            ->where('user_id', auth()->id())->whereIn('status', ['waiting', 'ready'])
            ->orderByDesc('created_at')->get());
    }

    public function reservations(Request $request): JsonResponse
    {
        $this->refreshReservations();
        $query = LibraryReservation::with(['book:id,title,author,available_copies', 'user:id,name,email'])
            ->when($request->filled('status'), fn (Builder $query) => $query->where('status', $request->string('status')));

        return response()->json($query->orderByDesc('created_at')->paginate(30));
    }

    public function reserve(LibraryBook $book): JsonResponse
    {
        $this->refreshReservations($book->id);
        $reservation = DB::transaction(function () use ($book) {
            $book = LibraryBook::whereKey($book->id)->lockForUpdate()->firstOrFail();
            if ($book->status !== 'published' || !in_array($book->access_type, ['physical', 'both'], true)) {
                throw ValidationException::withMessages(['book' => 'Buku ini tidak dapat direservasi.']);
            }
            if ($book->available_copies > 0) {
                throw ValidationException::withMessages(['book' => 'Buku masih tersedia; silakan hubungi pustakawan untuk peminjaman.']);
            }
            if (LibraryReservation::where('user_id', auth()->id())->where('book_id', $book->id)->whereIn('status', ['waiting', 'ready'])->exists()) {
                throw ValidationException::withMessages(['book' => 'Anda sudah memiliki reservasi aktif untuk buku ini.']);
            }
            $queue = LibraryReservation::where('book_id', $book->id)->whereIn('status', ['waiting', 'ready'])->max('queue_no') ?? 0;

            return LibraryReservation::create([
                'user_id' => auth()->id(),
                'book_id' => $book->id,
                'queue_no' => $queue + 1,
                'status' => 'waiting',
            ]);
        });
        $this->log('reservation_created', 'reservation', $reservation->id, 'Mereservasi buku ' . $book->title);
        AppNotification::send(
            (int) $reservation->user_id,
            'Reservasi Buku Dibuat',
            "Anda berada pada antrean {$reservation->queue_no} untuk buku \"{$book->title}\".",
            'info',
            '/perpustakaan/akun'
        );

        return response()->json(['message' => 'Reservasi berhasil dibuat.', 'data' => $reservation->load('book')], 201);
    }

    public function cancelReservation(LibraryReservation $reservation): JsonResponse
    {
        DB::transaction(function () use ($reservation) {
            $reservation = LibraryReservation::whereKey($reservation->id)->lockForUpdate()->firstOrFail();
            abort_unless($reservation->user_id === auth()->id(), 403);
            if (!in_array($reservation->status, ['waiting', 'ready'], true)) {
                throw ValidationException::withMessages(['reservation' => 'Reservasi ini sudah tidak aktif.']);
            }
            $reservation->update(['status' => 'cancelled']);
        });
        $this->releaseNextReservation($reservation->book_id);

        return response()->json(['message' => 'Reservasi dibatalkan.']);
    }

    public function collectReservation(LibraryReservation $reservation): JsonResponse
    {
        $this->refreshReservations($reservation->book_id);
        $canManage = auth()->user()?->can('digilib.circulation.manage') ?? false;
        abort_unless($canManage || $reservation->user_id === auth()->id(), 403);
        $loan = DB::transaction(function () use ($reservation) {
            $reservation = LibraryReservation::whereKey($reservation->id)->lockForUpdate()->firstOrFail();
            $book = LibraryBook::whereKey($reservation->book_id)->lockForUpdate()->firstOrFail();
            if ($reservation->status !== 'ready' || ($reservation->expires_at && $reservation->expires_at->isPast()) || $book->available_copies < 1) {
                throw ValidationException::withMessages(['reservation' => 'Koleksi reservasi tidak tersedia lagi.']);
            }
            $active = LibraryLoan::where('user_id', $reservation->user_id)->whereIn('status', ['borrowed', 'overdue'])->count();
            if ($active >= (int) $this->librarySettings()['loan_limit']) {
                throw ValidationException::withMessages(['reservation' => 'Anggota telah mencapai batas maksimal pinjaman aktif.']);
            }
            $book->decrement('available_copies');
            $reservation->update(['status' => 'collected']);

            return LibraryLoan::create([
                'loan_code' => 'DL-' . now()->format('ymd') . '-' . strtoupper(Str::random(6)),
                'user_id' => $reservation->user_id,
                'book_id' => $book->id,
                'borrowed_at' => now(),
                'due_at' => now()->addDays((int) $this->librarySettings()['loan_days']),
                'status' => 'borrowed',
                'processed_by' => auth()->id(),
            ]);
        });

        return response()->json(['message' => 'Reservasi dikonversi menjadi peminjaman.', 'data' => $loan->load(['book', 'user'])], 201);
    }

    public function favorites(): JsonResponse
    {
        $books = LibraryBook::with('studyProgram:id,code,name')
            ->whereHas('favoritedBy', fn (Builder $query) => $query->where('users.id', auth()->id()))
            ->orderByDesc('library_favorites.created_at')->paginate(20);

        return response()->json($books);
    }

    public function toggleFavorite(LibraryBook $book): JsonResponse
    {
        abort_unless($book->status === 'published', 404);
        $favorite = DB::table('library_favorites')->where('user_id', auth()->id())->where('book_id', $book->id)->exists();
        if ($favorite) {
            DB::table('library_favorites')->where('user_id', auth()->id())->where('book_id', $book->id)->delete();
        } else {
            DB::table('library_favorites')->insert(['user_id' => auth()->id(), 'book_id' => $book->id, 'created_at' => now(), 'updated_at' => now()]);
        }

        return response()->json(['favorited' => !$favorite]);
    }

    public function publicRepository(Request $request): JsonResponse
    {
        $items = LibraryRepositoryItem::with('studyProgram:id,code,name')->where('status', 'published')
            ->when($request->filled('search'), function (Builder $query) use ($request) {
                $term = '%' . addcslashes(trim($request->string('search')->toString()), '%_\\') . '%';
                $query->where(fn (Builder $query) => $query->where('title', 'like', $term)
                    ->orWhere('authors', 'like', $term)->orWhere('keywords', 'like', $term)->orWhere('abstract', 'like', $term));
            })
            ->when($request->filled('item_type'), fn (Builder $query) => $query->where('item_type', $request->string('item_type')))
            ->orderByDesc('published_at')->paginate(min(max((int) $request->input('per_page', 12), 1), 50));

        $items->getCollection()->transform(function (LibraryRepositoryItem $item) {
            $item->setAttribute('has_file', (bool) $item->file_path);
            $item->makeHidden('file_path');
            return $item;
        });

        return response()->json($items);
    }

    public function myRepositoryItems(): JsonResponse
    {
        return response()->json(LibraryRepositoryItem::where('submitted_by', auth()->id())->orderByDesc('created_at')->paginate(20));
    }

    public function submitRepositoryItem(Request $request): JsonResponse
    {
        $maxMb = (int) ($this->librarySettings()['repository_max_mb'] ?? 25);
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'authors' => 'required|string|max:500',
            'abstract' => 'nullable|string',
            'keywords' => 'nullable|string|max:500',
            'item_type' => ['required', Rule::in(['thesis', 'article', 'research', 'community_service', 'book', 'proceeding', 'module', 'policy', 'other'])],
            'study_program_id' => 'nullable|integer|exists:study_programs,id',
            'publication_year' => 'nullable|integer|min:1900|max:' . (now()->year + 1),
            'publisher' => 'nullable|string|max:200',
            'license_name' => 'nullable|string|max:100',
            'file' => "nullable|file|mimes:pdf|max:" . ($maxMb * 1024),
        ]);
        $path = $request->hasFile('file') ? $request->file('file')->store('digilib/repository', 'local') : null;
        $item = LibraryRepositoryItem::create([
            ...$validated,
            'identifier' => 'AJL-' . now()->format('Y') . '-' . strtoupper(Str::random(8)),
            'file_path' => $path,
            'file_size' => $request->file('file')?->getSize() ?? 0,
            'status' => 'submitted',
            'submitted_by' => auth()->id(),
        ]);
        $this->log('repository_submitted', 'repository', $item->id, 'Mengajukan karya ' . $item->title);
        foreach (\App\Models\User::permission('digilib.repository.manage')->where('is_active', true)->get(['id']) as $reviewer) {
            AppNotification::send(
                $reviewer->id,
                'Karya Repository Baru',
                "Karya \"{$item->title}\" diajukan untuk verifikasi.",
                'info',
                '/digilib/kelola'
            );
        }

        return response()->json(['message' => 'Karya berhasil diajukan untuk verifikasi.', 'data' => $item], 201);
    }

    public function manageRepository(Request $request): JsonResponse
    {
        $query = LibraryRepositoryItem::with(['studyProgram:id,code,name', 'submitter:id,name,email'])
            ->when($request->filled('status'), fn (Builder $query) => $query->where('status', $request->string('status')))
            ->when($request->filled('search'), fn (Builder $query) => $query->where('title', 'like', '%' . addcslashes(trim($request->string('search')->toString()), '%_\\') . '%'));

        return response()->json($query->orderByDesc('created_at')->paginate(30));
    }

    public function reviewRepository(Request $request, LibraryRepositoryItem $item): JsonResponse
    {
        $validated = $request->validate([
            'action' => ['required', Rule::in(['approve', 'revision', 'reject', 'publish'])],
            'review_note' => 'nullable|string|max:5000',
            'access_level' => ['nullable', Rule::in(['open', 'members', 'embargo', 'closed'])],
            'embargo_until' => 'nullable|date',
        ]);
        $status = match ($validated['action']) {
            'approve' => 'approved',
            'revision' => 'revision',
            'reject' => 'rejected',
            'publish' => 'published',
        };
        $item->update([
            'status' => $status,
            'review_note' => $validated['review_note'] ?? null,
            'reviewed_by' => auth()->id(),
            'access_level' => $validated['access_level'] ?? $item->access_level,
            'embargo_until' => $validated['embargo_until'] ?? $item->embargo_until,
            'published_at' => $status === 'published' ? now() : $item->published_at,
        ]);
        $this->log('repository_' . $status, 'repository', $item->id, 'Mengubah status karya ' . $item->title . ' menjadi ' . $status);
        if ($item->submitted_by) {
            AppNotification::send(
                (int) $item->submitted_by,
                'Status Karya Repository Diperbarui',
                "Status karya \"{$item->title}\" sekarang {$status}.".($validated['review_note'] ? ' Catatan: '.$validated['review_note'] : ''),
                $status === 'rejected' ? 'error' : ($status === 'revision' ? 'warning' : 'success'),
                '/perpustakaan/akun'
            );
        }

        return response()->json(['message' => 'Status repository berhasil diperbarui.', 'data' => $item->fresh()]);
    }

    public function downloadRepository(LibraryRepositoryItem $item): StreamedResponse|JsonResponse
    {
        $member = Auth::guard('sanctum')->user();
        $canManage = $member?->can('digilib.repository.manage') ?? false;
        $canBorrow = $member?->can('digilib.borrow') ?? false;
        if ($item->status !== 'published' && !$canManage) {
            abort(404);
        }
        $allowed = $item->access_level === 'open'
            || ($item->access_level === 'members' && $canBorrow)
            || ($item->access_level === 'embargo' && $item->embargo_until && $item->embargo_until->isPast())
            || $canManage;
        if (!$allowed) {
            return response()->json(['message' => $member ? 'Dokumen ini belum dapat diakses.' : 'Login diperlukan untuk mengakses dokumen ini.'], $member ? 403 : 401);
        }
        abort_unless($item->file_path && Storage::disk('local')->exists($item->file_path), 404);
        $item->increment('download_count');

        return Storage::disk('local')->download($item->file_path, Str::slug($item->title) . '.pdf');
    }

    public function submitProcurement(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'author' => 'nullable|string|max:255',
            'isbn' => 'nullable|string|max:30',
            'reason' => 'nullable|string|max:5000',
        ]);
        $id = DB::table('library_procurement_requests')->insertGetId([
            ...$validated,
            'requester_id' => auth()->id(),
            'status' => 'submitted',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        foreach (\App\Models\User::permission('digilib.procurement.manage')->where('is_active', true)->get(['id']) as $reviewer) {
            AppNotification::send(
                $reviewer->id,
                'Usulan Pengadaan Buku Baru',
                auth()->user()->name.' mengusulkan pengadaan "'.$validated['title'].'".',
                'info',
                '/digilib/kelola'
            );
        }

        return response()->json(['message' => 'Usulan pengadaan berhasil dikirim.', 'data' => DB::table('library_procurement_requests')->find($id)], 201);
    }

    public function procurementRequests(Request $request): JsonResponse
    {
        $query = DB::table('library_procurement_requests')->join('users', 'users.id', '=', 'library_procurement_requests.requester_id')
            ->select('library_procurement_requests.*', 'users.name as requester_name', 'users.email as requester_email')
            ->when($request->filled('status'), fn ($query) => $query->where('library_procurement_requests.status', $request->string('status')));

        return response()->json($query->orderByDesc('library_procurement_requests.created_at')->paginate(30));
    }

    public function reviewProcurement(Request $request, int $requestId): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['required', Rule::in(['reviewed', 'approved', 'purchased', 'rejected'])],
            'admin_note' => 'nullable|string|max:5000',
        ]);
        $updated = DB::table('library_procurement_requests')->where('id', $requestId)->update([
            ...$validated,
            'reviewed_by' => auth()->id(),
            'updated_at' => now(),
        ]);
        abort_unless($updated, 404);
        $procurementRequest = DB::table('library_procurement_requests')->find($requestId);
        if ($procurementRequest?->requester_id) {
            AppNotification::send(
                (int) $procurementRequest->requester_id,
                'Status Usulan Pengadaan Diperbarui',
                "Usulan pengadaan \"{$procurementRequest->title}\" sekarang berstatus {$validated['status']}.".($validated['admin_note'] ? ' Catatan: '.$validated['admin_note'] : ''),
                $validated['status'] === 'rejected' ? 'error' : 'info',
                '/perpustakaan/akun'
            );
        }

        return response()->json(['message' => 'Status usulan pengadaan diperbarui.']);
    }

    public function settings(): JsonResponse
    {
        return response()->json($this->librarySettings());
    }

    public function updateSettings(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'library_name' => 'required|string|max:255',
            'tagline' => 'nullable|string|max:255',
            'loan_days' => 'required|integer|min:1|max:90',
            'loan_limit' => 'required|integer|min:1|max:20',
            'fine_per_day' => 'required|numeric|min:0|max:1000000',
            'repository_max_mb' => 'required|integer|min:1|max:100',
            'reservation_hold_hours' => 'required|integer|min:1|max:168',
        ]);
        foreach ($validated as $key => $value) {
            DB::table('library_settings')->updateOrInsert(
                ['setting_key' => $key],
                ['setting_value' => (string) $value, 'updated_at' => now(), 'created_at' => now()]
            );
        }

        return response()->json(['message' => 'Pengaturan perpustakaan berhasil disimpan.', 'data' => $this->librarySettings()]);
    }

    public function reports(Request $request): JsonResponse
    {
        $year = (int) $request->input('year', now()->year);
        return response()->json([
            'year' => $year,
            'books' => [
                'total' => LibraryBook::count(),
                'published' => LibraryBook::where('status', 'published')->count(),
                'views' => LibraryBook::sum('view_count'),
            ],
            'circulation' => [
                'borrowed' => LibraryLoan::whereYear('borrowed_at', $year)->count(),
                'returned' => LibraryLoan::where('status', 'returned')->whereYear('returned_at', $year)->count(),
                'overdue' => LibraryLoan::whereIn('status', ['borrowed', 'overdue'])->where('due_at', '<', now())->count(),
                'fine_assessed' => LibraryLoan::whereYear('borrowed_at', $year)->sum('fine_amount'),
                'fine_paid' => LibraryLoan::whereYear('borrowed_at', $year)->sum('fine_paid'),
            ],
            'repository' => [
                'published' => LibraryRepositoryItem::where('status', 'published')->count(),
                'submitted' => LibraryRepositoryItem::whereYear('created_at', $year)->count(),
                'downloads' => LibraryRepositoryItem::sum('download_count'),
            ],
            'visits' => DB::table('library_visits')->whereYear('visit_date', $year)->count(),
            'top_books' => LibraryBook::orderByDesc('view_count')->limit(10)->get(['id', 'title', 'author', 'view_count']),
            'loans_by_month' => LibraryLoan::selectRaw('MONTH(borrowed_at) as month, COUNT(*) as total')
                ->whereYear('borrowed_at', $year)->groupByRaw('MONTH(borrowed_at)')->orderBy('month')->get(),
        ]);
    }

    public function visits(): JsonResponse
    {
        return response()->json(DB::table('library_visits')->leftJoin('users', 'users.id', '=', 'library_visits.user_id')
            ->select('library_visits.*', 'users.name as member_name', 'users.email as member_email')
            ->whereDate('visit_date', '>=', now()->subDays(30))->orderByDesc('check_in_at')->paginate(30));
    }

    public function recordVisit(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'user_id' => 'nullable|integer|exists:users,id',
            'visitor_name' => 'nullable|string|max:150',
            'purpose' => 'nullable|string|max:255',
        ]);
        if (empty($validated['user_id']) && empty($validated['visitor_name'])) {
            throw ValidationException::withMessages(['visitor_name' => 'Pilih akun anggota atau isi nama pengunjung.']);
        }
        $id = DB::table('library_visits')->insertGetId([
            ...$validated,
            'visit_date' => today(),
            'check_in_at' => now(),
            'recorded_by' => auth()->id(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json(['message' => 'Kunjungan berhasil dicatat.', 'data' => DB::table('library_visits')->find($id)], 201);
    }

    public function closeVisit(int $visitId): JsonResponse
    {
        $updated = DB::table('library_visits')->where('id', $visitId)->whereNull('check_out_at')->update([
            'check_out_at' => now(),
            'updated_at' => now(),
        ]);
        abort_unless($updated, 404);

        return response()->json(['message' => 'Kunjungan ditutup.']);
    }

    public function publicAnnouncements(): JsonResponse
    {
        return response()->json(DB::table('library_announcements')->where('is_published', true)
            ->where(fn ($query) => $query->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
            ->where(fn ($query) => $query->whereNull('ends_at')->orWhere('ends_at', '>=', now()))
            ->orderByDesc('created_at')->limit(20)->get());
    }

    public function announcements(): JsonResponse
    {
        return response()->json(DB::table('library_announcements')->orderByDesc('created_at')->paginate(30));
    }

    public function storeAnnouncement(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'title' => 'required|string|max:200',
            'body' => 'required|string|max:10000',
            'starts_at' => 'nullable|date',
            'ends_at' => 'nullable|date|after_or_equal:starts_at',
            'is_published' => 'boolean',
        ]);
        $id = DB::table('library_announcements')->insertGetId([
            ...$validated,
            'created_by' => auth()->id(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json(['message' => 'Pengumuman berhasil disimpan.', 'data' => DB::table('library_announcements')->find($id)], 201);
    }

    private function catalogQuery(Request $request): Builder
    {
        return LibraryBook::with('studyProgram:id,code,name')->where('status', 'published')
            ->when($request->filled('search'), function (Builder $query) use ($request) {
                $term = '%' . addcslashes(trim($request->string('search')->toString()), '%_\\') . '%';
                $query->where(fn (Builder $query) => $query->where('title', 'like', $term)->orWhere('author', 'like', $term)
                    ->orWhere('isbn', 'like', $term)->orWhere('description', 'like', $term));
            })
            ->when($request->filled('category'), fn (Builder $query) => $query->where('category', $request->string('category')))
            ->when($request->filled('year'), fn (Builder $query) => $query->where('publish_year', $request->integer('year')))
            ->when($request->filled('access_type'), fn (Builder $query) => $query->where('access_type', $request->string('access_type')))
            ->when($request->filled('study_program_id'), fn (Builder $query) => $query->where('study_program_id', $request->integer('study_program_id')));
    }

    private function bookPayload(LibraryBook $book, bool $favorite): array
    {
        $payload = $book->toArray();
        unset($payload['cover_path'], $payload['digital_path']);

        return [
            ...$payload,
            'cover_url' => $book->cover_path ? Storage::disk('public')->url($book->cover_path) : null,
            'has_digital_file' => (bool) $book->digital_path && Storage::disk('local')->exists($book->digital_path),
            'is_favorite' => $favorite,
            'study_program' => $book->studyProgram,
        ];
    }

    private function validatedBook(Request $request, ?LibraryBook $book = null): array
    {
        $validated = $request->validate([
            'accession_no' => ['nullable', 'string', 'max:60', Rule::unique('library_books')->ignore($book?->id)],
            'barcode' => ['nullable', 'string', 'max:80', Rule::unique('library_books')->ignore($book?->id)],
            'title' => 'required|string|max:255',
            'subtitle' => 'nullable|string|max:255',
            'author' => 'required|string|max:255',
            'isbn' => 'nullable|string|max:30',
            'publisher' => 'nullable|string|max:150',
            'publish_year' => 'nullable|integer|min:1000|max:' . (now()->year + 1),
            'language' => 'nullable|string|max:50',
            'category' => 'nullable|string|max:120',
            'study_program_id' => 'nullable|integer|exists:study_programs,id',
            'classification' => 'nullable|string|max:60',
            'shelf' => 'nullable|string|max:60',
            'description' => 'nullable|string|max:5000',
            'access_type' => ['required', Rule::in(['physical', 'digital', 'both'])],
            'total_copies' => 'required|integer|min:0|max:100000',
            'available_copies' => 'required|integer|min:0|lte:total_copies',
            'status' => ['required', Rule::in(['published', 'draft', 'archived'])],
        ]);

        return $validated;
    }

    private function librarySettings(): array
    {
        return array_merge(
            self::DEFAULT_SETTINGS,
            DB::table('library_settings')->pluck('setting_value', 'setting_key')->all()
        );
    }

    private function refreshOverdueLoans(?int $userId = null): void
    {
        LibraryLoan::whereIn('status', ['borrowed', 'overdue'])
            ->where('due_at', '<', now())
            ->when($userId, fn (Builder $query) => $query->where('user_id', $userId))
            ->update(['status' => 'overdue']);
    }

    private function releaseNextReservation(int $bookId): void
    {
        $this->refreshReservations($bookId);
    }

    private function refreshReservations(?int $bookId = null): void
    {
        $bookIds = $bookId
            ? collect([$bookId])
            : LibraryReservation::whereIn('status', ['waiting', 'ready'])->distinct()->pluck('book_id');

        foreach ($bookIds as $id) {
            $readyReservations = DB::transaction(function () use ($id) {
                $book = LibraryBook::whereKey($id)->lockForUpdate()->first();
                if (!$book) {
                    return collect();
                }
                LibraryReservation::where('book_id', $id)->where('status', 'ready')->whereNotNull('expires_at')
                    ->where('expires_at', '<', now())->update(['status' => 'expired']);

                $readyCount = LibraryReservation::where('book_id', $id)->where('status', 'ready')->count();
                $availableSlots = max(0, (int) $book->available_copies - $readyCount);
                $waiting = LibraryReservation::where('book_id', $id)->where('status', 'waiting')
                    ->orderBy('queue_no')->limit($availableSlots)->lockForUpdate()->get();
                foreach ($waiting as $reservation) {
                    $reservation->update([
                        'status' => 'ready',
                        'expires_at' => now()->addHours((int) $this->librarySettings()['reservation_hold_hours']),
                    ]);
                }

                return $waiting->map(fn (LibraryReservation $reservation) => [
                    'user_id' => (int) $reservation->user_id,
                    'title' => $book->title,
                    'expires_at' => $reservation->expires_at,
                ]);
            });

            DB::afterCommit(function () use ($readyReservations): void {
                foreach ($readyReservations as $reservation) {
                    AppNotification::send(
                        $reservation['user_id'],
                        'Buku Reservasi Siap Diambil',
                        "Buku \"{$reservation['title']}\" kini tersedia. Silakan ambil sebelum ".$reservation['expires_at']->format('d/m/Y H:i').'.',
                        'success',
                        '/perpustakaan/akun'
                    );
                }
            });
        }
    }

    private function log(string $action, ?string $entityType = null, int|string|null $entityId = null, ?string $description = null): void
    {
        DB::table('library_activity_logs')->insert([
            'user_id' => auth()->id(),
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId === null ? null : (string) $entityId,
            'description' => $description,
            'ip_address' => request()->ip(),
            'user_agent' => substr((string) request()->userAgent(), 0, 255),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
