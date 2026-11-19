<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AppNotification;
use App\Models\FeeStructure;
use App\Models\FeeType;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Payment;
use App\Models\Scholarship;
use App\Models\Semester;
use App\Models\Student;
use App\Models\StudentScholarship;
use App\Services\FeeStructureResolver;
use App\Exports\PaymentExport;
use App\Exports\InvoiceExport;
use App\Exports\InvoiceTemplateExport;
use App\Imports\InvoiceImport;
use App\Exports\PaymentTemplateExport;
use App\Imports\PaymentImport;
use Maatwebsite\Excel\Facades\Excel;
use App\Services\ExitedStudentInvoiceService;
use App\Services\KrsEligibilityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Barryvdh\DomPDF\Facade\Pdf;

class FinanceController extends Controller
{
    public function __construct(
        private readonly FeeStructureResolver $feeStructures,
        private readonly KrsEligibilityService $krsEligibility,
    ) {}

    // =============================================
    // FEE TYPES
    // =============================================

    public function feeTypes(): JsonResponse
    {
        return response()->json(FeeType::orderBy('name')->get());
    }

    public function storeFeeType(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'code' => 'required|string|max:30|unique:fee_types',
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'is_active' => 'boolean',
            'is_mandatory' => 'boolean',
            'is_recurring' => 'boolean',
        ]);

        $feeType = FeeType::create($validated);

        return response()->json(['message' => 'Jenis tagihan berhasil ditambahkan.', 'data' => $feeType], 201);
    }

    public function updateFeeType(Request $request, FeeType $feeType): JsonResponse
    {
        $validated = $request->validate([
            'code' => "sometimes|string|max:30|unique:fee_types,code,{$feeType->id}",
            'name' => 'sometimes|string|max:255',
            'description' => 'nullable|string',
            'is_active' => 'boolean',
            'is_mandatory' => 'boolean',
            'is_recurring' => 'boolean',
        ]);

        $feeType->update($validated);

        return response()->json(['message' => 'Jenis tagihan berhasil diupdate.', 'data' => $feeType]);
    }

    public function destroyFeeType(FeeType $feeType): JsonResponse
    {
        if ($feeType->invoiceItems()->count() > 0) {
            return response()->json(['message' => 'Tidak dapat menghapus jenis tagihan yang sudah digunakan.'], 422);
        }
        $feeType->structures()->delete();
        $feeType->delete();

        return response()->json(['message' => 'Jenis tagihan berhasil dihapus.']);
    }

    // =============================================
    // FEE STRUCTURES
    // =============================================

    public function feeStructures(Request $request): JsonResponse
    {
        $data = FeeStructure::with(['feeType', 'studyProgram', 'academicYear'])
            ->when($request->fee_type_id, fn ($q) => $q->where('fee_type_id', $request->fee_type_id))
            ->when($request->study_program_id, fn ($q) => $q->where('study_program_id', $request->study_program_id))
            ->when($request->academic_year_id, fn ($q) => $q->where('academic_year_id', $request->academic_year_id))
            ->when($request->class_type, fn ($q) => $q->where('class_type', $request->class_type))
            ->paginate($request->per_page ?? 20);

        return response()->json($data);
    }

    public function storeFeeStructure(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'fee_type_id' => 'required|exists:fee_types,id',
            'study_program_id' => 'nullable|exists:study_programs,id',
            'academic_year_id' => 'nullable|exists:academic_years,id',
            'academic_level' => 'nullable|integer|min:1|max:14',
            'class_type' => ['nullable', Rule::in(Student::CLASS_TYPES)],
            'amount' => 'required|numeric|min:0',
            'note' => 'nullable|string',
        ]);

        $structure = FeeStructure::create($validated);

        return response()->json(['message' => 'Struktur biaya berhasil ditambahkan.', 'data' => $structure->load(['feeType', 'studyProgram', 'academicYear'])], 201);
    }

    public function updateFeeStructure(Request $request, FeeStructure $feeStructure): JsonResponse
    {
        $validated = $request->validate([
            'fee_type_id' => 'sometimes|exists:fee_types,id',
            'study_program_id' => 'nullable|exists:study_programs,id',
            'academic_year_id' => 'nullable|exists:academic_years,id',
            'academic_level' => 'nullable|integer|min:1|max:14',
            'class_type' => ['nullable', Rule::in(Student::CLASS_TYPES)],
            'amount' => 'sometimes|numeric|min:0',
            'note' => 'nullable|string',
        ]);

        $feeStructure->update($validated);

        return response()->json(['message' => 'Struktur biaya berhasil diupdate.', 'data' => $feeStructure->fresh(['feeType', 'studyProgram', 'academicYear'])]);
    }

    public function destroyFeeStructure(FeeStructure $feeStructure): JsonResponse
    {
        $feeStructure->delete();

        return response()->json(['message' => 'Struktur biaya berhasil dihapus.']);
    }

    // =============================================
    // INVOICES (TAGIHAN)
    // =============================================

    private function invoicesQuery(Request $request)
    {
        $user = auth()->user();
        $query = Invoice::with(['student.studyProgram', 'semester.academicYear', 'items.feeType'])
            ->when($request->student_id, fn ($q) => $q->where('student_id', $request->student_id))
            ->when($request->semester_id, fn ($q) => $q->where('semester_id', $request->semester_id))
            ->when($request->status, fn ($q) => $q->where('status', $request->status))
            ->when($request->search, fn ($q) => $q->whereHas('student', fn ($q2) => $q2->where('name', 'like', "%{$request->search}%")
                ->orWhere('nim', 'like', "%{$request->search}%")))
            ->when($request->study_program_id, fn ($q, $v) => $q->whereHas('student', fn ($s) => $s->where('study_program_id', $v)))
            ->when($request->class_type, fn ($q, $v) => $q->whereHas('student', fn ($s) => $s->where('class_type', $v)))
            ->when($request->fee_type_id, fn ($q, $v) => $q->whereHas('items', fn ($i) => $i->where('fee_type_id', $v)));

        // Mahasiswa hanya lihat tagihan sendiri
        if ($user->hasRole('MAHASISWA') && $user->student) {
            $query->where('student_id', $user->student->id);
        }

        return $query;
    }

    public function invoices(Request $request): JsonResponse
    {
        $query = $this->invoicesQuery($request)->orderByDesc('invoice_date')->orderByDesc('id');
        if ($request->boolean('active_only')) {
            return response()->json($query->whereIn('status', ['UNPAID', 'PARTIAL', 'OVERDUE'])->get());
        }

        return response()->json($query->paginate($request->per_page ?? 20));
    }

    public function exportInvoices(Request $request)
    {
        $invoices = $this->invoicesQuery($request)->with('payments')->orderByDesc('invoice_date')->orderByDesc('id')->get();

        return Excel::download(new InvoiceExport($invoices), 'tagihan-mahasiswa-'.now()->format('Ymd-His').'.xlsx');
    }

    public function invoiceImportTemplate()
    {
        return Excel::download(new InvoiceTemplateExport, 'template-import-tagihan.xlsx');
    }

    public function importInvoices(Request $request): JsonResponse
    {
        $request->validate([
            'file' => 'required|file|mimes:xlsx,xls,csv|max:5120',
            'preview' => 'nullable|boolean',
        ]);

        $preview = $request->boolean('preview');
        $import = new InvoiceImport($preview, auth()->id());
        Excel::import($import, $request->file('file'));

        $verb = $preview ? 'akan dibuat' : 'berhasil dibuat';

        return response()->json([
            'message' => "{$import->imported} tagihan {$verb}, {$import->skippedPaid} dilewati karena sudah membayar, ".count($import->errors).' baris gagal.',
            'preview' => $preview,
            'imported' => $import->imported,
            'skipped_paid' => $import->skippedPaid,
            'errors' => $import->errors,
        ]);
    }

    public function showInvoice(Invoice $invoice): JsonResponse
    {
        return response()->json($invoice->load([
            'student.studyProgram', 'semester.academicYear',
            'items.feeType', 'payments.verifiedBy', 'createdBy',
        ]));
    }

    public function studentFinance(int $studentId): JsonResponse
    {
        $this->authorizeStudentFinanceAccess($studentId);
        $student = Student::with(['studyProgram.faculty', 'academicYear'])
            ->findOrFail($studentId);

        $invoices = $student->invoices()
            ->with(['semester.academicYear', 'items.feeType', 'payments.verifiedBy'])
            ->orderByDesc('invoice_date')
            ->orderByDesc('id')
            ->get();
        $payments = $student->payments()
            ->with(['invoice', 'verifiedBy'])
            ->orderByDesc('payment_date')
            ->orderByDesc('id')
            ->get();

        $totalInvoiced = (float) $invoices->whereNotIn('status', ['CANCELLED'])->sum('total_amount');
        $totalDiscount = (float) $invoices->whereNotIn('status', ['CANCELLED'])->sum('discount_amount');
        $totalScholarship = (float) $invoices->whereNotIn('status', ['CANCELLED'])->sum('scholarship_amount');
        $totalPaid = (float) $payments->where('status', 'VERIFIED')->sum('amount');

        return response()->json([
            'student' => $student,
            'summary' => [
                'total_invoiced' => $totalInvoiced,
                'total_discount' => $totalDiscount,
                'total_scholarship' => $totalScholarship,
                'total_paid' => $totalPaid,
                'outstanding' => max(0, $totalInvoiced - $totalDiscount - $totalScholarship - $totalPaid),
                'invoice_count' => $invoices->count(),
                'unpaid_count' => $invoices->whereIn('status', ['UNPAID', 'PARTIAL', 'OVERDUE'])->count(),
            ],
            'invoices' => $invoices,
            'payments' => $payments,
        ]);
    }

    private function authorizeStudentFinanceAccess(int $studentId): void
    {
        $user = auth()->user();
        $student = Student::findOrFail($studentId);
        $managedStudyPrograms = $user?->getManagedStudyProgramIds() ?? [];
        if ($managedStudyPrograms && in_array((int) $student->study_program_id, $managedStudyPrograms, true)) {
            return;
        }
        if ($user?->hasRole('DOSEN')) {
            abort_unless(
                (int) $student->advisor_id === (int) $user->lecturer?->id,
                403,
                'Anda hanya dapat melihat tunggakan mahasiswa bimbingan.'
            );
        }
    }

    private function studentReportData(Request $request): array
    {
        $filters = $request->validate([
            'group_by' => 'nullable|in:month,semester,prodi,kelas,student',
            'search' => 'nullable|string|max:100',
            'payment_status' => 'nullable|in:all,lunas,menunggak',
            'month' => 'nullable|date_format:Y-m',
            'semester_id' => 'nullable|integer',
            'study_program_id' => 'nullable|integer',
            'class_type' => 'nullable|string|max:30',
        ]);

        return app(\App\Services\StudentFinanceReport::class)->build($filters);
    }

    public function studentReport(Request $request): JsonResponse
    {
        return response()->json($this->studentReportData($request));
    }

    public function studentReportPdf(Request $request)
    {
        $institution = \App\Models\Institution::first();

        return Pdf::loadView('pdf.student-finance-report', [
            'report' => $this->studentReportData($request),
            'institution' => $institution,
            'letterheadPath' => $institution?->letterhead_path
                ? \Illuminate\Support\Facades\Storage::disk('public')->path($institution->letterhead_path)
                : null,
        ])->setPaper('a4', 'landscape')->download('laporan-keuangan-mahasiswa-'.now()->format('Ymd').'.pdf');
    }

    public function studentReportExcel(Request $request)
    {
        return \Maatwebsite\Excel\Facades\Excel::download(
            new \App\Exports\StudentFinanceReportExport($this->studentReportData($request)),
            'laporan-keuangan-mahasiswa-'.now()->format('Ymd').'.xlsx'
        );
    }

    public function studentFinancePdf(int $studentId)
    {
        $this->authorizeStudentFinanceAccess($studentId);
        $student = Student::with(['studyProgram.faculty', 'academicYear'])->findOrFail($studentId);
        $institution = \App\Models\Institution::first();
        $invoices = $student->invoices()
            ->with(['semester.academicYear', 'items.feeType'])
            ->orderByDesc('invoice_date')
            ->orderByDesc('id')
            ->get();
        $payments = $student->payments()
            ->with(['invoice', 'verifiedBy'])
            ->orderByDesc('payment_date')
            ->orderByDesc('id')
            ->get();

        $activeInvoices = $invoices->whereNotIn('status', ['CANCELLED']);
        $totalInvoiced = (float) $activeInvoices->sum('total_amount');
        $totalDiscount = (float) $activeInvoices->sum('discount_amount');
        $totalScholarship = (float) $activeInvoices->sum('scholarship_amount');
        $totalPaid = (float) $payments->where('status', 'VERIFIED')->sum('amount');

        $pdf = Pdf::loadView('pdf.student-finance', [
            'student' => $student,
            'invoices' => $invoices,
            'payments' => $payments,
            'letterheadPath' => $institution?->letterhead_path
                ? \Illuminate\Support\Facades\Storage::disk('public')->path($institution->letterhead_path)
                : null,
            'summary' => [
                'totalInvoiced' => $totalInvoiced,
                'totalDiscount' => $totalDiscount,
                'totalScholarship' => $totalScholarship,
                'totalPaid' => $totalPaid,
                'outstanding' => max(0, $totalInvoiced - $totalDiscount - $totalScholarship - $totalPaid),
            ],
            'institution' => $institution,
        ])->setPaper('a4', 'portrait')
            ->setOption('defaultFont', 'DejaVu Sans');

        return $pdf->download("keuangan-{$student->nim}.pdf");
    }

    public function storeInvoice(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'student_id' => 'required|exists:students,id',
            'semester_id' => 'nullable|exists:semesters,id',
            'due_date' => 'required|date',
            'note' => 'nullable|string',
            'discount_amount' => 'nullable|numeric|min:0',
            'scholarship_amount' => 'nullable|numeric|min:0',
            'items' => 'required|array|min:1',
            'items.*.fee_type_id' => 'required|exists:fee_types,id',
            'items.*.description' => 'nullable|string',
            'items.*.amount' => 'required|numeric|min:0',
        ]);

        $studentStatus = Student::whereKey($validated['student_id'])->value('status');
        if (in_array($studentStatus, ExitedStudentInvoiceService::EXIT_STATUSES, true)) {
            return response()->json(['message' => "Mahasiswa berstatus {$studentStatus}, tagihan baru tidak dapat dibuat."], 422);
        }

        if ($studentStatus === 'Cuti') {
            $leaveFeeTypeId = FeeType::where('code', ExitedStudentInvoiceService::LEAVE_FEE_CODE)->value('id');
            $hasOtherFee = collect($validated['items'])->contains(fn ($item) => (int) $item['fee_type_id'] !== (int) $leaveFeeTypeId);
            if ($hasOtherFee) {
                return response()->json(['message' => 'Mahasiswa berstatus Cuti hanya dapat ditagih Biaya Cuti.'], 422);
            }
        }

        $invoice = DB::transaction(function () use ($validated) {
            $totalAmount = collect($validated['items'])->sum('amount');

            $invoice = Invoice::create([
                'invoice_number' => Invoice::generateInvoiceNumber($validated['semester_id'] ?? 0),
                'student_id' => $validated['student_id'],
                'semester_id' => $validated['semester_id'] ?? null,
                'invoice_date' => now()->toDateString(),
                'due_date' => $validated['due_date'],
                'total_amount' => $totalAmount,
                'discount_amount' => $validated['discount_amount'] ?? 0,
                'scholarship_amount' => $validated['scholarship_amount'] ?? 0,
                'status' => 'UNPAID',
                'note' => $validated['note'] ?? null,
                'created_by' => auth()->id(),
            ]);

            foreach ($validated['items'] as $item) {
                InvoiceItem::create([
                    'invoice_id' => $invoice->id,
                    'fee_type_id' => $item['fee_type_id'],
                    'description' => $item['description'] ?? null,
                    'amount' => $item['amount'],
                ]);
            }

            return $invoice;
        });

        // Kirim notifikasi ke mahasiswa
        $this->notifyStudentInvoice($invoice);

        return response()->json([
            'message' => 'Tagihan berhasil dibuat.',
            'data' => $invoice->load(['student', 'items.feeType']),
        ], 201);
    }

    public function updateInvoice(Request $request, Invoice $invoice): JsonResponse
    {
        $validated = $request->validate([
            'due_date' => 'required|date',
            'note' => 'nullable|string',
            'discount_amount' => 'required|numeric|min:0',
            'scholarship_amount' => 'required|numeric|min:0',
            'items' => 'required|array|min:1',
            'items.*.fee_type_id' => 'required|exists:fee_types,id',
            'items.*.description' => 'nullable|string',
            'items.*.amount' => 'required|numeric|min:0',
        ]);

        DB::transaction(function () use ($invoice, $validated): void {
            $invoice->update([
                'due_date' => $validated['due_date'],
                'discount_amount' => $validated['discount_amount'],
                'scholarship_amount' => $validated['scholarship_amount'],
                'total_amount' => collect($validated['items'])->sum('amount'),
                'note' => $validated['note'] ?? null,
            ]);
            $invoice->items()->delete();
            foreach ($validated['items'] as $item) {
                $invoice->items()->create($item);
            }
            if (! in_array($invoice->status, ['CANCELLED', 'WAIVED'], true)) {
                $invoice->recalculateStatus();
            }
        });
        $invoice->loadMissing('student');
        if ($invoice->student?->user_id) {
            AppNotification::send(
                $invoice->student->user_id,
                'Tagihan Diperbarui',
                'Rincian atau jatuh tempo tagihan Anda telah diperbarui.',
                'info',
                '/keuangan/saya'
            );
        }

        return response()->json(['message' => 'Tagihan berhasil diperbarui.', 'data' => $invoice->fresh(['student', 'semester', 'items.feeType', 'payments'])]);
    }

    /** Kirim notifikasi tagihan ke mahasiswa */
    private function notifyStudentInvoice(Invoice $invoice): void
    {
        $student = $invoice->student;
        if (! $student?->user_id) {
            return;
        }

        $amount = number_format($invoice->total_amount, 0, ',', '.');
        AppNotification::send(
            $student->user_id,
            'Tagihan Baru',
            "Anda memiliki tagihan baru sebesar Rp {$amount}. Jatuh tempo: ".($invoice->due_date?->format('d/m/Y') ?? '-'),
            'warning',
            '/keuangan/saya'
        );
    }

    /** Generate tagihan otomatis untuk semua mahasiswa aktif per semester */
    public function generateBatch(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'semester_id' => 'required|exists:semesters,id',
            'study_program_id' => 'nullable|exists:study_programs,id',
            'entry_year' => 'nullable|integer|digits:4',
            'due_date' => 'required|date',
            'fee_type_ids' => 'required|array|min:1',
            'fee_type_ids.*' => [
                'integer',
                Rule::exists('fee_types', 'id')->where(fn ($query) => $query->where('is_active', true)),
            ],
            'preview' => 'nullable|boolean',
        ]);

        $semester = Semester::findOrFail($validated['semester_id']);
        $preview = $request->boolean('preview');
        $feeTypeIds = collect($validated['fee_type_ids'])->map(fn ($id) => (int) $id)->unique()->values();

        $students = Student::where('status', 'Aktif')
            ->when($validated['study_program_id'] ?? null, fn ($q, $v) => $q->where('study_program_id', $v))
            ->when($validated['entry_year'] ?? null, fn ($q, $v) => $q->where('entry_year', $v))
            ->get();

        $result = [
            'students' => $students->count(),
            'created' => 0,
            'would_create' => 0,
            'already_complete' => 0,
            'without_structure' => 0,
            'errors' => [],
        ];

        foreach ($students as $student) {
            try {
                $structures = $this->feeStructures->resolve($student, $semester, false)
                    ->filter(fn ($structure): bool => $feeTypeIds->contains((int) $structure->fee_type_id));
                if ($structures->isEmpty()) {
                    $result['without_structure']++;

                    continue;
                }

                $coveredFeeTypeIds = InvoiceItem::query()
                    ->whereHas('invoice', fn ($query) => $query
                        ->where('student_id', $student->id)
                        ->where('semester_id', $semester->id)
                        ->where('status', '!=', 'CANCELLED'))
                    ->pluck('fee_type_id')
                    ->map(fn ($id) => (int) $id)
                    ->all();
                $missingStructures = $structures->reject(
                    fn ($structure) => in_array((int) $structure->fee_type_id, $coveredFeeTypeIds, true)
                )->values();

                if ($missingStructures->isEmpty()) {
                    $result['already_complete']++;

                    continue;
                }

                if ($preview) {
                    $result['would_create']++;

                    continue;
                }

                $invoice = DB::transaction(function () use ($student, $semester, $validated, $missingStructures): Invoice {
                    $invoice = Invoice::create([
                        'invoice_number' => Invoice::generateInvoiceNumber($semester->id),
                        'student_id' => $student->id,
                        'semester_id' => $semester->id,
                        'invoice_date' => now()->toDateString(),
                        'due_date' => $validated['due_date'],
                        'total_amount' => $missingStructures->sum('amount'),
                        'status' => 'UNPAID',
                        'created_by' => auth()->id(),
                    ]);

                    foreach ($missingStructures as $structure) {
                        InvoiceItem::create([
                            'invoice_id' => $invoice->id,
                            'fee_type_id' => $structure->fee_type_id,
                            'description' => $structure->feeType->name,
                            'amount' => $structure->amount,
                        ]);
                    }

                    return $invoice;
                });

                $this->notifyStudentInvoice($invoice->load('student'));
                $result['created']++;
            } catch (\Throwable $exception) {
                $result['errors'][] = "{$student->nim}: {$exception->getMessage()}";
            }
        }

        $count = $preview ? $result['would_create'] : $result['created'];

        return response()->json([
            'message' => $preview
                ? "Pratinjau selesai. {$count} mahasiswa akan dibuatkan tagihan."
                : "Generate selesai. {$count} tagihan mahasiswa berhasil dibuat.",
            'count' => $count,
            'preview' => $preview,
            ...$result,
        ]);
    }

    public function cancelInvoice(Invoice $invoice): JsonResponse
    {
        if ($invoice->paid_amount > 0) {
            return response()->json(['message' => 'Tidak dapat membatalkan tagihan yang sudah ada pembayaran.'], 422);
        }
        $invoice->update(['status' => 'CANCELLED']);
        if ($invoice->student?->user_id) {
            AppNotification::send($invoice->student->user_id, 'Tagihan Dibatalkan',
                'Tagihan '.$invoice->invoice_number.' telah dibatalkan.', 'warning', '/keuangan/saya');
        }

        return response()->json(['message' => 'Tagihan berhasil dibatalkan.']);
    }

    public function destroyInvoice(Invoice $invoice): JsonResponse
    {
        if ($invoice->paid_amount > 0 || $invoice->payments()->where('status', 'VERIFIED')->exists()) {
            return response()->json(['message' => 'Tidak dapat menghapus tagihan yang sudah ada pembayaran terverifikasi.'], 422);
        }
        $invoice->loadMissing('student');
        $studentUserId = $invoice->student?->user_id;
        $invoiceNumber = $invoice->invoice_number;
        $invoice->payments()->delete();
        $invoice->items()->delete();
        $invoice->delete();
        if ($studentUserId) {
            AppNotification::send($studentUserId, 'Tagihan Dihapus',
                "Tagihan {$invoiceNumber} telah dihapus oleh bagian keuangan.", 'warning', '/keuangan/saya');
        }

        return response()->json(['message' => 'Tagihan berhasil dihapus.']);
    }

    public function waiveInvoice(Request $request, Invoice $invoice): JsonResponse
    {
        $request->validate(['note' => 'required|string']);
        $invoice->update(['status' => 'WAIVED', 'note' => $request->note]);
        if ($invoice->student?->user_id) {
            AppNotification::send($invoice->student->user_id, 'Tagihan Dibebaskan',
                'Tagihan '.$invoice->invoice_number.' telah dibebaskan. Catatan: '.$request->note, 'success', '/keuangan/saya');
        }

        return response()->json(['message' => 'Tagihan berhasil dibebaskan.']);
    }

    // =============================================
    // PAYMENTS (PEMBAYARAN)
    // =============================================

    private function paymentsQuery(Request $request)
    {
        $query = Payment::with(['invoice.semester', 'invoice.items.feeType', 'student.studyProgram', 'verifiedBy'])
            ->when($request->status, fn ($q) => $q->where('status', $request->status))
            ->when($request->student_id, fn ($q) => $q->where('student_id', $request->student_id))
            ->when($request->search, fn ($q) => $q->whereHas('student', fn ($q2) => $q2->where('name', 'like', "%{$request->search}%")
                ->orWhere('nim', 'like', "%{$request->search}%")))
            ->when($request->study_program_id, fn ($q, $v) => $q->whereHas('student', fn ($s) => $s->where('study_program_id', $v)))
            ->when($request->class_type, fn ($q, $v) => $q->whereHas('student', fn ($s) => $s->where('class_type', $v)))
            ->when($request->fee_type_id, fn ($q, $v) => $q->whereHas('invoice.items', fn ($i) => $i->where('fee_type_id', $v)));

        $user = auth()->user();
        if ($user->hasRole('MAHASISWA') && $user->student) {
            $query->where('student_id', $user->student->id);
        }

        return $query;
    }

    public function payments(Request $request): JsonResponse
    {
        return response()->json($this->paymentsQuery($request)->orderByDesc('payment_date')->paginate($request->per_page ?? 20));
    }

    public function exportPayments(Request $request)
    {
        $payments = $this->paymentsQuery($request)->orderByDesc('payment_date')->orderByDesc('id')->get();

        return Excel::download(new PaymentExport($payments), 'pembayaran-mahasiswa-'.now()->format('Ymd-His').'.xlsx');
    }

    public function paymentImportTemplate()
    {
        return Excel::download(new PaymentTemplateExport, 'template-import-pembayaran.xlsx');
    }

    public function importPayments(Request $request): JsonResponse
    {
        $request->validate([
            'file' => 'required|file|mimes:xlsx,xls,csv|max:5120',
            'preview' => 'nullable|boolean',
        ]);

        $preview = $request->boolean('preview');
        $import = new PaymentImport($preview, auth()->id());
        Excel::import($import, $request->file('file'));

        $verb = $preview ? 'akan diimpor' : 'berhasil diimpor';

        return response()->json([
            'message' => "{$import->imported} pembayaran {$verb}, {$import->skippedPaid} dilewati karena sudah membayar, ".count($import->errors).' baris gagal.',
            'preview' => $preview,
            'imported' => $import->imported,
            'skipped_paid' => $import->skippedPaid,
            'errors' => $import->errors,
        ]);
    }

    public function storePayment(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'invoice_id' => 'required|exists:invoices,id',
            'amount' => 'required|numeric|min:1',
            'payment_method' => 'required|string|max:50',
            'payment_date' => 'required|date',
            'reference_number' => 'nullable|string|max:255',
            'bank_name' => 'nullable|string|max:100',
            'account_number' => 'nullable|string|max:50',
            'account_name' => 'nullable|string|max:100',
            'note' => 'nullable|string',
        ]);

        $invoice = Invoice::findOrFail($validated['invoice_id']);

        if (in_array($invoice->status, ['PAID', 'CANCELLED', 'WAIVED'])) {
            return response()->json(['message' => 'Tagihan sudah lunas/dibatalkan.'], 422);
        }

        $payment = Payment::createWithGeneratedPaymentNumber([
            'invoice_id' => $invoice->id,
            'student_id' => $invoice->student_id,
            'amount' => $validated['amount'],
            'payment_method' => $validated['payment_method'],
            'payment_date' => $validated['payment_date'],
            'reference_number' => $validated['reference_number'] ?? null,
            'bank_name' => $validated['bank_name'] ?? null,
            'account_number' => $validated['account_number'] ?? null,
            'account_name' => $validated['account_name'] ?? null,
            'note' => $validated['note'] ?? null,
            'status' => 'PENDING',
        ]);
        if ($invoice->student?->user_id) {
            AppNotification::send($invoice->student->user_id, 'Pembayaran Menunggu Verifikasi',
                "Pembayaran {$payment->payment_number} telah dikirim dan menunggu verifikasi.",
                'info', '/keuangan/saya');
        }

        return response()->json(['message' => 'Pembayaran berhasil dicatat, menunggu verifikasi.', 'data' => $payment], 201);
    }

    public function updatePayment(Request $request, Payment $payment): JsonResponse
    {
        $validated = $request->validate([
            'amount' => 'required|numeric|min:1',
            'payment_method' => 'required|string|max:50',
            'payment_date' => 'required|date',
            'reference_number' => 'nullable|string|max:255',
            'bank_name' => 'nullable|string|max:100',
            'account_number' => 'nullable|string|max:50',
            'account_name' => 'nullable|string|max:100',
            'note' => 'nullable|string',
        ]);

        $invoice = $payment->invoice;
        $netAmount = $invoice->total_amount - $invoice->discount_amount - $invoice->scholarship_amount;
        $verifiedAmount = $invoice->payments()->where('status', 'VERIFIED')->where('id', '!=', $payment->id)->sum('amount');
        if ($payment->status === 'VERIFIED' && $verifiedAmount + $validated['amount'] > $netAmount) {
            return response()->json(['message' => 'Jumlah pembayaran melebihi sisa tagihan.'], 422);
        }

        $payment->update($validated);
        $invoice->recalculateStatus();
        $payment->loadMissing('student');
        if ($payment->student?->user_id) {
            AppNotification::send($payment->student->user_id, 'Data Pembayaran Diperbarui',
                "Data pembayaran {$payment->payment_number} telah diperbarui oleh bagian keuangan.",
                'info', '/keuangan/saya');
        }

        return response()->json(['message' => 'Pembayaran berhasil diperbarui.', 'data' => $payment->fresh(['invoice', 'student', 'verifiedBy'])]);
    }

    public function destroyPayment(Payment $payment): JsonResponse
    {
        $payment->loadMissing('student');
        $studentUserId = $payment->student?->user_id;
        $paymentNumber = $payment->payment_number;
        [$deleted, $receiptPath, $status] = DB::transaction(function () use ($payment): array {
            $lockedPayment = Payment::query()->lockForUpdate()->findOrFail($payment->id);
            if (! in_array($lockedPayment->status, ['PENDING', 'VERIFIED', 'REJECTED'], true)) {
                return [false, null, $lockedPayment->status];
            }

            $receiptPath = $lockedPayment->receipt_path;
            $status = $lockedPayment->status;
            $invoice = Invoice::query()->lockForUpdate()->findOrFail($lockedPayment->invoice_id);
            $lockedPayment->delete();
            if ($status === 'VERIFIED') {
                $invoice->recalculateStatus();
            }

            return [true, $receiptPath, $status];
        });

        if (! $deleted) {
            return response()->json(['message' => 'Pembayaran dengan status tersebut tidak dapat dihapus.'], 422);
        }

        if ($receiptPath) {
            Storage::disk('public')->delete($receiptPath);
        }
        if ($studentUserId) {
            AppNotification::send($studentUserId, 'Pembayaran Dihapus',
                "Data pembayaran {$paymentNumber} berstatus {$status} telah dihapus oleh bagian keuangan.",
                'warning', '/keuangan/saya');
        }

        return response()->json([
            'message' => 'Pembayaran berstatus '.$status.' berhasil dihapus.'
                .($status === 'VERIFIED' ? ' Saldo tagihan telah dihitung ulang.' : ''),
        ]);
    }

    public function verifyPayment(Request $request, Payment $payment): JsonResponse
    {
        if ($payment->status !== 'PENDING') {
            return response()->json(['message' => 'Pembayaran sudah diproses.'], 422);
        }

        $request->validate([
            'action' => 'required|in:verify,reject',
            'reason' => 'nullable|required_if:action,reject|string|max:2000',
        ]);

        if ($request->action === 'verify') {
            $netAmount = $payment->invoice->total_amount
                - $payment->invoice->discount_amount
                - $payment->invoice->scholarship_amount;
            $verifiedAmount = $payment->invoice->payments()
                ->where('status', 'VERIFIED')
                ->where('id', '!=', $payment->id)
                ->sum('amount');

            if ($verifiedAmount + $payment->amount > $netAmount) {
                return response()->json([
                    'message' => 'Jumlah pembayaran melebihi sisa tagihan.',
                ], 422);
            }

            $payment->update([
                'status' => 'VERIFIED',
                'verified_by' => auth()->id(),
                'verified_at' => now(),
            ]);
            // Update invoice
            $payment->invoice->recalculateStatus();
            if ($payment->student?->user_id) {
                AppNotification::send($payment->student->user_id, 'Pembayaran Diverifikasi ✓',
                    "Pembayaran {$payment->payment_number} telah diverifikasi.", 'success', '/keuangan/saya');
            }

            return response()->json(['message' => 'Pembayaran berhasil diverifikasi.']);
        } else {
            $payment->update([
                'status' => 'REJECTED',
                'rejection_reason' => trim($request->reason),
                'verified_by' => auth()->id(),
                'verified_at' => now(),
            ]);
            if ($payment->student?->user_id) {
                AppNotification::send($payment->student->user_id, 'Pembayaran Ditolak',
                    "Pembayaran {$payment->payment_number} ditolak.".($request->reason ? " Alasan: {$request->reason}" : ''),
                    'error', '/keuangan/saya');
            }

            return response()->json(['message' => 'Pembayaran ditolak.']);
        }
    }

    // =============================================
    // SCHOLARSHIPS (BEASISWA)
    // =============================================

    public function scholarships(): JsonResponse
    {
        return response()->json(Scholarship::withCount('students')->orderBy('name')->get());
    }

    public function storeScholarship(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'code' => 'required|string|max:30|unique:scholarships',
            'name' => 'required|string|max:255',
            'provider' => 'nullable|string|max:255',
            'description' => 'nullable|string',
            'requirements' => 'nullable|string',
            'amount' => 'nullable|numeric|min:0',
            'type' => 'required|in:FULL,PARTIAL,TUITION_ONLY,LIVING_COST',
            'is_active' => 'boolean',
        ]);

        $scholarship = Scholarship::create($validated);

        return response()->json(['message' => 'Beasiswa berhasil ditambahkan.', 'data' => $scholarship], 201);
    }

    public function updateScholarship(Request $request, Scholarship $scholarship): JsonResponse
    {
        $validated = $request->validate([
            'code' => "sometimes|string|max:30|unique:scholarships,code,{$scholarship->id}",
            'name' => 'sometimes|string|max:255',
            'provider' => 'nullable|string|max:255',
            'description' => 'nullable|string',
            'requirements' => 'nullable|string',
            'amount' => 'nullable|numeric|min:0',
            'type' => 'sometimes|in:FULL,PARTIAL,TUITION_ONLY,LIVING_COST',
            'is_active' => 'boolean',
        ]);

        $scholarship->update($validated);

        return response()->json(['message' => 'Beasiswa berhasil diupdate.', 'data' => $scholarship]);
    }

    public function destroyScholarship(Scholarship $scholarship): JsonResponse
    {
        if ($scholarship->students()->count() > 0) {
            return response()->json(['message' => 'Tidak dapat menghapus beasiswa yang sudah memiliki penerima.'], 422);
        }
        $scholarship->delete();

        return response()->json(['message' => 'Beasiswa berhasil dihapus.']);
    }

    public function studentScholarships(Request $request): JsonResponse
    {
        $data = StudentScholarship::with(['student.studyProgram', 'scholarship', 'academicYear', 'semester'])
            ->when($request->student_id, fn ($q) => $q->where('student_id', $request->student_id))
            ->when($request->scholarship_id, fn ($q) => $q->where('scholarship_id', $request->scholarship_id))
            ->when($request->status, fn ($q) => $q->where('status', $request->status))
            ->paginate($request->per_page ?? 20);

        return response()->json($data);
    }

    public function assignScholarship(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'student_id' => 'required|exists:students,id',
            'scholarship_id' => 'required|exists:scholarships,id',
            'academic_year_id' => 'nullable|exists:academic_years,id',
            'semester_id' => 'nullable|exists:semesters,id',
            'amount' => 'required|numeric|min:0',
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date',
            'note' => 'nullable|string',
        ]);

        $studentScholarship = StudentScholarship::create(array_merge($validated, ['status' => 'AKTIF']));
        $studentScholarship->load(['student', 'scholarship']);
        if ($studentScholarship->student?->user_id) {
            AppNotification::send($studentScholarship->student->user_id, 'Beasiswa Diberikan',
                "Anda menerima beasiswa \"{$studentScholarship->scholarship?->name}\".", 'success', '/keuangan/saya');
        }

        return response()->json(['message' => 'Beasiswa berhasil diberikan.', 'data' => $studentScholarship], 201);
    }

    public function revokeScholarship(StudentScholarship $studentScholarship): JsonResponse
    {
        $studentScholarship->update(['status' => 'DICABUT']);
        $studentScholarship->loadMissing('student', 'scholarship');
        if ($studentScholarship->student?->user_id) {
            AppNotification::send(
                $studentScholarship->student->user_id,
                'Beasiswa Dicabut',
                "Beasiswa \"{$studentScholarship->scholarship?->name}\" telah dicabut.",
                'warning',
                '/keuangan/saya'
            );
        }

        return response()->json(['message' => 'Beasiswa berhasil dicabut.']);
    }

    // =============================================
    // SUMMARY & REPORTS
    // =============================================

    /** Mahasiswa submit pembayaran + upload bukti */
    public function studentPayment(Request $request): JsonResponse
    {
        $request->validate([
            'invoice_id' => 'required|exists:invoices,id',
            'amount' => 'required|numeric|min:1',
            'payment_method' => 'required|string|max:50',
            'payment_date' => 'required|date',
            'reference_number' => 'nullable|string|max:255',
            'bank_name' => 'nullable|string|max:100',
            'account_name' => 'nullable|string|max:100',
            'note' => 'nullable|string',
            'receipt' => 'nullable|file|mimes:jpeg,png,pdf,webp|max:5120',
        ]);

        $student = auth()->user()->student;
        if (! $student) {
            return response()->json(['message' => 'Data mahasiswa tidak ditemukan.'], 404);
        }

        $invoice = Invoice::where('id', $request->invoice_id)
            ->where('student_id', $student->id)
            ->first();

        if (! $invoice) {
            return response()->json(['message' => 'Tagihan tidak ditemukan.'], 404);
        }

        if (in_array($invoice->status, ['PAID', 'CANCELLED', 'WAIVED'])) {
            return response()->json(['message' => 'Tagihan sudah lunas/dibatalkan.'], 422);
        }

        $receiptPath = null;
        if ($request->hasFile('receipt')) {
            $receiptPath = $request->file('receipt')->store('payment-receipts', 'public');
        }

        try {
            $payment = Payment::createWithGeneratedPaymentNumber([
                'invoice_id' => $invoice->id,
                'student_id' => $student->id,
                'amount' => $request->amount,
                'payment_method' => $request->payment_method,
                'payment_date' => $request->payment_date,
                'reference_number' => $request->reference_number,
                'bank_name' => $request->bank_name,
                'account_name' => $request->account_name,
                'receipt_path' => $receiptPath,
                'note' => $request->note,
                'status' => 'PENDING',
            ]);
        } catch (\Throwable $exception) {
            if ($receiptPath) {
                Storage::disk('public')->delete($receiptPath);
            }

            throw $exception;
        }

        $financeUsers = \App\Models\User::role(['SUPER_ADMIN', 'ADMIN_KEUANGAN', 'ADMIN_AKADEMIK'])
            ->where('is_active', true)
            ->where('id', '!=', auth()->id())
            ->get(['id']);
        foreach ($financeUsers as $financeUser) {
            AppNotification::send(
                $financeUser->id,
                'Pembayaran Menunggu Verifikasi',
                "Mahasiswa {$student->name} mengirim pembayaran {$payment->payment_number} yang menunggu verifikasi.",
                'warning',
                '/keuangan/pembayaran'
            );
        }

        return response()->json(['message' => 'Pembayaran berhasil dikirim, menunggu verifikasi admin.', 'data' => $payment], 201);
    }

    /** Cek status keuangan mahasiswa (untuk integrasi KRS) */
    public function checkPaymentStatus(Request $request): JsonResponse
    {
        $request->validate(['student_id' => 'required|exists:students,id', 'semester_id' => 'required|exists:semesters,id']);
        $student = Student::findOrFail($request->integer('student_id'));
        if ($request->user()?->hasRole('MAHASISWA')) {
            abort_unless((int) $request->user()?->student?->id === (int) $student->id, 403);
        }

        return response()->json(
            $this->krsEligibility->evaluate($student, Semester::findOrFail($request->integer('semester_id')))['finance']
        );
    }

    /** Rekap keuangan mahasiswa */
    public function studentSummary(Request $request): JsonResponse
    {
        $studentId = $request->student_id ?? auth()->user()->student?->id;
        if (! $studentId) {
            return response()->json(['message' => 'Student not found.'], 404);
        }

        $totalInvoiced = Invoice::where('student_id', $studentId)->whereNotIn('status', ['CANCELLED', 'WAIVED'])->sum('total_amount');
        $totalDiscount = Invoice::where('student_id', $studentId)->whereNotIn('status', ['CANCELLED', 'WAIVED'])->sum('discount_amount');
        $totalScholarship = Invoice::where('student_id', $studentId)->whereNotIn('status', ['CANCELLED', 'WAIVED'])->sum('scholarship_amount');
        $totalPaid = Payment::where('student_id', $studentId)->where('status', 'VERIFIED')->sum('amount');
        $outstanding = $totalInvoiced - $totalDiscount - $totalScholarship - $totalPaid;

        return response()->json([
            'total_invoiced' => $totalInvoiced,
            'total_discount' => $totalDiscount,
            'total_scholarship' => $totalScholarship,
            'total_paid' => $totalPaid,
            'outstanding' => max(0, $outstanding),
        ]);
    }

    /** Dashboard statistik keuangan */
    public function dashboard(Request $request): JsonResponse
    {
        $semesterId = $request->semester_id;

        $query = Invoice::when($semesterId, fn ($q) => $q->where('semester_id', $semesterId));

        $totalInvoiced = (clone $query)->whereNotIn('status', ['CANCELLED', 'WAIVED'])->sum('total_amount');
        $totalPaid = Payment::when($semesterId, fn ($q) => $q->whereHas('invoice', fn ($q2) => $q2->where('semester_id', $semesterId)))->where('status', 'VERIFIED')->sum('amount');
        $pendingPayments = Payment::when($semesterId, fn ($q) => $q->whereHas('invoice', fn ($q2) => $q2->where('semester_id', $semesterId)))->where('status', 'PENDING')->count();
        $unpaidInvoices = (clone $query)->whereIn('status', ['UNPAID', 'PARTIAL', 'OVERDUE'])->count();
        $overdueInvoices = (clone $query)->where('status', 'OVERDUE')->count();

        return response()->json([
            'total_invoiced' => $totalInvoiced,
            'total_paid' => $totalPaid,
            'total_outstanding' => max(0, $totalInvoiced - $totalPaid),
            'pending_payments' => $pendingPayments,
            'unpaid_invoices' => $unpaidInvoices,
            'overdue_invoices' => $overdueInvoices,
        ]);
    }
}
