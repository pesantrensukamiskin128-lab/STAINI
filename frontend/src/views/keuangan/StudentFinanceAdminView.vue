<script setup lang="ts">
import { ref, onMounted, computed } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { ArrowDownTrayIcon, ArrowLeftIcon, PencilIcon } from '@heroicons/vue/24/outline'
import { useToast } from 'vue-toastification'
import { useAuthStore } from '@/stores/auth'
import api from '@/services/api'
import InvoiceEditModal from '@/components/finance/InvoiceEditModal.vue'
import PaymentEditModal from '@/components/finance/PaymentEditModal.vue'

const route = useRoute()
const router = useRouter()
const auth = useAuthStore()
const toast = useToast()
const loading = ref(true)
const downloading = ref(false)
const data = ref<any>(null)
const editInvoice = ref<any>(null)
const editPayment = ref<any>(null)
const canEdit = auth.hasEffectiveAccess(['SUPER_ADMIN', 'ADMIN_KEUANGAN', 'ADMIN_AKADEMIK'])

const outstanding = computed(() => Number(data.value?.summary?.outstanding ?? 0))
const statusLabel: Record<string, string> = { UNPAID: 'Belum Bayar', PARTIAL: 'Sebagian', PAID: 'Lunas', OVERDUE: 'Jatuh Tempo', CANCELLED: 'Dibatalkan', WAIVED: 'Dibebaskan' }
const statusColor: Record<string, string> = { UNPAID: 'bg-red-100 text-red-700', PARTIAL: 'bg-yellow-100 text-yellow-700', PAID: 'bg-green-100 text-green-700', OVERDUE: 'bg-red-200 text-red-800', CANCELLED: 'bg-gray-100 text-gray-500', WAIVED: 'bg-blue-100 text-blue-700' }
const paymentColor: Record<string, string> = { PENDING: 'bg-yellow-100 text-yellow-700', VERIFIED: 'bg-green-100 text-green-700', REJECTED: 'bg-red-100 text-red-700', REFUNDED: 'bg-purple-100 text-purple-700' }

onMounted(load)

async function load() {
  try {
    const response = await api.get(`/finance/students/${route.params.studentId}/summary`)
    data.value = response.data
  } catch (e: any) {
    toast.error(e?.response?.data?.message ?? 'Gagal memuat data keuangan mahasiswa.')
  } finally { loading.value = false }
}

async function downloadPdf() {
  downloading.value = true
  try {
    const response = await api.get(`/finance/students/${route.params.studentId}/summary/pdf`, { responseType: 'blob' })
    const url = URL.createObjectURL(response.data)
    const link = document.createElement('a')
    link.href = url
    link.download = `keuangan-${data.value?.student?.nim ?? route.params.studentId}.pdf`
    link.click()
    URL.revokeObjectURL(url)
  } catch (e: any) {
    toast.error(e?.response?.data?.message ?? 'Gagal mengunduh laporan PDF.')
  } finally { downloading.value = false }
}

function currency(value: number) { return new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', minimumFractionDigits: 0 }).format(Number(value ?? 0)) }
function date(value: string) { return value ? new Date(value).toLocaleDateString('id-ID', { day: 'numeric', month: 'short', year: 'numeric' }) : '-' }
</script>

<template>
  <div v-if="loading" class="flex items-center justify-center h-64"><p class="text-gray-400">Memuat data keuangan...</p></div>
  <div v-else-if="data" class="space-y-6 max-w-6xl mx-auto">
    <div class="flex items-center gap-3">
      <button class="p-2 rounded-lg hover:bg-gray-100" @click="router.back()"><ArrowLeftIcon class="w-5 h-5 text-gray-500" /></button>
      <div class="flex-1">
        <h1 class="text-xl font-bold text-gray-900">Keuangan Mahasiswa</h1>
        <p class="text-sm text-gray-500">{{ data.student.name }} · {{ data.student.nim }} · {{ data.student.study_program?.name ?? '-' }}</p>
      </div>
      <button class="flex items-center gap-2 px-4 py-2 bg-red-600 hover:bg-red-700 disabled:bg-red-300 text-white text-sm font-medium rounded-lg" :disabled="downloading" @click="downloadPdf">
        <ArrowDownTrayIcon class="w-4 h-4" /> {{ downloading ? 'Menyiapkan...' : 'Export PDF' }}
      </button>
    </div>

    <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
      <div v-for="item in [{ label: 'Total Tagihan', value: data.summary.total_invoiced, color: 'text-gray-900' }, { label: 'Total Dibayar', value: data.summary.total_paid, color: 'text-green-700' }, { label: 'Diskon/Beasiswa', value: data.summary.total_discount + data.summary.total_scholarship, color: 'text-blue-700' }, { label: 'Sisa Tagihan', value: outstanding, color: outstanding > 0 ? 'text-red-700' : 'text-green-700' }]" :key="item.label" class="bg-white rounded-xl border border-gray-200 p-4">
        <p class="text-xs text-gray-500">{{ item.label }}</p><p :class="['text-lg font-bold mt-1', item.color]">{{ currency(item.value) }}</p>
      </div>
    </div>

    <section class="bg-white rounded-xl border border-gray-200 p-5">
      <h2 class="text-sm font-semibold text-gray-800 mb-3">Seluruh Tagihan</h2>
      <div class="overflow-x-auto">
        <table class="w-full text-sm"><thead><tr class="text-left text-xs text-gray-400 border-b"><th class="pb-2">Invoice</th><th class="pb-2">Semester</th><th class="pb-2">Jatuh Tempo</th><th class="pb-2 text-right">Total</th><th class="pb-2 text-right">Dibayar</th><th class="pb-2">Status</th></tr></thead>
          <tbody><tr v-for="invoice in data.invoices" :key="invoice.id" class="border-b border-gray-50"><td class="py-3"><button class="font-mono text-xs text-blue-700 hover:underline" @click="router.push(`/keuangan/tagihan/${invoice.id}`)">{{ invoice.invoice_number }}</button></td><td class="py-3 text-gray-600">{{ invoice.semester?.name ?? '-' }}</td><td class="py-3 text-gray-600">{{ date(invoice.due_date) }}</td><td class="py-3 text-right">{{ currency(invoice.total_amount) }}</td><td class="py-3 text-right text-green-700">{{ currency(invoice.paid_amount) }}</td><td class="py-3"><span :class="['px-2 py-0.5 rounded-full text-xs font-medium', statusColor[invoice.status]]">{{ statusLabel[invoice.status] ?? invoice.status }}</span></td><td v-if="canEdit" class="py-3 text-right"><button class="p-1 text-amber-600 hover:bg-amber-50 rounded" title="Edit tagihan" @click="editInvoice = invoice"><PencilIcon class="w-4 h-4" /></button></td></tr><tr v-if="!data.invoices.length"><td :colspan="canEdit ? 7 : 6" class="py-6 text-center text-gray-400">Belum ada tagihan.</td></tr></tbody>
        </table>
      </div>
    </section>

    <section class="bg-white rounded-xl border border-gray-200 p-5">
      <h2 class="text-sm font-semibold text-gray-800 mb-3">Riwayat Pembayaran</h2>
      <div class="overflow-x-auto">
        <table class="w-full text-sm"><thead><tr class="text-left text-xs text-gray-400 border-b"><th class="pb-2">No. Pembayaran</th><th class="pb-2">Invoice</th><th class="pb-2">Tanggal</th><th class="pb-2">Metode</th><th class="pb-2 text-right">Jumlah</th><th class="pb-2">Status</th></tr></thead>
          <tbody><tr v-for="payment in data.payments" :key="payment.id" class="border-b border-gray-50"><td class="py-3 font-mono text-xs">{{ payment.payment_number }}</td><td class="py-3 text-gray-600">{{ payment.invoice?.invoice_number ?? '-' }}</td><td class="py-3 text-gray-600">{{ date(payment.payment_date) }}</td><td class="py-3 text-gray-600">{{ payment.payment_method }}</td><td class="py-3 text-right">{{ currency(payment.amount) }}</td><td class="py-3"><span :class="['px-2 py-0.5 rounded-full text-xs font-medium', paymentColor[payment.status]]">{{ payment.status }}</span></td><td v-if="canEdit" class="py-3 text-right"><button class="p-1 text-amber-600 hover:bg-amber-50 rounded" title="Edit pembayaran" @click="editPayment = payment"><PencilIcon class="w-4 h-4" /></button></td></tr><tr v-if="!data.payments.length"><td :colspan="canEdit ? 7 : 6" class="py-6 text-center text-gray-400">Belum ada pembayaran.</td></tr></tbody>
        </table>
      </div>
    </section>
  </div>
  <InvoiceEditModal v-if="canEdit" :open="!!editInvoice" :invoice="editInvoice" @close="editInvoice = null" @saved="load" />
  <PaymentEditModal v-if="canEdit" :open="!!editPayment" :payment="editPayment" @close="editPayment = null" @saved="load" />
</template>
