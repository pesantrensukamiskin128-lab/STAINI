<script setup lang="ts">
import { ref, onMounted, watch } from 'vue'
import { useRouter } from 'vue-router'
import { ArrowLeftIcon, ArrowDownTrayIcon } from '@heroicons/vue/24/outline'
import api from '@/services/api'

const router = useRouter()
const loading = ref(false)
const downloading = ref(false)
const error = ref('')
const rows = ref<any[]>([])
const summary = ref<any>({ total_krs: 0, total_students: 0, by_class_type: {} })
const meta = ref<any>({ total: 0, current_page: 1, last_page: 1 })
const semesters = ref<any[]>([])
const prodis = ref<any[]>([])
const page = ref(1)

const filters = ref({ semester_id: '', study_program_id: '', class_type: '', status: '', search: '' })

const classTypes = [
  { value: 'REGULER', label: 'Reguler' },
  { value: 'HYBRID', label: 'Hybrid' },
  { value: 'KHUSUS', label: 'Khusus' },
]
const statuses = [
  { value: 'DRAFT', label: 'Draf' },
  { value: 'SUBMITTED', label: 'Diajukan' },
  { value: 'APPROVED', label: 'Disetujui Dosen Wali' },
  { value: 'DISETUJUI_PRODI', label: 'Disetujui Prodi' },
  { value: 'DITANDATANGANI', label: 'Ditandatangani' },
  { value: 'REJECTED', label: 'Ditolak' },
]

const list = (res: any) => res?.data?.data ?? res?.data ?? []
const params = () => Object.fromEntries(Object.entries(filters.value).filter(([, v]) => v !== ''))

async function load() {
  loading.value = true
  error.value = ''
  try {
    const { data } = await api.get('/reports/krs-recap', { params: { ...params(), page: page.value } })
    rows.value = data.data
    meta.value = data.meta
    summary.value = data.summary
  } catch (e: any) {
    error.value = e?.response?.data?.message ?? 'Gagal memuat rekap KRS.'
  } finally {
    loading.value = false
  }
}

async function download() {
  downloading.value = true
  try {
    const res = await api.get('/reports/krs-recap/excel', { params: params(), responseType: 'blob' })
    const url = URL.createObjectURL(new Blob([res.data]))
    const a = document.createElement('a')
    a.href = url
    a.download = `rekap-krs-mahasiswa-${Date.now()}.xlsx`
    document.body.appendChild(a)
    a.click()
    a.remove()
    URL.revokeObjectURL(url)
  } catch {
    error.value = 'Gagal mengunduh file Excel.'
  } finally {
    downloading.value = false
  }
}

watch(filters, () => { page.value = 1; load() }, { deep: true })
watch(page, load)

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
    <div class="flex items-center justify-between gap-3 flex-wrap">
      <div class="flex items-center gap-3">
        <button class="p-2 rounded-lg hover:bg-gray-100" @click="router.back()"><ArrowLeftIcon class="w-5 h-5 text-gray-500" /></button>
        <div>
          <h1 class="text-xl font-bold text-gray-900">Rekap KRS Mahasiswa</h1>
          <p class="text-sm text-gray-500 mt-0.5">KRS mahasiswa per semester beserta jenis kelas (reguler/hybrid/khusus)</p>
        </div>
      </div>
      <button
        class="inline-flex items-center gap-2 px-4 py-2 bg-green-600 text-white text-sm rounded-lg hover:bg-green-700 disabled:opacity-50"
        :disabled="downloading || !meta.total"
        @click="download"
      >
        <ArrowDownTrayIcon class="w-4 h-4" />
        {{ downloading ? 'Mengunduh...' : 'Unduh Excel' }}
      </button>
    </div>

    <div class="bg-white rounded-xl border border-gray-200 p-4 grid grid-cols-1 md:grid-cols-5 gap-3">
      <select v-model="filters.semester_id" class="px-3 py-2 border rounded-lg text-sm">
        <option value="">Semua semester</option>
        <option v-for="s in semesters" :key="s.id" :value="s.id">{{ s.name }}</option>
      </select>
      <select v-model="filters.study_program_id" class="px-3 py-2 border rounded-lg text-sm">
        <option value="">Semua prodi</option>
        <option v-for="p in prodis" :key="p.id" :value="p.id">{{ p.name }}</option>
      </select>
      <select v-model="filters.class_type" class="px-3 py-2 border rounded-lg text-sm">
        <option value="">Semua jenis kelas</option>
        <option v-for="c in classTypes" :key="c.value" :value="c.value">{{ c.label }}</option>
      </select>
      <select v-model="filters.status" class="px-3 py-2 border rounded-lg text-sm">
        <option value="">Semua status KRS</option>
        <option v-for="s in statuses" :key="s.value" :value="s.value">{{ s.label }}</option>
      </select>
      <input v-model.lazy="filters.search" placeholder="Cari NIM / nama" class="px-3 py-2 border rounded-lg text-sm" />
    </div>

    <div class="grid grid-cols-2 md:grid-cols-5 gap-3">
      <div class="bg-white rounded-xl border border-gray-200 p-4 text-center"><p class="text-xl font-bold">{{ summary.total_krs }}</p><p class="text-xs text-gray-500">Total KRS</p></div>
      <div class="bg-white rounded-xl border border-gray-200 p-4 text-center"><p class="text-xl font-bold">{{ summary.total_students }}</p><p class="text-xs text-gray-500">Mahasiswa</p></div>
      <div v-for="c in classTypes" :key="c.value" class="bg-white rounded-xl border border-gray-200 p-4 text-center">
        <p class="text-xl font-bold">{{ summary.by_class_type?.[c.value] ?? 0 }}</p>
        <p class="text-xs text-gray-500">KRS {{ c.label }}</p>
      </div>
    </div>

    <p v-if="error" class="text-sm text-red-600">{{ error }}</p>

    <div class="bg-white rounded-xl border border-gray-200 overflow-x-auto">
      <table class="min-w-full text-sm">
        <thead class="bg-gray-50 text-left text-xs text-gray-600">
          <tr>
            <th class="px-3 py-2">Semester</th><th class="px-3 py-2">NIM</th><th class="px-3 py-2">Nama</th>
            <th class="px-3 py-2">Prodi</th><th class="px-3 py-2">Kelas</th><th class="px-3 py-2">Status KRS</th>
            <th class="px-3 py-2 text-right">MK</th><th class="px-3 py-2 text-right">SKS</th>
          </tr>
        </thead>
        <tbody>
          <tr v-if="loading"><td colspan="8" class="px-3 py-8 text-center text-gray-400">Memuat...</td></tr>
          <tr v-else-if="!rows.length"><td colspan="8" class="px-3 py-8 text-center text-gray-400">Tidak ada data.</td></tr>
          <template v-else>
            <tr v-for="r in rows" :key="r.id" class="border-t">
              <td class="px-3 py-2">{{ r.semester }}</td>
              <td class="px-3 py-2">{{ r.nim }}</td>
              <td class="px-3 py-2">{{ r.name }}</td>
              <td class="px-3 py-2">{{ r.prodi }}</td>
              <td class="px-3 py-2">{{ r.class_type }}</td>
              <td class="px-3 py-2">{{ r.status }}</td>
              <td class="px-3 py-2 text-right">{{ r.course_count }}</td>
              <td class="px-3 py-2 text-right">{{ r.total_credits }}</td>
            </tr>
          </template>
        </tbody>
      </table>
    </div>

    <div v-if="meta.last_page > 1" class="flex items-center justify-center gap-3 text-sm">
      <button class="px-3 py-1 border rounded disabled:opacity-40" :disabled="page <= 1" @click="page--">Sebelumnya</button>
      <span>{{ meta.current_page }} / {{ meta.last_page }}</span>
      <button class="px-3 py-1 border rounded disabled:opacity-40" :disabled="page >= meta.last_page" @click="page++">Berikutnya</button>
    </div>
  </div>
</template>
