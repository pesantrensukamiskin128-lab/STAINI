<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { PlusIcon, ArrowPathIcon, PencilIcon, TrashIcon } from '@heroicons/vue/24/outline'
import { useToast } from 'vue-toastification'
import api from '@/services/api'

type RateCategory = 'TEACHING' | 'TEACHING_SKS' | 'STRUCTURAL' | 'ADVISOR' | 'INCIDENTAL' | 'STAFF_MONTHLY'
type Tab = 'rates' | 'activities' | 'payrolls'

const toast = useToast()
const activeTab = ref<Tab>('rates')
const loading = ref(false)
const saving = ref(false)
const rates = ref<any[]>([])
const activities = ref<any[]>([])
const activityPage = ref(1)
const activityLastPage = ref(1)
const payrolls = ref<any[]>([])
const lecturers = ref<any[]>([])
const semesters = ref<any[]>([])
const positionOptions = ref<Record<string, string>>({})
const selectedPayroll = ref<any>(null)
const editingRateId = ref<number | null>(null)
const editingActivityId = ref<number | null>(null)
const editingVerifiedActivity = ref(false)
const editingPayrollId = ref<number | null>(null)

const rateForm = reactive({
  name: '',
  category: 'TEACHING' as RateCategory,
  position_code: '',
  delivery_mode: 'ONSITE',
  amount: '',
  effective_from: new Date().toISOString().slice(0, 10),
  effective_until: '',
})
const activityForm = reactive({
  lecturer_id: '',
  honor_rate_id: '',
  reference_number: '',
  description: '',
  activity_date: new Date().toISOString().slice(0, 10),
  quantity: 1,
  semester_id: '',
})
const periodForm = reactive({
  period_type: 'MONTHLY',
  year: new Date().getFullYear(),
  month: new Date().getMonth() + 1,
  semester_id: '',
  activity_date: new Date().toISOString().slice(0, 10),
})
const deductionForm = reactive({
  recipient: '',
  deduction_type: 'TAX',
  description: '',
  amount: '',
})

const unitByCategory: Record<RateCategory, string> = {
  TEACHING: 'PER_MEETING',
  TEACHING_SKS: 'PER_SKS',
  STRUCTURAL: 'MONTHLY',
  ADVISOR: 'PER_STUDENT',
  INCIDENTAL: 'PER_ACTIVITY',
  STAFF_MONTHLY: 'MONTHLY',
}
const unitLabels: Record<string, string> = {
  PER_MEETING: 'per pertemuan',
  PER_SKS: 'per SKS per pertemuan',
  MONTHLY: 'per bulan',
  PER_STUDENT: 'per mahasiswa per semester',
  PER_ACTIVITY: 'per aktivitas',
}
const categoryLabels: Record<string, string> = {
  TEACHING: 'Mengajar',
  TEACHING_SKS: 'Mengajar per SKS',
  STRUCTURAL: 'Jabatan struktural',
  ADVISOR: 'Pembimbing akademik',
  INCIDENTAL: 'Insidental',
  STAFF_MONTHLY: 'Tenaga kependidikan',
}
const incidentRates = computed(() => rates.value.filter(rate =>
  rate.category === 'INCIDENTAL'
  && rate.effective_from <= activityForm.activity_date
  && (!rate.effective_until || rate.effective_until >= activityForm.activity_date),
))
const rateFilter = reactive({ search: '', category: '', status: '' })
const filteredRates = computed(() => {
  const term = rateFilter.search.trim().toLowerCase()
  return rates.value.filter(rate =>
    (!rateFilter.category || rate.category === rateFilter.category)
    && (!rateFilter.status || (rateFilter.status === 'ACTIVE') === !!rate.is_active)
    && (!term || [rate.name, rate.position_code, positionOptions.value[rate.position_code]].some(value => String(value ?? '').toLowerCase().includes(term))),
  )
})
const activityFilter = reactive({ search: '', status: '', honor_rate_id: '', date_from: '', date_to: '' })
const payrollSearch = ref('')
const downloadingPdf = ref(false)
const payrollSummary = computed(() => {
  type Line = { label: string; quantity: number; rate: number; amount: number; sks?: boolean }
  const groups = new Map<string, { key: string; name: string; nip?: string; lines: Line[]; total: number }>()
  const teaching = new Map<string, Line & { meetings: number; lineKey: string }>()
  for (const item of selectedPayroll.value?.items ?? []) {
    const type = item.lecturer_id ? 'lecturer' : 'staff'
    const id = item.lecturer_id ?? item.staff_id
    const key = `${type}:${id}`
    let group = groups.get(key)
    if (!group) {
      group = {
        key,
        name: item.lecturer?.name ?? item.lecturer?.full_name ?? item.staff?.name ?? `Penerima ${id}`,
        nip: item.staff?.nip,
        lines: [],
        total: 0,
      }
      groups.set(key, group)
    }
    const amount = Number(item.amount_snapshot)
    group.total += amount
    const category = item.rate?.category
    if (category === 'TEACHING' || category === 'TEACHING_SKS') {
      const lineKey = `${key}:${item.honor_rate_id}`
      const line = teaching.get(lineKey) ?? {
        lineKey,
        label: '',
        quantity: 0,
        rate: Number(item.unit_rate_snapshot),
        amount: 0,
        meetings: 0,
        sks: category === 'TEACHING_SKS',
      }
      line.quantity += Number(item.quantity)
      line.amount += amount
      line.meetings += 1
      line.label = category === 'TEACHING_SKS'
        ? `Mengajar ${item.rate?.delivery_mode === 'ONLINE' ? 'daring' : 'luring'} — ${line.meetings} pertemuan, ${line.quantity} SKS`
        : `Mengajar — ${line.meetings} pertemuan`
      if (!teaching.has(lineKey)) {
        teaching.set(lineKey, line)
        group.lines.push(line)
      }
    } else {
      group.lines.push({
        label: item.description,
        quantity: Number(item.quantity),
        rate: Number(item.unit_rate_snapshot),
        amount,
      })
    }
  }
  const list = [...groups.values()].sort((a, b) => a.name.localeCompare(b.name, 'id'))
  const term = payrollSearch.value.trim().toLowerCase()
  const visible = term
    ? list.filter(group => [group.name, group.nip, ...group.lines.map(line => line.label)].some(value => String(value ?? '').toLowerCase().includes(term)))
    : list
  return { list: visible, grandTotal: visible.reduce((sum, g) => sum + g.total, 0) }
})
const payrollRecipients = computed(() => {
  const recipients = new Map<string, { key: string; name: string }>()
  for (const item of selectedPayroll.value?.items ?? []) {
    const type = item.lecturer_id ? 'lecturer' : item.staff_id ? 'staff' : null
    const id = item.lecturer_id ?? item.staff_id
    if (!type || !id) continue
    const key = `${type}:${id}`
    recipients.set(key, {
      key,
      name: item.lecturer?.name ?? item.lecturer?.full_name ?? item.staff?.name ?? `Penerima ${id}`,
    })
  }
  return [...recipients.values()]
})
const selectedPeriodEnded = computed(() => {
  if (!selectedPayroll.value?.period_end) return false
  const today = new Date()
  const todayLocal = `${today.getFullYear()}-${String(today.getMonth() + 1).padStart(2, '0')}-${String(today.getDate()).padStart(2, '0')}`
  return selectedPayroll.value.period_end <= todayLocal
})

watch(() => rateForm.category, () => {
  if (!editingRateId.value) rateForm.position_code = ''
})

onMounted(() => loadAll())

async function loadAll() {
  loading.value = true
  try {
    const [rateResponse, activityResponse, payrollResponse, lecturerResponse, semesterResponse, positionResponse] = await Promise.all([
      api.get('/finance/honor/rates'),
      api.get('/finance/honor/activities', { params: activityParams(activityPage.value) }),
      api.get('/finance/honor/payrolls'),
      api.get('/lecturers/all'),
      api.get('/semesters', { params: { per_page: 100 } }),
      api.get('/lecturer-positions/available'),
    ])
    rates.value = rateResponse.data
    activities.value = activityResponse.data.data
    activityLastPage.value = activityResponse.data.last_page
    payrolls.value = payrollResponse.data.data
    lecturers.value = lecturerResponse.data
    semesters.value = semesterResponse.data.data
    positionOptions.value = positionResponse.data
    if (selectedPayroll.value) {
      const exists = payrolls.value.find(item => item.id === selectedPayroll.value.id)
      if (exists) await loadPayroll(exists.id)
    }
  } catch (error: any) {
    toast.error(error?.response?.data?.message ?? 'Data modul honor gagal dimuat.')
  } finally {
    loading.value = false
  }
}

function activityParams(page: number) {
  const params: Record<string, any> = { per_page: 100, page }
  for (const [key, value] of Object.entries(activityFilter)) {
    if (String(value).trim()) params[key] = String(value).trim()
  }
  return params
}

async function applyActivityFilter() {
  await changeActivityPage(1)
}

function resetActivityFilter() {
  Object.assign(activityFilter, { search: '', status: '', honor_rate_id: '', date_from: '', date_to: '' })
  applyActivityFilter()
}

async function downloadPayrollPdf() {
  if (!selectedPayroll.value) return
  downloadingPdf.value = true
  try {
    const response = await api.get(`/finance/honor/payrolls/${selectedPayroll.value.id}/pdf`, {
      params: payrollSearch.value.trim() ? { search: payrollSearch.value.trim() } : {},
      responseType: 'blob',
    })
    const url = URL.createObjectURL(response.data)
    const link = document.createElement('a')
    link.href = url
    link.download = `rincian-honor-${selectedPayroll.value.period_key}.pdf`
    document.body.appendChild(link)
    link.click()
    link.remove()
    URL.revokeObjectURL(url)
  } catch (error: any) {
    toast.error('Rincian honor gagal diunduh sebagai PDF.')
  } finally {
    downloadingPdf.value = false
  }
}

async function changeActivityPage(page: number) {
  if (page < 1 || (page > activityLastPage.value && page !== 1)) return
  activityPage.value = page
  try {
    const { data } = await api.get('/finance/honor/activities', { params: activityParams(page) })
    activities.value = data.data
    activityLastPage.value = data.last_page
  } catch (error: any) {
    toast.error(error?.response?.data?.message ?? 'Daftar aktivitas gagal dimuat.')
  }
}

async function saveRate() {
  saving.value = true
  try {
    const payload = {
      name: rateForm.name,
      category: rateForm.category,
      unit: unitByCategory[rateForm.category],
      position_code: ['STRUCTURAL', 'STAFF_MONTHLY'].includes(rateForm.category) ? (rateForm.position_code || null) : null,
      delivery_mode: ['TEACHING', 'TEACHING_SKS'].includes(rateForm.category) ? rateForm.delivery_mode : null,
      amount: Number(rateForm.amount),
      effective_from: rateForm.effective_from,
      effective_until: rateForm.effective_until || null,
    }
    if (editingRateId.value) {
      await api.put(`/finance/honor/rates/${editingRateId.value}`, payload)
      toast.success('Tarif honor berhasil diperbarui.')
      cancelRateEdit()
    } else {
      await api.post('/finance/honor/rates', payload)
      rateForm.name = ''
      rateForm.amount = ''
      toast.success('Tarif honor tersimpan.')
    }
    await loadAll()
  } catch (error: any) {
    toast.error(firstError(error) ?? 'Tarif honor gagal disimpan.')
  } finally {
    saving.value = false
  }
}

function editRate(rate: any) {
  editingRateId.value = rate.id
  Object.assign(rateForm, {
    name: rate.name,
    category: rate.category,
    position_code: rate.position_code ?? '',
    delivery_mode: rate.delivery_mode ?? 'ONSITE',
    amount: String(rate.amount),
    effective_from: rate.effective_from,
    effective_until: rate.effective_until ?? '',
  })
  activeTab.value = 'rates'
  window.scrollTo({ top: 0, behavior: 'smooth' })
}

function cancelRateEdit() {
  editingRateId.value = null
  Object.assign(rateForm, {
    name: '',
    category: 'TEACHING',
    position_code: '',
    delivery_mode: 'ONSITE',
    amount: '',
    effective_from: new Date().toISOString().slice(0, 10),
    effective_until: '',
  })
}

async function deleteRate(rate: any) {
  if (!confirm(`Hapus tarif "${rate.name}"? Tarif yang sudah dipakai tidak dapat dihapus.`)) return
  try {
    await api.delete(`/finance/honor/rates/${rate.id}`)
    toast.success('Tarif honor berhasil dihapus.')
    if (editingRateId.value === rate.id) cancelRateEdit()
    await loadAll()
  } catch (error: any) {
    toast.error(error?.response?.data?.message ?? 'Tarif honor gagal dihapus.')
  }
}

async function toggleRate(rate: any) {
  if (!rate.is_active) return
  if (!confirm(`Akhiri tarif "${rate.name}"? Riwayat tarif yang sudah dipakai tetap tersimpan.`)) return
  const today = new Date()
  const endDateDefault = `${today.getFullYear()}-${String(today.getMonth() + 1).padStart(2, '0')}-${String(today.getDate()).padStart(2, '0')}`
  const effective_until = window.prompt('Tanggal terakhir tarif ini berlaku (YYYY-MM-DD):', rate.effective_until ?? endDateDefault)
  if (!effective_until) return
  if (!/^\d{4}-\d{2}-\d{2}$/.test(effective_until)) {
    toast.error('Format tanggal harus YYYY-MM-DD.')
    return
  }
  try {
    await api.patch(`/finance/honor/rates/${rate.id}`, { is_active: false, effective_until })
    toast.success('Status tarif diperbarui.')
    await loadAll()
  } catch (error: any) {
    toast.error(error?.response?.data?.message ?? 'Status tarif gagal diperbarui.')
  }
}

async function saveActivity() {
  saving.value = true
  try {
    const payload = {
      ...activityForm,
      lecturer_id: Number(activityForm.lecturer_id),
      honor_rate_id: Number(activityForm.honor_rate_id),
      semester_id: activityForm.semester_id ? Number(activityForm.semester_id) : null,
    }
    if (editingActivityId.value) {
      await api.put(`/finance/honor/activities/${editingActivityId.value}`, payload)
      toast.success('Aktivitas diperbarui dan menunggu verifikasi ulang.')
      cancelActivityEdit()
    } else {
      await api.post('/finance/honor/activities', payload)
      Object.assign(activityForm, {
        lecturer_id: '', honor_rate_id: '', reference_number: '',
        description: '', quantity: 1, semester_id: '',
      })
      toast.success('Aktivitas dicatat dan menunggu verifikasi.')
    }
    await loadAll()
    activeTab.value = 'activities'
  } catch (error: any) {
    toast.error(firstError(error) ?? 'Aktivitas gagal dicatat.')
  } finally {
    saving.value = false
  }
}

function editActivity(activity: any) {
  editingActivityId.value = activity.id
  editingVerifiedActivity.value = activity.status === 'VERIFIED'
  Object.assign(activityForm, {
    lecturer_id: String(activity.lecturer_id),
    honor_rate_id: String(activity.honor_rate_id),
    reference_number: activity.reference_number ?? '',
    description: activity.description,
    activity_date: activity.activity_date,
    quantity: Number(activity.quantity),
    semester_id: activity.semester_id ? String(activity.semester_id) : '',
  })
  window.scrollTo({ top: 0, behavior: 'smooth' })
}

function cancelActivityEdit() {
  editingActivityId.value = null
  editingVerifiedActivity.value = false
  Object.assign(activityForm, {
    lecturer_id: '',
    honor_rate_id: '',
    reference_number: '',
    description: '',
    activity_date: new Date().toISOString().slice(0, 10),
    quantity: 1,
    semester_id: '',
  })
}

async function deleteActivity(activity: any) {
  if (!confirm(`Hapus aktivitas "${activity.description}"?`)) return
  try {
    await api.delete(`/finance/honor/activities/${activity.id}`)
    toast.success('Aktivitas honor berhasil dihapus.')
    if (editingActivityId.value === activity.id) cancelActivityEdit()
    await loadAll()
  } catch (error: any) {
    toast.error(error?.response?.data?.message ?? 'Aktivitas honor gagal dihapus.')
  }
}

async function verifyActivity(activity: any, status: 'VERIFIED' | 'REJECTED') {
  const prompt = status === 'VERIFIED' ? 'Verifikasi aktivitas ini?' : 'Tolak aktivitas ini?'
  if (!confirm(prompt)) return
  try {
    await api.post(`/finance/honor/activities/${activity.id}/verify`, { status })
    toast.success(status === 'VERIFIED' ? 'Aktivitas diverifikasi.' : 'Aktivitas ditolak.')
    await loadAll()
  } catch (error: any) {
    toast.error(error?.response?.data?.message ?? 'Verifikasi gagal.')
  }
}

async function createPayroll() {
  saving.value = true
  try {
    const payload = periodForm.period_type === 'MONTHLY'
      ? { period_type: 'MONTHLY', year: Number(periodForm.year), month: Number(periodForm.month) }
      : periodForm.period_type === 'SEMESTER'
        ? { period_type: 'SEMESTER', semester_id: Number(periodForm.semester_id) }
        : { period_type: 'INCIDENTAL', activity_date: periodForm.activity_date }
    const { data } = editingPayrollId.value
      ? await api.put(`/finance/honor/payrolls/${editingPayrollId.value}`, payload)
      : await api.post('/finance/honor/payrolls', payload)
    if (editingPayrollId.value) toast.success(data.message ?? 'Periode honor berhasil diperbarui.')
    editingPayrollId.value = null
    await loadAll()
    await loadPayroll(data.data.id)
    activeTab.value = 'payrolls'
  } catch (error: any) {
    toast.error(firstError(error) ?? 'Periode honor gagal dibuat.')
  } finally {
    saving.value = false
  }
}

function editPayroll(payroll: any) {
  if (payroll.status !== 'DRAFT') return
  editingPayrollId.value = payroll.id
  if (payroll.period_type === 'MONTHLY') {
    const [year, month] = payroll.period_key.replace('MONTHLY-', '').split('-').map(Number)
    Object.assign(periodForm, { period_type: 'MONTHLY', year, month, semester_id: '' })
  } else if (payroll.period_type === 'SEMESTER') {
    Object.assign(periodForm, { period_type: 'SEMESTER', semester_id: String(payroll.semester_id) })
  } else {
    Object.assign(periodForm, { period_type: 'INCIDENTAL', activity_date: payroll.period_start })
  }
  activeTab.value = 'payrolls'
  window.scrollTo({ top: 0, behavior: 'smooth' })
}

function cancelPayrollEdit() {
  editingPayrollId.value = null
  Object.assign(periodForm, {
    period_type: 'MONTHLY',
    year: new Date().getFullYear(),
    month: new Date().getMonth() + 1,
    semester_id: '',
    activity_date: new Date().toISOString().slice(0, 10),
  })
}

async function deletePayroll(payroll: any) {
  const status = String(payroll.status ?? '').trim().toUpperCase()
  if (!['DRAFT', 'PAID'].includes(status)) return
  const paidWarning = status === 'PAID'
    ? ' Perhatian: catatan pembayaran, referensi bukti, dan seluruh snapshot honor akan ikut dihapus permanen.'
    : ' Seluruh snapshot draft pada periode ini akan ikut dihapus.'
  if (!confirm(`Hapus periode ${payroll.period_key}?${paidWarning} Tindakan ini tidak dapat dibatalkan.`)) return
  try {
    await api.delete(`/finance/honor/payrolls/${payroll.id}`)
    toast.success('Periode honor berhasil dihapus.')
    if (selectedPayroll.value?.id === payroll.id) selectedPayroll.value = null
    if (editingPayrollId.value === payroll.id) cancelPayrollEdit()
    await loadAll()
  } catch (error: any) {
    toast.error(error?.response?.data?.message ?? 'Periode honor gagal dihapus.')
  }
}

function canDeletePayroll(payroll: any) {
  return ['DRAFT', 'PAID'].includes(String(payroll.status ?? '').trim().toUpperCase())
}

async function addPayrollDeduction() {
  if (!selectedPayroll.value || selectedPayroll.value.status !== 'DRAFT') return
  const [recipient_type, recipientId] = deductionForm.recipient.split(':')
  if (!recipient_type || !recipientId) {
    toast.error('Pilih penerima potongan.')
    return
  }
  saving.value = true
  try {
    await api.post(`/finance/honor/payrolls/${selectedPayroll.value.id}/deductions`, {
      recipient_type,
      recipient_id: Number(recipientId),
      deduction_type: deductionForm.deduction_type,
      description: deductionForm.description,
      amount: Number(deductionForm.amount),
    })
    deductionForm.description = ''
    deductionForm.amount = ''
    toast.success('Potongan ditambahkan.')
    await loadAll()
  } catch (error: any) {
    toast.error(firstError(error) ?? 'Potongan honor gagal ditambahkan.')
  } finally {
    saving.value = false
  }
}

async function deletePayrollDeduction(deduction: any) {
  if (!selectedPayroll.value || selectedPayroll.value.status !== 'DRAFT') return
  if (!confirm(`Hapus potongan "${deduction.description}" sebesar ${formatCurrency(deduction.amount)}?`)) return
  try {
    await api.delete(`/finance/honor/payrolls/${selectedPayroll.value.id}/deductions/${deduction.id}`)
    toast.success('Potongan dihapus.')
    await loadAll()
  } catch (error: any) {
    toast.error(error?.response?.data?.message ?? 'Potongan honor gagal dihapus.')
  }
}
async function loadPayroll(id: number) {
  try {
    selectedPayroll.value = (await api.get(`/finance/honor/payrolls/${id}`)).data.data
  } catch (error: any) {
    toast.error(error?.response?.data?.message ?? 'Rincian periode gagal dimuat.')
  }
}

async function calculatePayroll() {
  if (!selectedPayroll.value || !confirm('Hitung ulang draft dari seluruh aktivitas yang telah diverifikasi? Snapshot draft sebelumnya akan diganti.')) return
  saving.value = true
  try {
    const { data } = await api.post(`/finance/honor/payrolls/${selectedPayroll.value.id}/calculate`)
    selectedPayroll.value = data.data
    selectedPayroll.value.total_amount = data.summary.total_amount
    selectedPayroll.value.pending_activity_count = data.summary.pending_activity_count
    toast.success(`${data.summary.item_count} rincian dihitung dari aktivitas terverifikasi.`)
    await loadAll()
  } catch (error: any) {
    toast.error(firstError(error) ?? 'Perhitungan honor gagal.')
  } finally {
    saving.value = false
  }
}

async function approvePayroll() {
  if (!selectedPayroll.value || !confirm('Sahkan periode ini? Snapshot honor tidak dapat dihitung ulang setelah disahkan.')) return
  try {
    await api.post(`/finance/honor/payrolls/${selectedPayroll.value.id}/approve`)
    toast.success('Periode honor disahkan.')
    await loadAll()
    await loadPayroll(selectedPayroll.value.id)
  } catch (error: any) {
    toast.error(error?.response?.data?.message ?? 'Periode honor gagal disahkan.')
  }
}

async function markPaid() {
  if (!selectedPayroll.value || !confirm('Tandai seluruh honor pada periode ini sudah dibayar?')) return
  const payment_reference = window.prompt('Referensi pembayaran (opsional):') ?? ''
  try {
    await api.post(`/finance/honor/payrolls/${selectedPayroll.value.id}/paid`, { payment_reference })
    toast.success('Periode honor ditandai telah dibayar.')
    await loadAll()
    await loadPayroll(selectedPayroll.value.id)
  } catch (error: any) {
    toast.error(error?.response?.data?.message ?? 'Periode honor gagal ditandai dibayar.')
  }
}

function formatCurrency(value: number | string) {
  return new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', maximumFractionDigits: 0 }).format(Number(value || 0))
}

function firstError(error: any): string | undefined {
  const errors = error?.response?.data?.errors
  return errors ? Object.values(errors).flat()[0] as string : error?.response?.data?.message
}
</script>

<template>
  <div class="space-y-5">
    <div>
      <h1 class="text-xl font-bold text-gray-900">Honor Dosen & Tendik</h1>
      <p class="mt-1 text-sm text-gray-500">Kelola tarif, verifikasi aktivitas, dan proses honor dengan snapshot yang terlindungi.</p>
    </div>

    <div class="flex gap-2 overflow-x-auto border-b border-gray-200">
      <button v-for="tab in [{ id: 'rates', label: 'Tarif' }, { id: 'activities', label: 'Aktivitas' }, { id: 'payrolls', label: 'Periode honor' }]" :key="tab.id" class="whitespace-nowrap border-b-2 px-3 py-2 text-sm font-medium" :class="activeTab === tab.id ? 'border-blue-600 text-blue-700' : 'border-transparent text-gray-500'" @click="activeTab = tab.id as Tab">
        {{ tab.label }}
      </button>
    </div>

    <div v-if="loading" class="py-12 text-center text-sm text-gray-400">Memuat data honor...</div>

    <template v-else-if="activeTab === 'rates'">
      <form class="grid gap-3 rounded-xl border border-gray-200 bg-white p-4 md:grid-cols-4" @submit.prevent="saveRate">
        <label class="text-xs font-medium text-gray-600">Nama tarif
          <input v-model="rateForm.name" required maxlength="255" class="mt-1 w-full rounded-lg border px-3 py-2 text-sm" placeholder="Honor mengajar luring" />
        </label>
        <label class="text-xs font-medium text-gray-600">Jenis honor
          <select v-model="rateForm.category" class="mt-1 w-full rounded-lg border px-3 py-2 text-sm">
            <option value="TEACHING">Mengajar per pertemuan</option>
            <option value="TEACHING_SKS">Mengajar per SKS</option>
            <option value="STRUCTURAL">Jabatan struktural per bulan</option>
            <option value="ADVISOR">Pembimbing akademik per mahasiswa/semester</option>
            <option value="INCIDENTAL">Insidental</option>
            <option value="STAFF_MONTHLY">Tenaga kependidikan per bulan</option>
          </select>
        </label>
        <label v-if="rateForm.category === 'TEACHING' || rateForm.category === 'TEACHING_SKS'" class="text-xs font-medium text-gray-600">Cara mengajar
          <select v-model="rateForm.delivery_mode" class="mt-1 w-full rounded-lg border px-3 py-2 text-sm">
            <option value="ONSITE">Luring</option><option value="ONLINE">Daring</option>
          </select>
        </label>
        <label v-if="rateForm.category === 'STRUCTURAL'" class="text-xs font-medium text-gray-600">Jabatan struktural
          <select v-model="rateForm.position_code" required class="mt-1 w-full rounded-lg border px-3 py-2 text-sm"><option value="">Pilih jabatan</option><option v-for="(label, code) in positionOptions" :key="code" :value="code">{{ label }} · {{ code }}</option></select>
        </label>
        <label v-if="rateForm.category === 'STAFF_MONTHLY'" class="text-xs font-medium text-gray-600">Jabatan tenaga kependidikan (opsional)
          <input v-model="rateForm.position_code" maxlength="100" class="mt-1 w-full rounded-lg border px-3 py-2 text-sm" placeholder="Kosongkan untuk tarif semua tendik" />
        </label>
        <label class="text-xs font-medium text-gray-600">Nominal (Rp, {{ unitLabels[unitByCategory[rateForm.category]] }})
          <input v-model="rateForm.amount" required type="number" min="1" step="1" class="mt-1 w-full rounded-lg border px-3 py-2 text-sm" />
        </label>
        <label class="text-xs font-medium text-gray-600">Berlaku mulai
          <input v-model="rateForm.effective_from" required type="date" class="mt-1 w-full rounded-lg border px-3 py-2 text-sm" />
        </label>
        <label class="text-xs font-medium text-gray-600">Berlaku sampai (opsional)
          <input v-model="rateForm.effective_until" type="date" class="mt-1 w-full rounded-lg border px-3 py-2 text-sm" />
        </label>
        <div class="flex items-end gap-2">
          <button :disabled="saving" class="flex items-center gap-2 rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium text-white disabled:opacity-50">
            <PlusIcon v-if="!editingRateId" class="h-4 w-4" /> {{ editingRateId ? 'Simpan perubahan' : 'Tambah tarif' }}
          </button>
          <button v-if="editingRateId" type="button" class="rounded-lg border px-4 py-2 text-sm text-gray-700" @click="cancelRateEdit">Batal edit</button>
        </div>
        <p v-if="rateForm.category === 'STAFF_MONTHLY'" class="md:col-span-4 text-xs text-gray-500">Tarif bulanan berlaku untuk tenaga kependidikan aktif; tarif khusus jabatan menggantikan tarif umum untuk jabatan tersebut.</p>
      </form>

      <div class="overflow-hidden rounded-xl border border-gray-200 bg-white">
        <div class="border-b px-4 py-3 text-sm font-semibold text-gray-800">Daftar tarif efektif</div>
        <div class="flex flex-wrap items-center gap-2 border-b bg-gray-50 px-4 py-3">
          <input v-model="rateFilter.search" type="search" placeholder="Cari nama tarif / jabatan" class="min-w-56 flex-1 rounded-lg border px-3 py-2 text-sm" />
          <select v-model="rateFilter.category" class="rounded-lg border px-3 py-2 text-sm"><option value="">Semua kategori</option><option v-for="(label, code) in categoryLabels" :key="code" :value="code">{{ label }}</option></select>
          <select v-model="rateFilter.status" class="rounded-lg border px-3 py-2 text-sm"><option value="">Semua status</option><option value="ACTIVE">Aktif</option><option value="INACTIVE">Nonaktif</option></select>
          <span class="text-xs text-gray-500">{{ filteredRates.length }} dari {{ rates.length }} tarif</span>
        </div>
        <div class="overflow-x-auto">
          <table class="min-w-full divide-y divide-gray-100 text-sm">
            <thead class="bg-gray-50 text-left text-xs uppercase text-gray-500"><tr><th class="px-4 py-3">Tarif</th><th class="px-4 py-3">Kategori / dasar</th><th class="px-4 py-3">Nominal</th><th class="px-4 py-3">Masa berlaku</th><th class="px-4 py-3">Status</th><th class="min-w-36 px-4 py-3 text-right">Aksi</th></tr></thead>
            <tbody class="divide-y divide-gray-100">
              <tr v-for="rate in filteredRates" :key="rate.id">
                <td class="px-4 py-3 font-medium">{{ rate.name }}</td>
                <td class="px-4 py-3 text-gray-600">{{ categoryLabels[rate.category] }}<span v-if="rate.delivery_mode"> · {{ rate.delivery_mode === 'ONSITE' ? 'Luring' : 'Daring' }}</span><span v-if="rate.position_code"> · {{ positionOptions[rate.position_code] ?? rate.position_code }}</span><span v-else-if="rate.category === 'STAFF_MONTHLY'"> · Semua jabatan</span></td>
                <td class="px-4 py-3">{{ formatCurrency(rate.amount) }} <span class="text-xs text-gray-400">{{ unitLabels[rate.unit] }}</span></td>
                <td class="px-4 py-3 text-xs text-gray-600">{{ rate.effective_from }} – {{ rate.effective_until ?? 'selanjutnya' }}</td>
                <td class="px-4 py-3"><span class="rounded-full px-2 py-1 text-xs" :class="rate.is_active ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-500'">{{ rate.is_active ? 'Aktif' : 'Nonaktif' }}</span></td>
                <td class="px-4 py-3 text-right">
                  <div class="flex items-center justify-end gap-1 whitespace-nowrap">
                    <button v-if="rate.is_active" class="rounded-lg px-2 py-1.5 text-xs font-medium text-blue-700 hover:bg-blue-50" @click="toggleRate(rate)">Akhiri</button>
                    <span v-else class="mr-1 text-xs text-gray-400">Diarsipkan</span>
                    <template v-if="!rate.activities_count && !rate.payroll_items_count">
                      <button class="rounded-lg p-1.5 text-blue-600 hover:bg-blue-50" :aria-label="`Edit tarif ${rate.name}`" :title="`Edit tarif ${rate.name}`" @click="editRate(rate)"><PencilIcon class="h-4 w-4" /></button>
                      <button class="rounded-lg p-1.5 text-red-500 hover:bg-red-50" :aria-label="`Hapus tarif ${rate.name}`" :title="`Hapus tarif ${rate.name}`" @click="deleteRate(rate)"><TrashIcon class="h-4 w-4" /></button>
                    </template>
                    <span v-else class="rounded-md bg-gray-100 px-2 py-1 text-xs text-gray-500" title="Tarif telah dipakai dan riwayatnya dikunci">Terkunci</span>
                  </div>
                </td>
              </tr>
              <tr v-if="!filteredRates.length"><td colspan="6" class="px-4 py-8 text-center text-gray-400">{{ rates.length ? 'Tidak ada tarif yang sesuai filter.' : 'Belum ada tarif honor.' }}</td></tr>
            </tbody>
          </table>
        </div>
      </div>
    </template>

    <template v-else-if="activeTab === 'activities'">
      <form class="grid gap-3 rounded-xl border border-gray-200 bg-white p-4 md:grid-cols-3" @submit.prevent="saveActivity">
        <label class="text-xs font-medium text-gray-600">Dosen
          <select v-model="activityForm.lecturer_id" required class="mt-1 w-full rounded-lg border px-3 py-2 text-sm"><option value="">Pilih dosen</option><option v-for="lecturer in lecturers" :key="lecturer.id" :value="String(lecturer.id)">{{ lecturer.name }}{{ lecturer.nidn ? ` · ${lecturer.nidn}` : '' }}</option></select>
        </label>
        <label class="text-xs font-medium text-gray-600">Tarif insidental
          <select v-model="activityForm.honor_rate_id" required class="mt-1 w-full rounded-lg border px-3 py-2 text-sm"><option value="">{{ incidentRates.length ? 'Pilih jenis aktivitas' : 'Belum ada tarif insidental yang berlaku pada tanggal ini' }}</option><option v-for="rate in incidentRates" :key="rate.id" :value="String(rate.id)">{{ rate.name }} · {{ formatCurrency(rate.amount) }}</option></select>
        </label>
        <label class="text-xs font-medium text-gray-600">Nomor referensi penugasan
          <input v-model="activityForm.reference_number" required maxlength="100" class="mt-1 w-full rounded-lg border px-3 py-2 text-sm" placeholder="Nomor SK / kode kegiatan" />
        </label>
        <label class="text-xs font-medium text-gray-600">Rincian kegiatan
          <input v-model="activityForm.description" required maxlength="255" class="mt-1 w-full rounded-lg border px-3 py-2 text-sm" placeholder="Ujian skripsi / pembimbing KKN" />
        </label>
        <label class="text-xs font-medium text-gray-600">Tanggal kegiatan
          <input v-model="activityForm.activity_date" required type="date" class="mt-1 w-full rounded-lg border px-3 py-2 text-sm" />
        </label>
        <label class="text-xs font-medium text-gray-600">Jumlah satuan
          <input v-model.number="activityForm.quantity" required type="number" min="0.01" step="0.01" class="mt-1 w-full rounded-lg border px-3 py-2 text-sm" />
        </label>
        <label class="text-xs font-medium text-gray-600">Semester (jika terkait)
          <select v-model="activityForm.semester_id" class="mt-1 w-full rounded-lg border px-3 py-2 text-sm"><option value="">Tidak terkait semester tertentu</option><option v-for="semester in semesters" :key="semester.id" :value="String(semester.id)">{{ semester.name }}</option></select>
        </label>
        <div class="flex items-end gap-2">
          <button :disabled="saving || !incidentRates.length" class="rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium text-white disabled:opacity-50">{{ editingActivityId ? 'Simpan perubahan' : 'Catat aktivitas' }}</button>
          <button v-if="editingActivityId" type="button" class="rounded-lg border px-4 py-2 text-sm text-gray-700" @click="cancelActivityEdit">Batal</button>
        </div>
      </form>
      <p v-if="editingVerifiedActivity" class="rounded-lg bg-amber-50 px-4 py-3 text-xs text-amber-800">Menyimpan perubahan akan membatalkan verifikasi dan mengembalikan aktivitas ke status menunggu verifikasi.</p>
      <p v-if="!incidentRates.length" class="rounded-lg bg-amber-50 p-3 text-sm text-amber-800">Buat tarif kategori Insidental terlebih dahulu pada tab Tarif.</p>

      <form class="flex flex-wrap items-end gap-2 rounded-xl border border-gray-200 bg-white p-4" @submit.prevent="applyActivityFilter">
        <input v-model="activityFilter.search" type="search" placeholder="Cari dosen, NIDN, rincian, referensi" class="min-w-56 flex-1 rounded-lg border px-3 py-2 text-sm" />
        <select v-model="activityFilter.status" class="rounded-lg border px-3 py-2 text-sm"><option value="">Semua status</option><option value="PENDING">PENDING</option><option value="VERIFIED">VERIFIED</option><option value="REJECTED">REJECTED</option></select>
        <select v-model="activityFilter.honor_rate_id" class="rounded-lg border px-3 py-2 text-sm"><option value="">Semua tarif</option><option v-for="rate in rates" :key="rate.id" :value="String(rate.id)">{{ rate.name }}</option></select>
        <label class="text-xs text-gray-500">Dari<input v-model="activityFilter.date_from" type="date" class="ml-1 rounded-lg border px-2 py-2 text-sm" /></label>
        <label class="text-xs text-gray-500">Sampai<input v-model="activityFilter.date_to" type="date" class="ml-1 rounded-lg border px-2 py-2 text-sm" /></label>
        <button class="rounded-lg bg-gray-800 px-4 py-2 text-sm text-white">Terapkan</button>
        <button type="button" class="rounded-lg border px-4 py-2 text-sm text-gray-700" @click="resetActivityFilter">Reset</button>
      </form>

      <div class="overflow-x-auto rounded-xl border border-gray-200 bg-white">
        <table class="min-w-full divide-y divide-gray-100 text-sm">
          <thead class="bg-gray-50 text-left text-xs uppercase text-gray-500"><tr><th class="px-4 py-3">Tanggal / Referensi</th><th class="px-4 py-3">Dosen / aktivitas</th><th class="px-4 py-3">Tarif × jumlah</th><th class="px-4 py-3">Nilai</th><th class="px-4 py-3">Status</th><th class="px-4 py-3"></th></tr></thead>
          <tbody class="divide-y divide-gray-100">
            <tr v-for="activity in activities" :key="activity.id">
              <td class="px-4 py-3 text-xs">{{ activity.activity_date }}<br><span class="text-gray-400">{{ activity.reference_number ?? activity.source_key }}</span></td>
              <td class="px-4 py-3"><span class="font-medium">{{ activity.lecturer?.name ?? activity.lecturer?.full_name }}</span><br><span class="text-xs text-gray-500">{{ activity.description }}</span></td>
              <td class="px-4 py-3 text-xs">{{ activity.rate?.name }}<br>{{ activity.quantity }} × {{ formatCurrency(activity.unit_rate_snapshot) }}</td>
              <td class="px-4 py-3 font-medium">{{ formatCurrency(activity.amount_snapshot) }}</td>
              <td class="px-4 py-3"><span class="rounded-full px-2 py-1 text-xs" :class="activity.status === 'VERIFIED' ? 'bg-green-100 text-green-700' : activity.status === 'PENDING' ? 'bg-amber-100 text-amber-800' : 'bg-red-100 text-red-700'">{{ activity.status }}</span></td>
              <td class="px-4 py-3 text-right">
                <div class="flex justify-end gap-2">
                  <template v-if="activity.source_key?.startsWith('manual:') && ['PENDING', 'VERIFIED', 'REJECTED'].includes(activity.status) && !activity.is_locked">
                    <button class="rounded-lg p-1.5 text-blue-600 hover:bg-blue-50" :aria-label="`Edit aktivitas ${activity.description}`" :title="`Edit aktivitas ${activity.description}`" @click="editActivity(activity)"><PencilIcon class="h-4 w-4" /></button>
                    <button class="rounded-lg p-1.5 text-red-500 hover:bg-red-50" :aria-label="`Hapus aktivitas ${activity.description}`" :title="`Hapus aktivitas ${activity.description}`" @click="deleteActivity(activity)"><TrashIcon class="h-4 w-4" /></button>
                  </template>
                  <template v-else-if="activity.source_key?.startsWith('manual:') && activity.is_locked">
                    <span class="rounded-md bg-gray-100 px-2 py-1 text-xs text-gray-500" title="Aktivitas sudah masuk periode yang disahkan atau dibayar">Terkunci</span>
                  </template>
                  <template v-if="activity.status === 'PENDING'">
                    <button class="text-xs font-medium text-green-700" @click="verifyActivity(activity, 'VERIFIED')">Verifikasi</button>
                    <button class="text-xs text-red-600" @click="verifyActivity(activity, 'REJECTED')">Tolak</button>
                  </template>
                </div>
              </td>
            </tr>
            <tr v-if="!activities.length"><td colspan="6" class="px-4 py-8 text-center text-gray-400">Belum ada aktivitas honor.</td></tr>
          </tbody>
        </table>
      </div>
      <div v-if="activityLastPage > 1" class="flex items-center justify-end gap-3 text-sm">
        <button :disabled="activityPage <= 1" class="rounded-lg border px-3 py-1.5 disabled:opacity-40" @click="changeActivityPage(activityPage - 1)">Sebelumnya</button>
        <span class="text-xs text-gray-500">Halaman {{ activityPage }} dari {{ activityLastPage }}</span>
        <button :disabled="activityPage >= activityLastPage" class="rounded-lg border px-3 py-1.5 disabled:opacity-40" @click="changeActivityPage(activityPage + 1)">Berikutnya</button>
      </div>
    </template>

    <template v-else>
      <form class="flex flex-wrap items-end gap-3 rounded-xl border border-gray-200 bg-white p-4" @submit.prevent="createPayroll">
        <label class="text-xs font-medium text-gray-600">Jenis periode
          <select v-model="periodForm.period_type" class="mt-1 block rounded-lg border px-3 py-2 text-sm"><option value="MONTHLY">Bulanan</option><option value="SEMESTER">Semester</option><option value="INCIDENTAL">Insidental / harian</option></select>
        </label>
        <template v-if="periodForm.period_type === 'MONTHLY'">
          <label class="text-xs font-medium text-gray-600">Tahun<input v-model.number="periodForm.year" required type="number" min="2000" max="2200" class="mt-1 block w-28 rounded-lg border px-3 py-2 text-sm" /></label>
          <label class="text-xs font-medium text-gray-600">Bulan<input v-model.number="periodForm.month" required type="number" min="1" max="12" class="mt-1 block w-24 rounded-lg border px-3 py-2 text-sm" /></label>
        </template>
        <label v-else-if="periodForm.period_type === 'SEMESTER'" class="text-xs font-medium text-gray-600">Semester
          <select v-model="periodForm.semester_id" required class="mt-1 block min-w-48 rounded-lg border px-3 py-2 text-sm"><option value="">Pilih semester</option><option v-for="semester in semesters" :key="semester.id" :value="String(semester.id)">{{ semester.name }}</option></select>
        </label>
        <label v-else class="text-xs font-medium text-gray-600">Tanggal kegiatan
          <input v-model="periodForm.activity_date" required type="date" class="mt-1 block rounded-lg border px-3 py-2 text-sm" />
        </label>
        <div class="flex gap-2">
          <button :disabled="saving" class="rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium text-white disabled:opacity-50">{{ editingPayrollId ? 'Simpan perubahan' : 'Buka draft periode' }}</button>
          <button v-if="editingPayrollId" type="button" class="rounded-lg border px-4 py-2 text-sm text-gray-700" @click="cancelPayrollEdit">Batal</button>
        </div>
      </form>
      <p v-if="periodForm.period_type === 'INCIDENTAL'" class="rounded-lg bg-blue-50 px-4 py-3 text-xs text-blue-800">Periode harian hanya menghitung aktivitas kategori Insidental yang sudah diverifikasi pada tanggal tersebut. Setelah dihitung dan diperiksa, periode dapat langsung disahkan dan ditandai dibayar.</p>
      <p v-if="editingPayrollId" class="rounded-lg bg-amber-50 px-4 py-3 text-xs text-amber-800">Jika rentang periode berubah, snapshot draft yang sudah dihitung akan dihapus dan harus dihitung ulang sebelum pengesahan.</p>

      <div class="grid gap-4 lg:grid-cols-[minmax(260px,0.8fr)_minmax(0,2fr)]">
        <div class="overflow-hidden rounded-xl border border-gray-200 bg-white">
          <div class="border-b px-4 py-3 text-sm font-semibold">Periode honor</div>
          <div v-for="payroll in payrolls" :key="payroll.id" class="border-b px-4 py-3 hover:bg-blue-50" :class="selectedPayroll?.id === payroll.id ? 'bg-blue-50' : ''">
            <button class="block w-full text-left" @click="loadPayroll(payroll.id)">
              <span class="block text-sm font-medium">{{ payroll.period_type === 'MONTHLY' ? payroll.period_key.replace('MONTHLY-', '') : payroll.period_type === 'INCIDENTAL' ? `Insidental · ${payroll.period_start}` : payroll.semester?.name }}</span>
              <span class="mt-1 block text-xs text-gray-500">{{ payroll.status }} · {{ payroll.items_count }} rincian</span>
            </button>
            <div v-if="canDeletePayroll(payroll)" class="mt-2 flex gap-3">
              <button v-if="payroll.status === 'DRAFT'" class="text-xs text-gray-700 hover:underline" @click="editPayroll(payroll)">Edit periode</button>
              <button class="text-xs text-red-600 hover:underline" @click="deletePayroll(payroll)">Hapus periode</button>
            </div>
          </div>
          <p v-if="!payrolls.length" class="p-5 text-center text-sm text-gray-400">Belum ada periode.</p>
        </div>

        <div v-if="selectedPayroll" class="space-y-3">
          <div class="rounded-xl border border-gray-200 bg-white p-4">
            <div class="flex flex-wrap items-start justify-between gap-3">
              <div><h2 class="font-semibold text-gray-900">{{ selectedPayroll.period_type === 'MONTHLY' ? selectedPayroll.period_key : selectedPayroll.period_type === 'INCIDENTAL' ? `Insidental · ${selectedPayroll.period_start}` : selectedPayroll.semester?.name }}</h2><p class="mt-1 text-xs text-gray-500">{{ selectedPayroll.period_start }} – {{ selectedPayroll.period_end }} · {{ selectedPayroll.status }}</p></div>
              <div class="flex flex-wrap gap-2">
                <button v-if="selectedPayroll.status === 'DRAFT'" :disabled="saving" class="flex items-center gap-1 rounded-lg border px-3 py-2 text-xs font-medium text-gray-700 disabled:opacity-50" @click="calculatePayroll"><ArrowPathIcon class="h-4 w-4" /> Hitung draft</button>
                <button v-if="selectedPayroll.status === 'DRAFT' && selectedPeriodEnded" class="rounded-lg bg-blue-600 px-3 py-2 text-xs font-medium text-white" @click="approvePayroll">Sahkan</button>
                <button v-if="selectedPayroll.status === 'APPROVED'" class="rounded-lg bg-green-600 px-3 py-2 text-xs font-medium text-white" @click="markPaid">Tandai dibayar</button>
              </div>
              <p v-if="selectedPayroll.status === 'DRAFT' && !selectedPeriodEnded" class="mt-3 text-xs text-amber-800">Periode belum berakhir. Pengesahan baru tersedia setelah akhir periode.</p>
            </div>
            <div class="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
              <div class="rounded-lg bg-gray-50 p-3"><span class="text-xs text-gray-500">Honor bruto</span><strong class="mt-1 block text-sm">{{ formatCurrency(selectedPayroll.gross_amount ?? selectedPayroll.total_amount) }}</strong></div>
              <div class="rounded-lg bg-red-50 p-3"><span class="text-xs text-red-700">Total potongan</span><strong class="mt-1 block text-sm text-red-800">{{ formatCurrency(selectedPayroll.deduction_amount) }}</strong></div>
              <div class="rounded-lg bg-green-50 p-3"><span class="text-xs text-green-700">Bersih dibayarkan</span><strong class="mt-1 block text-sm text-green-800">{{ formatCurrency(selectedPayroll.total_amount) }}</strong></div>
              <div class="rounded-lg bg-amber-50 p-3"><span class="text-xs text-amber-800">Menunggu verifikasi</span><strong class="mt-1 block text-sm text-amber-900">{{ selectedPayroll.pending_activity_count ?? '—' }}</strong></div>
            </div>
            <p class="mt-3 text-xs text-gray-500">{{ selectedPayroll.period_type === 'INCIDENTAL' ? 'Periode ini khusus aktivitas insidental terverifikasi pada tanggal kegiatan.' : 'Honor mengajar dihitung dari jurnal COMPLETED; jurnal tanpa cara mengajar dihitung luring.' }} Tarif dan nilai setiap aktivitas dikunci pada saat periode disahkan.</p>
          </div>
          <div class="flex flex-wrap items-center gap-2 rounded-xl border border-gray-200 bg-white p-3">
            <input v-model="payrollSearch" type="search" placeholder="Cari penerima atau aktivitas" class="min-w-56 flex-1 rounded-lg border px-3 py-2 text-sm" />
            <span class="text-xs text-gray-500">{{ payrollSummary.list.length }} penerima</span>
            <button :disabled="downloadingPdf || !selectedPayroll.items?.length" class="rounded-lg border px-3 py-2 text-xs font-medium text-gray-700 disabled:opacity-50" @click="downloadPayrollPdf">{{ downloadingPdf ? 'Menyiapkan…' : 'Export PDF' }}</button>
          </div>
          <div class="overflow-x-auto rounded-xl border border-gray-200 bg-white">
            <table class="min-w-full divide-y divide-gray-100 text-sm">
              <thead class="bg-gray-50 text-left text-xs uppercase text-gray-500"><tr><th class="px-4 py-3">Penerima</th><th class="px-4 py-3">Aktivitas</th><th class="px-4 py-3">Jumlah × Tarif</th><th class="px-4 py-3 text-right">Honor</th></tr></thead>
              <tbody class="divide-y divide-gray-100">
                <tr v-for="group in payrollSummary.list" :key="group.key" class="align-top">
                  <td class="px-4 py-3 font-medium">{{ group.name }}<span v-if="group.nip" class="block text-xs font-normal text-gray-500">{{ group.nip }}</span></td>
                  <td class="px-4 py-3"><div v-for="(line, i) in group.lines" :key="i" class="py-0.5">{{ line.label }}</div></td>
                  <td class="px-4 py-3 text-xs text-gray-600"><div v-for="(line, i) in group.lines" :key="i" class="py-0.5">{{ line.quantity }} × {{ formatCurrency(line.rate) }} = {{ formatCurrency(line.amount) }}</div></td>
                  <td class="px-4 py-3 text-right font-semibold">{{ formatCurrency(group.total) }}</td>
                </tr>
                <tr v-if="!payrollSummary.list.length"><td colspan="4" class="px-4 py-8 text-center text-gray-400">Belum ada snapshot honor. Verifikasi aktivitas, lalu hitung draft.</td></tr>
              </tbody>
              <tfoot v-if="payrollSummary.list.length" class="bg-gray-50 font-semibold"><tr><td class="px-4 py-3" colspan="3">Jumlah honor keseluruhan</td><td class="px-4 py-3 text-right">{{ formatCurrency(payrollSummary.grandTotal) }}</td></tr></tfoot>
            </table>
          </div>
          <div class="space-y-3 rounded-xl border border-gray-200 bg-white p-4">
            <div>
              <h3 class="text-sm font-semibold text-gray-900">Potongan per penerima</h3>
              <p class="mt-1 text-xs text-gray-500">Masukkan nominal tetap sebelum periode disahkan. Potongan dikunci setelah periode disahkan dan dicantumkan pada slip honor.</p>
            </div>
            <form v-if="selectedPayroll.status === 'DRAFT' && payrollRecipients.length" class="grid gap-3 sm:grid-cols-2 lg:grid-cols-5" @submit.prevent="addPayrollDeduction">
              <label class="text-xs font-medium text-gray-600">Penerima
                <select v-model="deductionForm.recipient" required class="mt-1 w-full rounded-lg border px-3 py-2 text-sm">
                  <option value="">Pilih penerima</option>
                  <option v-for="recipient in payrollRecipients" :key="recipient.key" :value="recipient.key">{{ recipient.name }}</option>
                </select>
              </label>
              <label class="text-xs font-medium text-gray-600">Jenis potongan
                <select v-model="deductionForm.deduction_type" class="mt-1 w-full rounded-lg border px-3 py-2 text-sm"><option value="TAX">Pajak</option><option value="OTHER">Potongan lainnya</option></select>
              </label>
              <label class="text-xs font-medium text-gray-600">Keterangan
                <input v-model="deductionForm.description" required maxlength="255" class="mt-1 w-full rounded-lg border px-3 py-2 text-sm" placeholder="PPh 21 / koperasi" />
              </label>
              <label class="text-xs font-medium text-gray-600">Nominal (Rp)
                <input v-model="deductionForm.amount" required type="number" min="1" step="1" class="mt-1 w-full rounded-lg border px-3 py-2 text-sm" />
              </label>
              <button :disabled="saving" class="self-end rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium text-white disabled:opacity-50">Tambah potongan</button>
            </form>
            <div class="overflow-x-auto">
              <table class="min-w-full divide-y divide-gray-100 text-sm">
                <thead class="bg-gray-50 text-left text-xs uppercase text-gray-500"><tr><th class="px-3 py-2">Penerima</th><th class="px-3 py-2">Jenis</th><th class="px-3 py-2">Keterangan</th><th class="px-3 py-2 text-right">Nominal</th><th class="px-3 py-2"></th></tr></thead>
                <tbody class="divide-y divide-gray-100">
                  <tr v-for="deduction in selectedPayroll.deductions ?? []" :key="deduction.id">
                    <td class="px-3 py-2">{{ deduction.lecturer?.name ?? deduction.lecturer?.full_name ?? deduction.staff?.name }}</td>
                    <td class="px-3 py-2">{{ deduction.deduction_type === 'TAX' ? 'Pajak' : 'Lainnya' }}</td>
                    <td class="px-3 py-2">{{ deduction.description }}</td>
                    <td class="px-3 py-2 text-right">{{ formatCurrency(deduction.amount) }}</td>
                    <td class="px-3 py-2 text-right"><button v-if="selectedPayroll.status === 'DRAFT'" class="text-xs text-red-600 hover:underline" @click="deletePayrollDeduction(deduction)">Hapus</button></td>
                  </tr>
                  <tr v-if="!selectedPayroll.deductions?.length"><td colspan="5" class="px-3 py-5 text-center text-xs text-gray-400">Belum ada potongan pada periode ini.</td></tr>
                </tbody>
              </table>
            </div>
          </div>
          <div v-if="selectedPayroll.status === 'DRAFT' && activities.some(activity => activity.status === 'PENDING' && activity.activity_date >= selectedPayroll.period_start && activity.activity_date <= selectedPayroll.period_end)" class="rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">
            Ada aktivitas menunggu verifikasi. Tinjau pada tab Aktivitas sebelum mengesahkan periode.
            <button class="ml-2 font-semibold underline" @click="activeTab = 'activities'">Buka aktivitas</button>
          </div>
        </div>
        <div v-else class="rounded-xl border border-dashed border-gray-300 bg-white p-8 text-center text-sm text-gray-400">Pilih periode untuk meninjau rincian honor.</div>
      </div>
    </template>
  </div>
</template>
