<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { ArrowDownTrayIcon } from '@heroicons/vue/24/outline'
import { useToast } from 'vue-toastification'
import api from '@/services/api'

const toast = useToast()
const loading = ref(true)
const downloadingId = ref<number | null>(null)
const payrolls = ref<any[]>([])

onMounted(async () => {
  try {
    payrolls.value = (await api.get('/finance/honor/my-payrolls')).data.data
  } catch (error: any) {
    toast.error(error?.response?.data?.message ?? 'Data honor gagal dimuat.')
  } finally {
    loading.value = false
  }
})

async function downloadSlip(payroll: any) {
  downloadingId.value = payroll.id
  try {
    const response = await api.get(`/finance/honor/my-payrolls/${payroll.id}/pdf`, { responseType: 'blob' })
    const url = URL.createObjectURL(response.data)
    const link = document.createElement('a')
    link.href = url
    link.download = `slip-honor-${payroll.period_key}.pdf`
    document.body.appendChild(link)
    link.click()
    link.remove()
    URL.revokeObjectURL(url)
  } catch (error: any) {
    toast.error(error?.response?.data?.message ?? 'Slip honor gagal diunduh.')
  } finally {
    downloadingId.value = null
  }
}

function formatCurrency(value: number | string) {
  return new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', maximumFractionDigits: 0 }).format(Number(value || 0))
}
</script>

<template>
  <div class="space-y-5">
    <div>
      <h1 class="text-xl font-bold text-gray-900">Honor Saya</h1>
      <p class="mt-1 text-sm text-gray-500">Rincian honor bulanan atau semester yang telah dibayarkan dan slip pribadi Anda.</p>
    </div>

    <div v-if="loading" class="py-12 text-center text-sm text-gray-400">Memuat riwayat honor...</div>
    <div v-else-if="!payrolls.length" class="rounded-xl border border-dashed border-gray-300 bg-white p-8 text-center text-sm text-gray-500">Belum ada honor yang telah dibayarkan.</div>
    <div v-else class="space-y-4">
      <section v-for="payroll in payrolls" :key="payroll.id" class="overflow-hidden rounded-xl border border-gray-200 bg-white">
        <header class="flex flex-wrap items-center justify-between gap-3 border-b border-gray-100 px-4 py-4">
          <div>
            <h2 class="font-semibold text-gray-900">{{ payroll.period_type === 'MONTHLY' ? new Date(`${payroll.period_start}T00:00:00`).toLocaleDateString('id-ID', { month: 'long', year: 'numeric' }) : payroll.period_type === 'INCIDENTAL' ? `Insidental · ${new Date(`${payroll.period_start}T00:00:00`).toLocaleDateString('id-ID')}` : payroll.semester?.name }}</h2>
            <p class="mt-1 text-xs text-gray-500">Dibayarkan {{ new Date(payroll.paid_at).toLocaleDateString('id-ID') }} · {{ payroll.items.length }} rincian</p>
          </div>
          <div class="flex items-center gap-3">
            <div class="text-right">
              <strong class="block text-sm text-gray-900">{{ formatCurrency(payroll.total_amount) }}</strong>
              <span class="text-[11px] text-gray-500">Bersih dibayarkan</span>
            </div>
            <button :disabled="downloadingId === payroll.id" class="flex items-center gap-2 rounded-lg bg-blue-600 px-3 py-2 text-xs font-medium text-white disabled:opacity-50" @click="downloadSlip(payroll)">
              <ArrowDownTrayIcon class="h-4 w-4" /> {{ downloadingId === payroll.id ? 'Menyiapkan...' : 'Unduh slip' }}
            </button>
          </div>
        </header>
        <div class="overflow-x-auto">
          <table class="min-w-full divide-y divide-gray-100 text-sm">
            <thead class="bg-gray-50 text-left text-xs uppercase text-gray-500"><tr><th class="px-4 py-3">Aktivitas</th><th class="px-4 py-3">Tanggal</th><th class="px-4 py-3">Jumlah × Tarif</th><th class="px-4 py-3 text-right">Honor bruto</th></tr></thead>
            <tbody class="divide-y divide-gray-100">
              <tr v-for="item in payroll.items" :key="item.id"><td class="px-4 py-3">{{ item.description }}</td><td class="px-4 py-3 text-xs text-gray-600">{{ item.activity_date }}</td><td class="px-4 py-3 text-xs text-gray-600">{{ item.quantity }} × {{ formatCurrency(item.unit_rate_snapshot) }}</td><td class="px-4 py-3 text-right font-medium">{{ formatCurrency(item.amount_snapshot) }}</td></tr>
              <tr class="bg-gray-50"><td colspan="3" class="px-4 py-2 text-right text-xs text-gray-600">Honor bruto</td><td class="px-4 py-2 text-right text-xs font-medium">{{ formatCurrency(payroll.gross_amount) }}</td></tr>
              <tr v-for="deduction in payroll.deductions ?? []" :key="`deduction-${deduction.id}`"><td colspan="3" class="px-4 py-2 text-right text-xs text-red-700">{{ deduction.deduction_type === 'TAX' ? 'Pajak' : 'Potongan lainnya' }} · {{ deduction.description }}</td><td class="px-4 py-2 text-right text-xs text-red-700">− {{ formatCurrency(deduction.amount) }}</td></tr>
              <tr class="bg-green-50"><td colspan="3" class="px-4 py-2 text-right text-xs font-semibold text-green-800">Bersih dibayarkan</td><td class="px-4 py-2 text-right text-xs font-semibold text-green-800">{{ formatCurrency(payroll.total_amount) }}</td></tr>
            </tbody>
          </table>
        </div>
      </section>
    </div>
  </div>
</template>
