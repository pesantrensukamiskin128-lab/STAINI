<script setup lang="ts">
import { ref, computed, watch, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { useToast } from 'vue-toastification'
import {
  MagnifyingGlassIcon,
  ArrowPathIcon,
  DocumentArrowDownIcon,
  ArrowDownTrayIcon,
  UsersIcon,
  DocumentTextIcon,
  CheckCircleIcon,
  ExclamationTriangleIcon,
  EyeIcon,
  XMarkIcon,
  FunnelIcon,
  AcademicCapIcon,
  CalendarDaysIcon,
} from '@heroicons/vue/24/outline'
import api from '@/services/api'

const router = useRouter()
const toast = useToast()

const groups = [
  { value: 'student', label: 'Per Mahasiswa', icon: UsersIcon },
  { value: 'prodi', label: 'Berbasis Prodi', icon: AcademicCapIcon },
  { value: 'semester', label: 'Per Semester', icon: CalendarDaysIcon },
  { value: 'month', label: 'Per Bulan', icon: CalendarDaysIcon },
  { value: 'kelas', label: 'Berbasis Kelas', icon: UsersIcon },
]

const filters = ref({
  group_by: 'student',
  search: '',
  payment_status: 'all',
  month: '',
  semester_id: '',
  study_program_id: '',
  class_type: '',
})

const semesters = ref<any[]>([])
const prodis = ref<any[]>([])
const report = ref<any>(null)
const loading = ref(false)
const downloading = ref('')

// Pagination
const page = ref(1)
const perPage = ref(25)

const isStudentMode = computed(() => filters.value.group_by === 'student')

const list = (r: any) => (Array.isArray(r.data) ? r.data : r.data?.data ?? [])

function params() {
  const p: Record<string, string> = {}
  Object.entries(filters.value).forEach(([k, v]) => {
    if (v !== '' && v !== null && v !== undefined) p[k] = String(v)
  })
  return p
}

let searchTimer: any = null
function onSearchInput() {
  clearTimeout(searchTimer)
  searchTimer = setTimeout(() => {
    page.value = 1
    load()
  }, 400)
}

async function load() {
  loading.value = true
  try {
    const { data } = await api.get('/finance/student-report', { params: params() })
    report.value = data
  } catch {
    toast.error('Gagal memuat laporan keuangan mahasiswa.')
  } finally {
    loading.value = false
  }
}

function resetFilters() {
  filters.value = {
    group_by: 'student',
    search: '',
    payment_status: 'all',
    month: '',
    semester_id: '',
    study_program_id: '',
    class_type: '',
  }
  page.value = 1
  load()
}

function setGroupBy(groupValue: string) {
  filters.value.group_by = groupValue
  page.value = 1
  load()
}

function drillDownToStudent(groupType: string, label: string) {
  filters.value.group_by = 'student'
  if (groupType === 'prodi') {
    const found = prodis.value.find(p => p.name === label)
    if (found) filters.value.study_program_id = String(found.id)
  } else if (groupType === 'kelas') {
    filters.value.class_type = label
  } else if (groupType === 'semester') {
    const found = semesters.value.find(s => s.name === label)
    if (found) filters.value.semester_id = String(found.id)
  }
  page.value = 1
  load()
}

const totalRowsCount = computed(() => report.value?.rows?.length ?? 0)

const totalPages = computed(() => Math.ceil(totalRowsCount.value / perPage.value) || 1)

const paginatedRows = computed(() => {
  const rows = report.value?.rows ?? []
  if (!isStudentMode.value) return rows
  const start = (page.value - 1) * perPage.value
  return rows.slice(start, start + perPage.value)
})

watch(() => filters.value.group_by, () => {
  page.value = 1
})

async function download(type: 'pdf' | 'excel') {
  downloading.value = type
  try {
    const res = await api.get(`/finance/student-report/${type}`, {
      params: params(),
      responseType: 'blob',
    })
    const url = URL.createObjectURL(new Blob([res.data]))
    const a = document.createElement('a')
    a.href = url
    a.download = `laporan-keuangan-mahasiswa-${Date.now()}.${type === 'pdf' ? 'pdf' : 'xlsx'}`
    document.body.appendChild(a)
    a.click()
    a.remove()
    URL.revokeObjectURL(url)
    toast.success(`Laporan ${type.toUpperCase()} berhasil diunduh.`)
  } catch {
    toast.error(`Gagal mengunduh file ${type.toUpperCase()}.`)
  } finally {
    downloading.value = ''
  }
}

const rp = (n: number) =>
  new Intl.NumberFormat('id-ID', {
    style: 'currency',
    currency: 'IDR',
    minimumFractionDigits: 0,
  }).format(n ?? 0)

const percent = (part: number, total: number) => {
  if (!total || total <= 0) return 0
  return Math.min(100, Math.round((part / total) * 100))
}

const initials = (name: string) => {
  if (!name) return '?'
  const parts = name.trim().split(/\s+/)
  if (parts.length === 1) return parts[0].slice(0, 2).toUpperCase()
  return (parts[0][0] + parts[1][0]).toUpperCase()
}

onMounted(async () => {
  const [s, p] = await Promise.allSettled([
    api.get('/semesters', { params: { per_page: 100 } }),
    api.get('/study-programs/all'),
  ])
  if (s.status === 'fulfilled') semesters.value = list(s.value)
  if (p.status === 'fulfilled') prodis.value = list(p.value)
  load()
})
</script>

<template>
  <div class="space-y-6 pb-12">
    <!-- Top Header -->
    <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
      <div>
        <div class="flex items-center gap-2">
          <span class="inline-flex items-center rounded-full bg-blue-50 px-2.5 py-0.5 text-xs font-semibold text-blue-700 ring-1 ring-inset ring-blue-700/10">
            Laporan Keuangan
          </span>
          <span class="text-xs text-gray-500">Nurul Iman Academic Integrated System</span>
        </div>
        <h1 class="mt-1 text-2xl font-bold tracking-tight text-gray-900 sm:text-3xl">
          Laporan Keuangan Mahasiswa
        </h1>
        <p class="mt-1 text-sm text-gray-500">
          Rekapitulasi tagihan, pemantauan pembayaran, dan rincian tunggakan mahasiswa terpadu.
        </p>
      </div>

      <!-- Action Buttons -->
      <div class="flex flex-wrap items-center gap-2.5">
        <button
          class="inline-flex items-center gap-1.5 rounded-xl border border-gray-300 bg-white px-3 py-2 text-xs font-medium text-gray-700 shadow-sm transition hover:bg-gray-50 disabled:opacity-50"
          :disabled="loading"
          title="Muat ulang data"
          @click="load"
        >
          <ArrowPathIcon class="h-4 w-4" :class="{ 'animate-spin': loading }" />
          <span class="hidden sm:inline">Segarkan</span>
        </button>

        <button
          class="inline-flex items-center gap-2 rounded-xl bg-emerald-600 px-4 py-2 text-xs font-semibold text-white shadow-sm transition hover:bg-emerald-700 focus:outline-none focus:ring-2 focus:ring-emerald-500/20 disabled:cursor-not-allowed disabled:opacity-60"
          :disabled="!!downloading || !report?.rows?.length"
          @click="download('excel')"
        >
          <ArrowDownTrayIcon class="h-4 w-4" />
          <span>{{ downloading === 'excel' ? 'Mengunduh...' : 'Unduh Excel' }}</span>
        </button>

        <button
          class="inline-flex items-center gap-2 rounded-xl bg-blue-600 px-4 py-2 text-xs font-semibold text-white shadow-sm transition hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500/20 disabled:cursor-not-allowed disabled:opacity-60"
          :disabled="!!downloading || !report?.rows?.length"
          @click="download('pdf')"
        >
          <DocumentArrowDownIcon class="h-4 w-4" />
          <span>{{ downloading === 'pdf' ? 'Mengunduh...' : 'Unduh PDF' }}</span>
        </button>
      </div>
    </div>

    <!-- Executive KPI Overview Cards -->
    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
      <!-- Card 1: Mahasiswa & Tagihan -->
      <div class="relative overflow-hidden rounded-2xl border border-gray-200/90 bg-white p-5 shadow-sm transition hover:shadow-md">
        <div class="flex items-center justify-between">
          <p class="text-xs font-medium uppercase tracking-wider text-gray-500">Total Mahasiswa</p>
          <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-blue-50 text-blue-600 ring-1 ring-blue-600/10">
            <UsersIcon class="h-5 w-5" />
          </div>
        </div>
        <p class="mt-3 text-2xl font-extrabold tracking-tight text-gray-900">
          {{ (report?.totals?.students ?? 0).toLocaleString('id-ID') }}
          <span class="text-sm font-normal text-gray-500">Mhs</span>
        </p>
        <p class="mt-1 text-xs text-gray-500">
          Dari total <strong class="text-gray-700">{{ (report?.totals?.invoices ?? 0).toLocaleString('id-ID') }}</strong> tagihan aktif
        </p>
      </div>

      <!-- Card 2: Total Tagihan Bruto -->
      <div class="relative overflow-hidden rounded-2xl border border-gray-200/90 bg-white p-5 shadow-sm transition hover:shadow-md">
        <div class="flex items-center justify-between">
          <p class="text-xs font-medium uppercase tracking-wider text-gray-500">Total Tagihan</p>
          <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-indigo-50 text-indigo-600 ring-1 ring-indigo-600/10">
            <DocumentTextIcon class="h-5 w-5" />
          </div>
        </div>
        <p class="mt-3 text-2xl font-extrabold tracking-tight text-gray-900">
          {{ rp(report?.totals?.invoiced ?? 0) }}
        </p>
        <p class="mt-1 text-xs text-gray-500">
          Diskon & Beasiswa: <span class="font-medium text-gray-700">{{ rp((report?.totals?.discount ?? 0) + (report?.totals?.scholarship ?? 0)) }}</span>
        </p>
      </div>

      <!-- Card 3: Realisasi Terbayar -->
      <div class="relative overflow-hidden rounded-2xl border border-gray-200/90 bg-white p-5 shadow-sm transition hover:shadow-md">
        <div class="flex items-center justify-between">
          <p class="text-xs font-medium uppercase tracking-wider text-gray-500">Realisasi Pembayaran</p>
          <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-emerald-50 text-emerald-600 ring-1 ring-emerald-600/10">
            <CheckCircleIcon class="h-5 w-5" />
          </div>
        </div>
        <div class="mt-3 flex items-baseline gap-2">
          <p class="text-2xl font-extrabold tracking-tight text-emerald-600">
            {{ rp(report?.totals?.paid ?? 0) }}
          </p>
        </div>
        <div class="mt-1 flex items-center gap-1.5 text-xs text-gray-500">
          <span class="inline-flex items-center rounded-full bg-emerald-50 px-1.5 py-0.5 text-[10px] font-bold text-emerald-700">
            {{ percent(report?.totals?.paid ?? 0, (report?.totals?.invoiced ?? 1) - (report?.totals?.discount ?? 0) - (report?.totals?.scholarship ?? 0)) }}%
          </span>
          <span>dari kewajiban bersih</span>
        </div>
      </div>

      <!-- Card 4: Sisa Tunggakan -->
      <div class="relative overflow-hidden rounded-2xl border border-gray-200/90 bg-white p-5 shadow-sm transition hover:shadow-md">
        <div class="flex items-center justify-between">
          <p class="text-xs font-medium uppercase tracking-wider text-gray-500">Total Tunggakan</p>
          <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-rose-50 text-rose-600 ring-1 ring-rose-600/10">
            <ExclamationTriangleIcon class="h-5 w-5" />
          </div>
        </div>
        <div class="mt-3 flex items-baseline gap-2">
          <p class="text-2xl font-extrabold tracking-tight text-rose-600">
            {{ rp(report?.totals?.outstanding ?? 0) }}
          </p>
        </div>
        <div class="mt-1 flex items-center gap-1.5 text-xs text-gray-500">
          <span class="inline-flex items-center rounded-full bg-rose-50 px-1.5 py-0.5 text-[10px] font-bold text-rose-700">
            {{ percent(report?.totals?.outstanding ?? 0, (report?.totals?.invoiced ?? 1) - (report?.totals?.discount ?? 0) - (report?.totals?.scholarship ?? 0)) }}%
          </span>
          <span>belum terselesaikan</span>
        </div>
      </div>
    </div>

    <!-- Filter Control Card -->
    <div class="rounded-2xl border border-gray-200/90 bg-white p-5 shadow-sm space-y-4">
      <!-- Row 1: Mode Tampilan / Grouping Pill Tabs -->
      <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between border-b border-gray-100 pb-4">
        <div>
          <span class="text-xs font-semibold uppercase tracking-wider text-gray-400">Mode Tampilan</span>
          <div class="mt-1.5 flex flex-wrap gap-1.5">
            <button
              v-for="g in groups"
              :key="g.value"
              class="inline-flex items-center gap-1.5 rounded-xl px-3 py-1.5 text-xs font-semibold transition"
              :class="filters.group_by === g.value
                ? 'bg-blue-600 text-white shadow-sm ring-1 ring-blue-600'
                : 'bg-gray-100 text-gray-600 hover:bg-gray-200/80 hover:text-gray-900'"
              @click="setGroupBy(g.value)"
            >
              <component :is="g.icon" class="h-3.5 w-3.5" />
              <span>{{ g.label }}</span>
            </button>
          </div>
        </div>

        <!-- Quick Status Filter -->
        <div class="flex items-center gap-2">
          <span class="text-xs font-medium text-gray-500">Status:</span>
          <select
            v-model="filters.payment_status"
            class="rounded-xl border border-gray-300 bg-white px-3 py-1.5 text-xs font-medium text-gray-700 shadow-sm focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-500/20"
            @change="page = 1; load()"
          >
            <option value="all">Semua Status</option>
            <option value="lunas">Hanya Lunas</option>
            <option value="menunggak">Ada Tunggakan</option>
          </select>
        </div>
      </div>

      <!-- Row 2: Detailed Filters Grid -->
      <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-6">
        <!-- Search Mahasiswa -->
        <div class="lg:col-span-2">
          <label class="block text-xs font-semibold text-gray-600 mb-1">
            Cari Mahasiswa (Nama / NIM)
          </label>
          <div class="relative">
            <div class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3">
              <MagnifyingGlassIcon class="h-4 w-4 text-gray-400" />
            </div>
            <input
              v-model="filters.search"
              type="text"
              placeholder="Ketik nama atau NIM..."
              class="block w-full rounded-xl border border-gray-300 pl-9 pr-8 py-2 text-xs placeholder:text-gray-400 focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-500/20"
              @input="onSearchInput"
            />
            <button
              v-if="filters.search"
              class="absolute inset-y-0 right-0 flex items-center pr-2.5 text-gray-400 hover:text-gray-600"
              @click="filters.search = ''; page = 1; load()"
            >
              <XMarkIcon class="h-4 w-4" />
            </button>
          </div>
        </div>

        <!-- Filter Semester -->
        <div>
          <label class="block text-xs font-semibold text-gray-600 mb-1">Semester</label>
          <select
            v-model="filters.semester_id"
            class="block w-full rounded-xl border border-gray-300 bg-white px-3 py-2 text-xs text-gray-700 focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-500/20"
            @change="page = 1; load()"
          >
            <option value="">Semua Semester</option>
            <option v-for="s in semesters" :key="s.id" :value="s.id">{{ s.name }}</option>
          </select>
        </div>

        <!-- Filter Prodi -->
        <div>
          <label class="block text-xs font-semibold text-gray-600 mb-1">Program Studi</label>
          <select
            v-model="filters.study_program_id"
            class="block w-full rounded-xl border border-gray-300 bg-white px-3 py-2 text-xs text-gray-700 focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-500/20"
            @change="page = 1; load()"
          >
            <option value="">Semua Prodi</option>
            <option v-for="p in prodis" :key="p.id" :value="p.id">{{ p.name }}</option>
          </select>
        </div>

        <!-- Filter Kelas -->
        <div>
          <label class="block text-xs font-semibold text-gray-600 mb-1">Kelas</label>
          <select
            v-model="filters.class_type"
            class="block w-full rounded-xl border border-gray-300 bg-white px-3 py-2 text-xs text-gray-700 focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-500/20"
            @change="page = 1; load()"
          >
            <option value="">Semua Kelas</option>
            <option value="REGULER">Reguler</option>
            <option value="HYBRID">Hybrid</option>
          </select>
        </div>

        <!-- Filter Bulan / Reset Button -->
        <div>
          <div class="flex items-center justify-between mb-1">
            <label class="block text-xs font-semibold text-gray-600">Bulan Tagihan</label>
            <button
              class="text-[11px] font-medium text-blue-600 hover:text-blue-800 transition"
              title="Reset semua filter"
              @click="resetFilters"
            >
              Reset
            </button>
          </div>
          <input
            v-model="filters.month"
            type="month"
            class="block w-full rounded-xl border border-gray-300 bg-white px-3 py-1.5 text-xs text-gray-700 focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-500/20"
            @change="page = 1; load()"
          />
        </div>
      </div>

      <!-- Active Filter Tags -->
      <div v-if="report?.filters?.length" class="flex flex-wrap items-center gap-1.5 pt-2 border-t border-gray-100">
        <span class="text-[11px] font-medium text-gray-400">Filter aktif:</span>
        <span
          v-for="(fTag, idx) in report.filters"
          :key="idx"
          class="inline-flex items-center gap-1 rounded-lg bg-gray-100 px-2.5 py-0.5 text-xs text-gray-700"
        >
          <span>{{ fTag }}</span>
        </span>
        <button
          class="text-xs font-semibold text-red-600 hover:text-red-700 hover:underline ml-1"
          @click="resetFilters"
        >
          Bersihkan semua
        </button>
      </div>
    </div>

    <!-- Main Data Table Card -->
    <div class="overflow-hidden rounded-2xl border border-gray-200/90 bg-white shadow-sm">
      <!-- Table Header Bar -->
      <div class="flex flex-col gap-3 border-b border-gray-100 bg-gray-50/70 px-5 py-3.5 sm:flex-row sm:items-center sm:justify-between">
        <div class="flex items-center gap-2">
          <h2 class="text-sm font-bold text-gray-900">
            {{ report?.group_label ?? 'Daftar Laporan' }}
          </h2>
          <span class="inline-flex items-center rounded-full bg-blue-100 px-2 py-0.5 text-xs font-semibold text-blue-800">
            {{ totalRowsCount }} data
          </span>
        </div>

        <!-- Pagination size selector when in student mode -->
        <div v-if="isStudentMode && totalRowsCount > 0" class="flex items-center gap-2 text-xs text-gray-500">
          <span>Baris per halaman:</span>
          <select
            v-model="perPage"
            class="rounded-lg border border-gray-300 bg-white px-2 py-1 text-xs text-gray-700 focus:outline-none focus:ring-1 focus:ring-blue-500"
            @change="page = 1"
          >
            <option :value="15">15</option>
            <option :value="25">25</option>
            <option :value="50">50</option>
            <option :value="100">100</option>
          </select>
        </div>
      </div>

      <!-- Table Content -->
      <div class="overflow-x-auto">
        <table class="min-w-full divide-y divide-gray-200 text-left text-xs">
          <!-- THEAD FOR STUDENT MODE -->
          <thead v-if="isStudentMode" class="bg-gray-50/90 font-semibold uppercase tracking-wider text-gray-600">
            <tr>
              <th scope="col" class="py-3 pl-5 pr-2 text-center w-12">No</th>
              <th scope="col" class="px-3 py-3 min-w-[200px]">Mahasiswa</th>
              <th scope="col" class="px-3 py-3 min-w-[150px]">Program Studi</th>
              <th scope="col" class="px-3 py-3 text-center">Kelas</th>
              <th scope="col" class="px-3 py-3 text-right">Tagihan</th>
              <th scope="col" class="px-3 py-3 text-right">Total Tagihan</th>
              <th scope="col" class="px-3 py-3 text-right">Potongan</th>
              <th scope="col" class="px-3 py-3 text-right">Terbayar</th>
              <th scope="col" class="px-3 py-3 text-right">Sisa Tunggakan</th>
              <th scope="col" class="px-3 py-3 text-center">Status</th>
              <th scope="col" class="py-3 pl-2 pr-5 text-right w-16">Aksi</th>
            </tr>
          </thead>

          <!-- THEAD FOR AGGREGATE MODE -->
          <thead v-else class="bg-gray-50/90 font-semibold uppercase tracking-wider text-gray-600">
            <tr>
              <th scope="col" class="py-3 pl-5 pr-2 text-center w-12">No</th>
              <th scope="col" class="px-3 py-3 min-w-[200px]">{{ report?.group_label ?? 'Kategori' }}</th>
              <th scope="col" class="px-3 py-3 text-right">Mhs</th>
              <th scope="col" class="px-3 py-3 text-right">Jml Tagihan</th>
              <th scope="col" class="px-3 py-3 text-right">Total Tagihan</th>
              <th scope="col" class="px-3 py-3 text-right">Potongan</th>
              <th scope="col" class="px-3 py-3 text-right">Beasiswa</th>
              <th scope="col" class="px-3 py-3 text-right">Terbayar</th>
              <th scope="col" class="px-3 py-3 text-right">Sisa Tunggakan</th>
              <th scope="col" class="px-3 py-3 text-center">Realisasi</th>
              <th scope="col" class="py-3 pl-2 pr-5 text-right w-24">Rincian</th>
            </tr>
          </thead>

          <!-- TBODY -->
          <tbody class="divide-y divide-gray-100 bg-white">
            <!-- Loading skeleton state -->
            <tr v-if="loading">
              <td :colspan="isStudentMode ? 11 : 11" class="py-12 text-center text-gray-400">
                <div class="flex flex-col items-center justify-center gap-2">
                  <ArrowPathIcon class="h-6 w-6 animate-spin text-blue-600" />
                  <span class="text-xs font-medium text-gray-500">Memuat rincian laporan keuangan...</span>
                </div>
              </td>
            </tr>

            <!-- Empty state -->
            <tr v-else-if="!paginatedRows.length">
              <td :colspan="isStudentMode ? 11 : 11" class="py-16 text-center">
                <div class="mx-auto flex max-w-sm flex-col items-center justify-center gap-2">
                  <div class="flex h-12 w-12 items-center justify-center rounded-2xl bg-gray-100 text-gray-400">
                    <FunnelIcon class="h-6 w-6" />
                  </div>
                  <h3 class="mt-2 text-sm font-bold text-gray-900">Tidak ada data ditemukan</h3>
                  <p class="text-xs text-gray-500">
                    Tidak ditemukan data keuangan mahasiswa yang sesuai dengan kriteria filter saat ini.
                  </p>
                  <button
                    class="mt-2 inline-flex items-center gap-1 rounded-xl bg-blue-50 px-3 py-1.5 text-xs font-semibold text-blue-700 hover:bg-blue-100"
                    @click="resetFilters"
                  >
                    Reset Filter
                  </button>
                </div>
              </td>
            </tr>

            <!-- ROWS IN STUDENT MODE -->
            <template v-else-if="isStudentMode">
              <tr
                v-for="(row, idx) in paginatedRows"
                :key="row.student_id"
                class="transition hover:bg-blue-50/40"
              >
                <!-- Number -->
                <td class="py-3 pl-5 pr-2 text-center font-mono text-gray-400">
                  {{ (page - 1) * perPage + idx + 1 }}
                </td>

                <!-- Mahasiswa (Avatar, NIM, Nama) -->
                <td class="px-3 py-3">
                  <div class="flex items-center gap-2.5">
                    <div class="flex h-8 w-8 flex-shrink-0 items-center justify-center rounded-full bg-blue-100 text-xs font-bold text-blue-700 ring-2 ring-white">
                      {{ initials(row.name) }}
                    </div>
                    <div class="min-w-0">
                      <p class="truncate font-semibold text-gray-900 hover:text-blue-600">
                        {{ row.name }}
                      </p>
                      <p class="font-mono text-[11px] text-gray-500">
                        {{ row.nim }}
                      </p>
                    </div>
                  </div>
                </td>

                <!-- Prodi -->
                <td class="px-3 py-3 text-gray-600">
                  <p class="truncate font-medium text-gray-800">{{ row.prodi }}</p>
                </td>

                <!-- Kelas -->
                <td class="px-3 py-3 text-center">
                  <span
                    class="inline-flex rounded-full px-2 py-0.5 text-[10px] font-semibold"
                    :class="row.class_type === 'HYBRID' ? 'bg-purple-50 text-purple-700 ring-1 ring-inset ring-purple-700/10' : 'bg-gray-100 text-gray-700'"
                  >
                    {{ row.class_type }}
                  </span>
                </td>

                <!-- Tagihan Count -->
                <td class="px-3 py-3 text-right font-medium text-gray-700">
                  {{ row.invoices }}
                </td>

                <!-- Total Tagihan -->
                <td class="px-3 py-3 text-right font-mono font-medium text-gray-900 tabular-nums">
                  {{ rp(row.invoiced) }}
                </td>

                <!-- Potongan -->
                <td class="px-3 py-3 text-right font-mono text-gray-500 tabular-nums">
                  {{ rp(row.discount + row.scholarship) }}
                </td>

                <!-- Dibayar -->
                <td class="px-3 py-3 text-right font-mono font-bold text-emerald-600 tabular-nums">
                  {{ rp(row.paid) }}
                </td>

                <!-- Tunggakan -->
                <td class="px-3 py-3 text-right font-mono font-bold tabular-nums" :class="row.outstanding > 0 ? 'text-rose-600' : 'text-gray-400'">
                  {{ rp(row.outstanding) }}
                </td>

                <!-- Status Badge -->
                <td class="px-3 py-3 text-center">
                  <span
                    class="inline-flex items-center rounded-full px-2 py-0.5 text-[10px] font-bold tracking-wide"
                    :class="{
                      'bg-emerald-50 text-emerald-700 ring-1 ring-inset ring-emerald-600/20': row.status === 'LUNAS',
                      'bg-amber-50 text-amber-700 ring-1 ring-inset ring-amber-600/20': row.status === 'SEBAGIAN',
                      'bg-rose-50 text-rose-700 ring-1 ring-inset ring-rose-600/20': row.status === 'BELUM_BAYAR',
                    }"
                  >
                    {{ row.status === 'LUNAS' ? 'Lunas' : (row.status === 'SEBAGIAN' ? 'Sebagian' : 'Belum Bayar') }}
                  </span>
                </td>

                <!-- Aksi -->
                <td class="py-3 pl-2 pr-5 text-right">
                  <button
                    class="inline-flex items-center gap-1 rounded-lg p-1.5 text-blue-600 hover:bg-blue-100 transition"
                    title="Lihat riwayat keuangan mahasiswa"
                    @click="router.push(`/keuangan/mahasiswa/${row.student_id}`)"
                  >
                    <EyeIcon class="h-4 w-4" />
                  </button>
                </td>
              </tr>
            </template>

            <!-- ROWS IN AGGREGATE MODE -->
            <template v-else>
              <tr
                v-for="(row, idx) in paginatedRows"
                :key="row.label"
                class="transition hover:bg-blue-50/40"
              >
                <!-- Number -->
                <td class="py-3 pl-5 pr-2 text-center font-mono text-gray-400">
                  {{ idx + 1 }}
                </td>

                <!-- Category Label -->
                <td class="px-3 py-3 font-semibold text-gray-900">
                  {{ row.label }}
                </td>

                <!-- Students count -->
                <td class="px-3 py-3 text-right font-medium text-gray-700">
                  {{ row.students?.toLocaleString('id-ID') }}
                </td>

                <!-- Invoices count -->
                <td class="px-3 py-3 text-right font-medium text-gray-700">
                  {{ row.invoices?.toLocaleString('id-ID') }}
                </td>

                <!-- Total Tagihan -->
                <td class="px-3 py-3 text-right font-mono font-medium text-gray-900 tabular-nums">
                  {{ rp(row.invoiced) }}
                </td>

                <!-- Diskon -->
                <td class="px-3 py-3 text-right font-mono text-gray-500 tabular-nums">
                  {{ rp(row.discount) }}
                </td>

                <!-- Beasiswa -->
                <td class="px-3 py-3 text-right font-mono text-gray-500 tabular-nums">
                  {{ rp(row.scholarship) }}
                </td>

                <!-- Dibayar -->
                <td class="px-3 py-3 text-right font-mono font-bold text-emerald-600 tabular-nums">
                  {{ rp(row.paid) }}
                </td>

                <!-- Tunggakan -->
                <td class="px-3 py-3 text-right font-mono font-bold tabular-nums" :class="row.outstanding > 0 ? 'text-rose-600' : 'text-gray-400'">
                  {{ rp(row.outstanding) }}
                </td>

                <!-- % Realisasi -->
                <td class="px-3 py-3 text-center">
                  <div class="flex items-center justify-center gap-1.5">
                    <div class="h-2 w-16 overflow-hidden rounded-full bg-gray-200">
                      <div
                        class="h-full bg-emerald-500"
                        :style="{ width: percent(row.paid, row.invoiced - row.discount - row.scholarship) + '%' }"
                      />
                    </div>
                    <span class="font-mono text-[10px] font-bold text-gray-600">
                      {{ percent(row.paid, row.invoiced - row.discount - row.scholarship) }}%
                    </span>
                  </div>
                </td>

                <!-- Action: Drilldown -->
                <td class="py-3 pl-2 pr-5 text-right">
                  <button
                    class="rounded-lg bg-gray-100 px-2 py-1 text-[11px] font-semibold text-blue-700 hover:bg-blue-100 transition"
                    title="Buka rincian mahasiswa di kategori ini"
                    @click="drillDownToStudent(filters.group_by, row.label)"
                  >
                    Rincian
                  </button>
                </td>
              </tr>
            </template>
          </tbody>

          <!-- TFOOT TOTALS -->
          <tfoot v-if="report?.rows?.length" class="border-t-2 border-gray-200 bg-gray-50/90 font-bold text-gray-900">
            <!-- TFOOT FOR STUDENT MODE -->
            <tr v-if="isStudentMode">
              <td colspan="4" class="py-3.5 pl-5 pr-2 font-bold text-gray-900">
                TOTAL KESELURUHAN ({{ report.totals.students?.toLocaleString('id-ID') }} Mahasiswa)
              </td>
              <td class="px-3 py-3.5 text-right font-bold text-gray-900">
                {{ report.totals.invoices?.toLocaleString('id-ID') }}
              </td>
              <td class="px-3 py-3.5 text-right font-mono font-bold text-gray-900 tabular-nums">
                {{ rp(report.totals.invoiced) }}
              </td>
              <td class="px-3 py-3.5 text-right font-mono text-gray-600 tabular-nums">
                {{ rp(report.totals.discount + report.totals.scholarship) }}
              </td>
              <td class="px-3 py-3.5 text-right font-mono font-bold text-emerald-600 tabular-nums">
                {{ rp(report.totals.paid) }}
              </td>
              <td class="px-3 py-3.5 text-right font-mono font-bold text-rose-600 tabular-nums">
                {{ rp(report.totals.outstanding) }}
              </td>
              <td colspan="2" class="py-3.5 pl-2 pr-5 text-center text-xs font-semibold text-gray-500">
                {{ percent(report.totals.paid, report.totals.invoiced - report.totals.discount - report.totals.scholarship) }}% Terbayar
              </td>
            </tr>

            <!-- TFOOT FOR AGGREGATE MODE -->
            <tr v-else>
              <td colspan="2" class="py-3.5 pl-5 pr-2 font-bold text-gray-900">
                TOTAL KESELURUHAN
              </td>
              <td class="px-3 py-3.5 text-right font-bold text-gray-900">
                {{ report.totals.students?.toLocaleString('id-ID') }}
              </td>
              <td class="px-3 py-3.5 text-right font-bold text-gray-900">
                {{ report.totals.invoices?.toLocaleString('id-ID') }}
              </td>
              <td class="px-3 py-3.5 text-right font-mono font-bold text-gray-900 tabular-nums">
                {{ rp(report.totals.invoiced) }}
              </td>
              <td class="px-3 py-3.5 text-right font-mono text-gray-600 tabular-nums">
                {{ rp(report.totals.discount) }}
              </td>
              <td class="px-3 py-3.5 text-right font-mono text-gray-600 tabular-nums">
                {{ rp(report.totals.scholarship) }}
              </td>
              <td class="px-3 py-3.5 text-right font-mono font-bold text-emerald-600 tabular-nums">
                {{ rp(report.totals.paid) }}
              </td>
              <td class="px-3 py-3.5 text-right font-mono font-bold text-rose-600 tabular-nums">
                {{ rp(report.totals.outstanding) }}
              </td>
              <td class="px-3 py-3.5 text-center text-xs font-semibold text-gray-500">
                {{ percent(report.totals.paid, report.totals.invoiced - report.totals.discount - report.totals.scholarship) }}%
              </td>
              <td class="py-3.5 pl-2 pr-5"></td>
            </tr>
          </tfoot>
        </table>
      </div>

      <!-- Pagination Footer Bar (Active in Student Mode) -->
      <div
        v-if="isStudentMode && totalPages > 1"
        class="flex flex-col gap-3 border-t border-gray-200 bg-white px-5 py-3.5 sm:flex-row sm:items-center sm:justify-between"
      >
        <p class="text-xs text-gray-500">
          Menampilkan <span class="font-semibold text-gray-900">{{ (page - 1) * perPage + 1 }}</span> sampai
          <span class="font-semibold text-gray-900">{{ Math.min(page * perPage, totalRowsCount) }}</span> dari
          <span class="font-semibold text-gray-900">{{ totalRowsCount }}</span> mahasiswa
        </p>

        <div class="flex items-center gap-1.5">
          <button
            class="rounded-lg border border-gray-300 bg-white px-2.5 py-1 text-xs font-medium text-gray-700 transition hover:bg-gray-50 disabled:cursor-not-allowed disabled:opacity-40"
            :disabled="page <= 1"
            @click="page = 1"
          >
            Awal
          </button>
          <button
            class="rounded-lg border border-gray-300 bg-white px-2.5 py-1 text-xs font-medium text-gray-700 transition hover:bg-gray-50 disabled:cursor-not-allowed disabled:opacity-40"
            :disabled="page <= 1"
            @click="page--"
          >
            Sebelumnya
          </button>

          <span class="px-2 text-xs font-semibold text-gray-700">
            Hal {{ page }} / {{ totalPages }}
          </span>

          <button
            class="rounded-lg border border-gray-300 bg-white px-2.5 py-1 text-xs font-medium text-gray-700 transition hover:bg-gray-50 disabled:cursor-not-allowed disabled:opacity-40"
            :disabled="page >= totalPages"
            @click="page++"
          >
            Selanjutnya
          </button>
          <button
            class="rounded-lg border border-gray-300 bg-white px-2.5 py-1 text-xs font-medium text-gray-700 transition hover:bg-gray-50 disabled:cursor-not-allowed disabled:opacity-40"
            :disabled="page >= totalPages"
            @click="page = totalPages"
          >
            Akhir
          </button>
        </div>
      </div>
    </div>
  </div>
</template>
