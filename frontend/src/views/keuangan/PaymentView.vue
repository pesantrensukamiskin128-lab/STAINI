<script setup lang="ts">
import { ref, onMounted, computed } from 'vue'
import { useToast } from 'vue-toastification'
import { CheckCircleIcon, XCircleIcon, EyeIcon, PencilIcon, TrashIcon } from '@heroicons/vue/24/outline'
import DataTable from '@/components/ui/DataTable.vue'
import BaseModal from '@/components/ui/BaseModal.vue'
import api from '@/services/api'
import PaymentEditModal from '@/components/finance/PaymentEditModal.vue'

interface PaymentItem {
  id: number; payment_number: string; amount: number
  payment_method: string; payment_date: string; reference_number: string
  bank_name: string; account_name: string; receipt_path: string
  status: string; note: string; rejection_reason: string
  student?: { nim: string; name: string; study_program?: { code: string } }
  invoice?: { invoice_number: string }
  verified_by?: { name: string }
}

const toast = useToast()
const items = ref<PaymentItem[]>([])
const pagination = ref({ total: 0, currentPage: 1, lastPage: 1 })
const loading = ref(true)
const filterStatus = ref('PENDING')
const filterProdi = ref('')
const filterClassType = ref('')
const filterFeeType = ref('')
const prodis = ref<any[]>([])
const feeTypes = ref<any[]>([])
const search = ref('')

// Detail/preview modal
const detailModal = ref(false)
const selectedPayment = ref<PaymentItem | null>(null)
const editModal = ref(false)
const rejectionModal = ref(false)
const rejectionPayment = ref<PaymentItem | null>(null)
const rejectionReason = ref('')
const rejecting = ref(false)

const baseUrl = computed(() => (import.meta.env.VITE_API_URL ?? 'http://localhost:8000/api').replace(/\/api\/?$/, ''))

const columns = [
  { key: 'payment', label: 'No. Pembayaran' },
  { key: 'student', label: 'Mahasiswa' },
  { key: 'invoice', label: 'Invoice' },
  { key: 'method', label: 'Metode' },
  { key: 'amount', label: 'Jumlah', class: 'text-right' },
  { key: 'status', label: 'Status' },
  { key: 'aksi', label: '', class: 'text-right' },
]

const statusColor: Record<string, string> = {
  PENDING: 'bg-yellow-100 text-yellow-700', VERIFIED: 'bg-green-100 text-green-700',
  REJECTED: 'bg-red-100 text-red-600', REFUNDED: 'bg-purple-100 text-purple-700',
}

onMounted(() => {
  loadFilterOptions()
  load()
})

async function loadFilterOptions() {
  const [p, f] = await Promise.allSettled([api.get('/study-programs/all'), api.get('/finance/fee-types')])
  const list = (r: any) => (Array.isArray(r.data) ? r.data : r.data?.data ?? [])
  if (p.status === 'fulfilled') prodis.value = list(p.value)
  if (f.status === 'fulfilled') feeTypes.value = list(f.value)
}

async function load(page = 1) {
  loading.value = true
  try {
    const { data } = await api.get('/finance/payments', {
      params: {
        status: filterStatus.value, search: search.value, page,
        study_program_id: filterProdi.value, class_type: filterClassType.value, fee_type_id: filterFeeType.value,
      }
    })
    if (page > 1 && page > data.last_page && data.last_page > 0) {
      return await load(data.last_page)
    }
    items.value = data.data
    pagination.value = { total: data.total, currentPage: data.current_page, lastPage: data.last_page }
  } finally { loading.value = false }
}

const importing = ref(false)
const importFile = ref<HTMLInputElement | null>(null)
const importResult = ref<{ message: string; preview: boolean; errors: string[] } | null>(null)

function saveBlob(data: BlobPart, name: string) {
  const url = URL.createObjectURL(new Blob([data]))
  const a = document.createElement('a')
  a.href = url
  a.download = name
  a.click()
  URL.revokeObjectURL(url)
}

async function exportPayments() {
  try {
    const res = await api.get('/finance/payments/export', {
      responseType: 'blob',
      params: {
        status: filterStatus.value, search: search.value,
        study_program_id: filterProdi.value, class_type: filterClassType.value, fee_type_id: filterFeeType.value,
      },
    })
    saveBlob(res.data, `pembayaran-mahasiswa-${Date.now()}.xlsx`)
  } catch { toast.error('Gagal mengekspor pembayaran.') }
}

async function downloadTemplate() {
  try {
    const res = await api.get('/finance/payments/import-template', { responseType: 'blob' })
    saveBlob(res.data, 'template-import-pembayaran.xlsx')
  } catch { toast.error('Gagal mengunduh template.') }
}

async function onImportFile(e: Event) {
  const input = e.target as HTMLInputElement
  const file = input.files?.[0]
  if (!file) return
  const preview = !confirm('Impor sekarang? Klik "Batal" untuk hanya pratinjau tanpa menyimpan.')
  importing.value = true
  try {
    const form = new FormData()
    form.append('file', file)
    form.append('preview', preview ? '1' : '0')
    const { data } = await api.post('/finance/payments/import', form)
    importResult.value = { message: data.message, preview: data.preview, errors: data.errors ?? [] }
    if (!preview) await load()
  } catch (err: any) {
    toast.error(err?.response?.data?.message ?? 'Gagal mengimpor pembayaran.')
  } finally {
    importing.value = false
    input.value = ''
  }
}

async function verify(payment: PaymentItem, action: 'verify' | 'reject', reason?: string): Promise<boolean> {
  try {
    await api.post(`/finance/payments/${payment.id}/verify`, { action, reason })
    toast.success(action === 'verify' ? 'Pembayaran diverifikasi.' : 'Pembayaran ditolak.')
    if (selectedPayment.value?.id === payment.id) {
      selectedPayment.value.rejection_reason = reason ?? ''
      selectedPayment.value.status = action === 'verify' ? 'VERIFIED' : 'REJECTED'
    }
    await load(pagination.value.currentPage)
    return true
  } catch (e: any) {
    toast.error(e?.response?.data?.message ?? 'Gagal.')
    return false
  }
}

function openReject(payment: PaymentItem) {
  rejectionPayment.value = payment
  rejectionReason.value = ''
  detailModal.value = false
  rejectionModal.value = true
}

async function rejectPayment() {
  const payment = rejectionPayment.value
  const reason = rejectionReason.value.trim()
  if (!payment || !reason || rejecting.value) return

  rejecting.value = true
  try {
    if (await verify(payment, 'reject', reason)) {
      rejectionModal.value = false
      rejectionPayment.value = null
      rejectionReason.value = ''
    }
  } finally {
    rejecting.value = false
  }
}

async function deletePayment(payment: PaymentItem) {
  if (!['PENDING', 'VERIFIED', 'REJECTED'].includes(payment.status)) return
  const verifiedWarning = payment.status === 'VERIFIED'
    ? ' Pembayaran terverifikasi akan dikeluarkan dari perhitungan saldo tagihan.'
    : ''
  if (!confirm(`Hapus pembayaran ${payment.status.toLowerCase()} "${payment.payment_number}"? Data dan bukti pembayaran tidak dapat dikembalikan.${verifiedWarning}`)) return

  try {
    await api.delete(`/finance/payments/${payment.id}`)
    toast.success(`Pembayaran ${payment.status.toLowerCase()} berhasil dihapus.`)
    if (selectedPayment.value?.id === payment.id) detailModal.value = false
    await load(pagination.value.currentPage)
  } catch (e: any) { toast.error(e?.response?.data?.message ?? 'Gagal menghapus pembayaran.') }
}

function formatCurrency(n: number) {
  return new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', minimumFractionDigits: 0 }).format(n)
}
function formatDate(d: string) {
  return d ? new Date(d).toLocaleDateString('id-ID', { day: 'numeric', month: 'short', year: 'numeric' }) : '-'
}

function openDetail(payment: PaymentItem) {
  selectedPayment.value = payment
  detailModal.value = true
}
function openEdit(payment: PaymentItem) { selectedPayment.value = payment; editModal.value = true }
</script>

<template>
  <div class="space-y-5">
    <div class="flex flex-wrap items-start justify-between gap-3">
      <div>
        <h1 class="text-xl font-bold text-gray-900">Verifikasi Pembayaran</h1>
        <p class="text-sm text-gray-500 mt-0.5">Verifikasi pembayaran yang masuk dari mahasiswa</p>
      </div>
      <div class="flex flex-wrap gap-2">
        <button class="px-3.5 py-2 rounded-lg border border-gray-300 text-sm hover:bg-gray-50" @click="downloadTemplate">Template Import</button>
        <button class="px-3.5 py-2 rounded-lg border border-gray-300 text-sm hover:bg-gray-50" @click="exportPayments">Export</button>
        <button :disabled="importing" class="px-3.5 py-2 rounded-lg bg-blue-600 text-white text-sm hover:bg-blue-700 disabled:opacity-50" @click="importFile?.click()">
          {{ importing ? 'Memproses...' : 'Import' }}
        </button>
        <input ref="importFile" type="file" accept=".xlsx,.xls,.csv" class="hidden" @change="onImportFile" />
      </div>
    </div>

    <div v-if="importResult" class="rounded-lg border border-blue-200 bg-blue-50 p-3 text-sm text-gray-700">
      <div class="flex justify-between gap-3">
        <p><span v-if="importResult.preview" class="font-semibold">[Pratinjau] </span>{{ importResult.message }}</p>
        <button class="text-gray-500 hover:text-gray-800" @click="importResult = null">Tutup</button>
      </div>
      <ul v-if="importResult.errors.length" class="mt-2 list-disc pl-5 max-h-48 overflow-auto text-red-600">
        <li v-for="(er, i) in importResult.errors" :key="i">{{ er }}</li>
      </ul>
    </div>

    <div class="flex flex-wrap gap-3">
      <select v-model="filterStatus" class="px-3.5 py-2 rounded-lg border border-gray-300 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500" @change="load()">
        <option value="">Semua Status</option>
        <option value="PENDING">Pending</option>
        <option value="VERIFIED">Verified</option>
        <option value="REJECTED">Rejected</option>
      </select>
      <select v-model="filterProdi" class="px-3.5 py-2 rounded-lg border border-gray-300 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500" @change="load()">
        <option value="">Semua Prodi</option>
        <option v-for="p in prodis" :key="p.id" :value="p.id">{{ p.name }}</option>
      </select>
      <select v-model="filterClassType" class="px-3.5 py-2 rounded-lg border border-gray-300 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500" @change="load()">
        <option value="">Semua Jenis Kelas</option>
        <option value="REGULER">Reguler</option>
        <option value="HYBRID">Hybrid</option>
        <option value="KHUSUS">Khusus</option>
      </select>
      <select v-model="filterFeeType" class="px-3.5 py-2 rounded-lg border border-gray-300 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500" @change="load()">
        <option value="">Semua Jenis Tagihan</option>
        <option v-for="f in feeTypes" :key="f.id" :value="f.id">{{ f.name }}</option>
      </select>
      <input v-model="search" type="text" placeholder="Cari NIM/nama..." class="px-3.5 py-2 rounded-lg border border-gray-300 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 w-52" @input="load()" />
    </div>

    <DataTable :columns="columns" :rows="items" :loading="loading" :total="pagination.total" :current-page="pagination.currentPage" :last-page="pagination.lastPage" @page-change="load">
      <template #default="{ row }">
        <td class="px-4 py-3">
          <p class="font-mono text-xs text-gray-600">{{ row.payment_number }}</p>
          <p class="text-xs text-gray-400">{{ formatDate(row.payment_date) }}</p>
        </td>
        <td class="px-4 py-3">
          <p class="font-medium text-gray-900 text-sm">{{ row.student?.name }}</p>
          <p class="text-xs text-gray-500">{{ row.student?.nim }}</p>
        </td>
        <td class="px-4 py-3 text-xs text-gray-500 font-mono">{{ row.invoice?.invoice_number }}</td>
        <td class="px-4 py-3 text-sm text-gray-600">{{ row.payment_method }}
          <p v-if="row.reference_number" class="text-xs text-gray-400">Ref: {{ row.reference_number }}</p>
        </td>
        <td class="px-4 py-3 text-right font-semibold text-gray-900">{{ formatCurrency(row.amount) }}</td>
        <td class="px-4 py-3">
          <span :class="['inline-flex px-2 py-0.5 rounded-full text-xs font-medium', statusColor[row.status] ?? '']">{{ row.status }}</span>
        </td>
        <td class="px-4 py-3">
          <div class="flex items-center justify-end gap-1">
            <button class="p-1.5 rounded-lg text-blue-600 hover:bg-blue-50" title="Detail" @click="openDetail(row)"><EyeIcon class="w-4 h-4" /></button>
            <button class="p-1.5 rounded-lg text-amber-600 hover:bg-amber-50" title="Edit" @click="openEdit(row)"><PencilIcon class="w-4 h-4" /></button>
            <template v-if="row.status === 'PENDING'">
              <button class="p-1.5 rounded-lg text-green-600 hover:bg-green-50" title="Verifikasi" @click="verify(row, 'verify')"><CheckCircleIcon class="w-4 h-4" /></button>
              <button class="p-1.5 rounded-lg text-red-500 hover:bg-red-50" title="Tolak" @click="openReject(row)"><XCircleIcon class="w-4 h-4" /></button>
            </template>
            <button v-if="['PENDING', 'VERIFIED', 'REJECTED'].includes(row.status)" class="p-1.5 rounded-lg text-red-600 hover:bg-red-50" title="Hapus pembayaran" @click="deletePayment(row)"><TrashIcon class="w-4 h-4" /></button>
          </div>
        </td>
      </template>
    </DataTable>
  </div>

  <!-- Modal Detail Pembayaran -->
  <BaseModal :open="detailModal" title="Detail Pembayaran" size="lg" @close="detailModal = false">
    <div v-if="selectedPayment" class="space-y-4">
      <div class="grid grid-cols-2 gap-4 text-sm">
        <div><span class="text-xs text-gray-400">No. Pembayaran</span><p class="font-mono font-medium">{{ selectedPayment.payment_number }}</p></div>
        <div><span class="text-xs text-gray-400">Status</span><p><span :class="['px-2 py-0.5 rounded-full text-xs font-medium', statusColor[selectedPayment.status]]">{{ selectedPayment.status }}</span></p></div>
        <div><span class="text-xs text-gray-400">Mahasiswa</span><p class="font-medium">{{ selectedPayment.student?.name }}</p><p class="text-xs text-gray-500">{{ selectedPayment.student?.nim }}</p></div>
        <div><span class="text-xs text-gray-400">Invoice</span><p class="font-mono text-xs">{{ selectedPayment.invoice?.invoice_number }}</p></div>
        <div><span class="text-xs text-gray-400">Jumlah</span><p class="font-bold text-lg">{{ formatCurrency(selectedPayment.amount) }}</p></div>
        <div><span class="text-xs text-gray-400">Tanggal Bayar</span><p>{{ formatDate(selectedPayment.payment_date) }}</p></div>
        <div><span class="text-xs text-gray-400">Metode</span><p>{{ selectedPayment.payment_method }}</p></div>
        <div><span class="text-xs text-gray-400">No. Referensi</span><p class="font-mono text-xs">{{ selectedPayment.reference_number || '-' }}</p></div>
        <div><span class="text-xs text-gray-400">Nama Bank</span><p>{{ selectedPayment.bank_name || '-' }}</p></div>
        <div><span class="text-xs text-gray-400">Nama Rekening</span><p>{{ selectedPayment.account_name || '-' }}</p></div>
      </div>

      <!-- Bukti Pembayaran -->
      <div class="border border-gray-200 rounded-lg p-4">
        <p class="text-xs font-medium text-gray-700 mb-2">Bukti Pembayaran</p>
        <div v-if="selectedPayment.receipt_path">
          <div v-if="selectedPayment.receipt_path.endsWith('.pdf')" class="bg-gray-50 rounded-lg p-4 text-center">
            <p class="text-sm text-gray-600 mb-2">File PDF</p>
            <a :href="`${baseUrl}/storage/${selectedPayment.receipt_path}`" target="_blank" rel="noopener" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white text-sm rounded-lg inline-block">Buka PDF</a>
          </div>
          <div v-else>
            <img :src="`${baseUrl}/storage/${selectedPayment.receipt_path}`" alt="Bukti Pembayaran" class="w-full max-h-80 object-contain rounded-lg border" />
            <a :href="`${baseUrl}/storage/${selectedPayment.receipt_path}`" target="_blank" rel="noopener" class="text-xs text-blue-600 hover:underline mt-2 inline-block">Buka di tab baru ↗</a>
          </div>
        </div>
        <p v-else class="text-sm text-gray-400 italic">Tidak ada bukti pembayaran yang diupload.</p>
      </div>

      <!-- Catatan -->
      <div v-if="selectedPayment.note">
        <p class="text-xs font-medium text-gray-700">Catatan:</p>
        <p class="text-sm text-gray-600 mt-0.5">{{ selectedPayment.note }}</p>
      </div>
      <div v-if="selectedPayment.status === 'REJECTED' && selectedPayment.rejection_reason" class="rounded-lg border border-red-200 bg-red-50 p-3">
        <p class="text-xs font-semibold text-red-800">Catatan penolakan:</p>
        <p class="mt-1 whitespace-pre-wrap text-sm text-red-700">{{ selectedPayment.rejection_reason }}</p>
      </div>

      <!-- Aksi verifikasi dari modal -->
      <div v-if="selectedPayment.status === 'PENDING'" class="flex gap-2 pt-3 border-t border-gray-200">
        <button class="flex-1 px-4 py-2 bg-green-600 hover:bg-green-700 text-white text-sm font-medium rounded-lg" @click="verify(selectedPayment, 'verify'); detailModal = false">
          ✓ Verifikasi Pembayaran
        </button>
        <button class="flex-1 px-4 py-2 bg-red-600 hover:bg-red-700 text-white text-sm font-medium rounded-lg" @click="openReject(selectedPayment)">
          ✗ Tolak Pembayaran
        </button>
      </div>
      <div v-if="['PENDING', 'VERIFIED', 'REJECTED'].includes(selectedPayment.status)" class="flex justify-end pt-3 border-t border-gray-200">
        <button class="inline-flex items-center gap-2 px-4 py-2 bg-red-600 hover:bg-red-700 text-white text-sm font-medium rounded-lg" @click="deletePayment(selectedPayment)">
          <TrashIcon class="w-4 h-4" />
          Hapus Pembayaran {{ selectedPayment.status }}
        </button>
      </div>
    </div>
    <template #footer>
      <button class="px-4 py-2 text-sm text-gray-600 hover:bg-gray-100 rounded-lg" @click="detailModal = false">Tutup</button>
    </template>
  </BaseModal>
  <PaymentEditModal :open="editModal" :payment="selectedPayment" @close="editModal = false" @saved="load()" />
  <BaseModal :open="rejectionModal" title="Tolak Pembayaran" size="md" @close="rejectionModal = false">
    <div v-if="rejectionPayment" class="space-y-3">
      <p class="text-sm text-gray-600">
        Pembayaran <span class="font-mono font-medium">{{ rejectionPayment.payment_number }}</span>
        atas nama {{ rejectionPayment.student?.name }} akan ditolak.
      </p>
      <label for="payment-rejection-reason" class="block text-sm font-medium text-gray-700">
        Catatan penolakan <span class="text-red-500">*</span>
      </label>
      <textarea
        id="payment-rejection-reason"
        v-model="rejectionReason"
        required
        maxlength="2000"
        rows="4"
        placeholder="Jelaskan alasan penolakan dan hal yang perlu diperbaiki mahasiswa."
        class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-red-500"
      />
      <p class="text-xs text-gray-500">Catatan ini akan terlihat oleh mahasiswa pada riwayat pembayaran.</p>
    </div>
    <template #footer>
      <button class="px-4 py-2 text-sm text-gray-600 hover:bg-gray-100 rounded-lg" @click="rejectionModal = false">Batal</button>
      <button
        :disabled="!rejectionReason.trim() || rejecting"
        class="px-4 py-2 rounded-lg bg-red-600 text-sm font-medium text-white hover:bg-red-700 disabled:cursor-not-allowed disabled:bg-red-300"
        @click="rejectPayment"
      >{{ rejecting ? 'Menyimpan...' : 'Tolak Pembayaran' }}</button>
    </template>
  </BaseModal>
</template>
