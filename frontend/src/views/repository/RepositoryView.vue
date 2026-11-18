<script setup lang="ts">
import { ref, onMounted, watch } from 'vue'
import { useRouter, useRoute } from 'vue-router'
import { MagnifyingGlassIcon, BookOpenIcon, AcademicCapIcon, BeakerIcon } from '@heroicons/vue/24/outline'
import api from '@/services/api'

interface RepoItem {
  id: number; source: string; type: string; type_label: string
  title: string; author: string; study_program?: string
  year?: number; abstract?: string; cover_url?: string; has_file: boolean
  published_at: string
}

const router = useRouter()
const route  = useRoute()

const items      = ref<RepoItem[]>([])
const loading    = ref(false)
const total      = ref(0)
const currentPage= ref(1)
const lastPage   = ref(1)
const stats      = ref({ total: 0, penelitian: 0, pengabdian: 0, skripsi: 0, karya_dosen: 0 })

const search     = ref((route.query.search as string) || '')
const filterType = ref((route.query.type as string) || '')
const filterYear = ref((route.query.year as string) || '')
const filterProdi= ref((route.query.prodi as string) || '')

const programs   = ref<any[]>([])
const years      = ref<number[]>([])

const typeOptions = [
  { value: '', label: 'Semua Jenis' },
  { value: 'penelitian', label: '🔬 Penelitian' },
  { value: 'pengabdian', label: '🤝 Pengabdian' },
  { value: 'skripsi', label: '🎓 Skripsi' },
  { value: 'buku', label: '📚 Buku' },
  { value: 'modul_ajar', label: '📝 Modul Ajar' },
  { value: 'hki_paten', label: '🏅 HKI / Paten' },
  { value: 'penelitian_mandiri', label: '🔬 Penelitian Mandiri' },
  { value: 'pengabdian_mandiri', label: '🤝 Pengabdian Mandiri' },
]

const sourceIcon: Record<string, string> = {
  penelitian: '🔬', skripsi: '🎓', karya_dosen: '📚'
}
const sourceBg: Record<string, string> = {
  penelitian: 'bg-blue-50', skripsi: 'bg-green-50', karya_dosen: 'bg-purple-50'
}

onMounted(async () => {
  // Load stats, programs, years bersamaan
  const [statsRes, prodiRes] = await Promise.all([
    api.get('/repository/stats').catch(() => ({ data: stats.value })),
    api.get('/repository/study-programs').catch(() => ({ data: [] })),
  ])
  stats.value    = statsRes.data
  programs.value = prodiRes.data

  // Generate tahun 5 tahun terakhir
  const now = new Date().getFullYear()
  years.value = Array.from({ length: 6 }, (_, i) => now - i)

  load()
})

watch([filterType, filterYear, filterProdi], () => { currentPage.value = 1; load() })

let searchTimer: ReturnType<typeof setTimeout>
watch(search, () => {
  clearTimeout(searchTimer)
  searchTimer = setTimeout(() => { currentPage.value = 1; load() }, 400)
})

async function load(page = currentPage.value) {
  loading.value = true
  try {
    const { data } = await api.get('/repository', {
      params: {
        search: search.value || undefined,
        type: filterType.value || undefined,
        year: filterYear.value || undefined,
        study_program_id: filterProdi.value || undefined,
        page,
        per_page: 12,
      },
    })
    items.value      = data.data
    total.value      = data.total
    currentPage.value= data.current_page
    lastPage.value   = data.last_page
  } catch { } finally { loading.value = false }
}

function goDetail(item: RepoItem) {
  router.push(`/repository/${item.source}/${item.id}`)
}

function truncate(text: string | undefined, max = 120) {
  if (!text) return '-'
  return text.length > max ? text.substring(0, max) + '...' : text
}
</script>

<template>
  <div class="space-y-7">
    <!-- Hero -->
    <div class="relative overflow-hidden bg-gradient-to-br from-slate-950 via-blue-900 to-blue-700 rounded-3xl p-6 sm:p-8 text-white shadow-xl shadow-blue-900/10">
      <div class="absolute -right-16 -top-24 w-72 h-72 rounded-full bg-blue-400/20 blur-3xl pointer-events-none" />
      <div class="absolute -left-20 -bottom-32 w-80 h-80 rounded-full bg-cyan-300/10 blur-3xl pointer-events-none" />
      <div class="relative">
        <div class="flex flex-wrap items-center gap-2 mb-4">
          <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full bg-white/10 border border-white/15 text-xs font-medium text-blue-100">
            <BookOpenIcon class="w-3.5 h-3.5" /> Pusat Karya Ilmiah
          </span>
          <span class="text-xs text-blue-200">Akses publik · Terintegrasi NURIS</span>
        </div>
        <h1 class="text-2xl sm:text-3xl font-bold tracking-tight mb-2">Repository Karya Ilmiah</h1>
        <p class="text-blue-100 text-sm sm:text-base max-w-2xl mb-6">Temukan penelitian, skripsi, pengabdian, dan karya dosen dalam satu ruang pengetahuan akademik.</p>

        <!-- Search Box -->
        <div class="relative max-w-3xl">
          <MagnifyingGlassIcon class="absolute left-4 top-1/2 -translate-y-1/2 w-5 h-5 text-gray-400" />
          <input
            v-model="search"
            type="text"
            placeholder="Cari judul, abstrak, kata kunci..."
            class="w-full pl-11 pr-4 py-3.5 rounded-2xl bg-white text-gray-900 placeholder:text-gray-400 text-sm shadow-lg focus:outline-none focus:ring-4 focus:ring-blue-300/30"
          />
        </div>

        <!-- Stats -->
        <div class="grid grid-cols-2 sm:grid-cols-5 gap-2.5 mt-6">
          <div v-for="s in [
            { label: 'Total Karya', value: stats.total, icon: '📖' },
            { label: 'Penelitian', value: stats.penelitian, icon: '🔬' },
            { label: 'Pengabdian', value: stats.pengabdian, icon: '🤝' },
            { label: 'Skripsi', value: stats.skripsi, icon: '🎓' },
            { label: 'Karya Dosen', value: stats.karya_dosen, icon: '📚' },
          ]" :key="s.label" class="bg-white/10 border border-white/10 rounded-2xl px-3 py-3 text-center backdrop-blur-sm">
            <p class="text-lg font-bold">{{ s.icon }} {{ s.value }}</p>
            <p class="text-[11px] text-blue-100">{{ s.label }}</p>
          </div>
        </div>
      </div>
    </div>

    <!-- Filters -->
    <div class="flex flex-wrap gap-3 items-center bg-white rounded-2xl border border-gray-200/80 p-4 shadow-sm">
      <div class="w-full sm:w-auto sm:min-w-44">
        <label class="block text-[11px] font-semibold uppercase tracking-wide text-gray-400 mb-1">Jenis karya</label>
        <select v-model="filterType" class="w-full px-3 py-2.5 rounded-xl border border-gray-200 bg-gray-50 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500/30 focus:border-blue-500">
        <option v-for="t in typeOptions" :key="t.value" :value="t.value">{{ t.label }}</option>
        </select>
      </div>

      <div class="w-[calc(50%-0.375rem)] sm:w-auto sm:min-w-36">
        <label class="block text-[11px] font-semibold uppercase tracking-wide text-gray-400 mb-1">Tahun</label>
        <select v-model="filterYear" class="w-full px-3 py-2.5 rounded-xl border border-gray-200 bg-gray-50 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500/30 focus:border-blue-500">
        <option value="">Semua Tahun</option>
        <option v-for="y in years" :key="y" :value="y">{{ y }}</option>
        </select>
      </div>

      <div class="w-[calc(50%-0.375rem)] sm:w-auto sm:min-w-52">
        <label class="block text-[11px] font-semibold uppercase tracking-wide text-gray-400 mb-1">Program studi</label>
        <select v-model="filterProdi" class="w-full px-3 py-2.5 rounded-xl border border-gray-200 bg-gray-50 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500/30 focus:border-blue-500">
        <option value="">Semua Program Studi</option>
        <option v-for="p in programs" :key="p.id" :value="p.id">{{ p.name }}</option>
        </select>
      </div>

      <span v-if="total > 0" class="text-sm text-gray-500 sm:ml-auto self-end pb-2">{{ total }} karya ditemukan</span>
    </div>

    <!-- Loading -->
    <div v-if="loading" class="flex items-center justify-center py-16 text-gray-400">
      <svg class="animate-spin w-8 h-8 mr-3" fill="none" viewBox="0 0 24 24">
        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z" />
      </svg>
      Memuat karya ilmiah...
    </div>

    <!-- Empty -->
    <div v-else-if="!items.length" class="text-center py-16 text-gray-400">
      <BookOpenIcon class="w-16 h-16 mx-auto mb-3 text-gray-300" />
      <p class="font-medium text-gray-500">Tidak ada karya yang ditemukan</p>
      <p class="text-sm mt-1">Coba ubah kata kunci atau filter</p>
    </div>

    <!-- Grid -->
    <div v-else class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-5">
      <div
        v-for="item in items"
        :key="`${item.source}-${item.id}`"
        class="bg-white rounded-2xl border border-gray-200/80 overflow-hidden hover:-translate-y-1 hover:border-blue-300 hover:shadow-xl hover:shadow-blue-900/10 transition-all cursor-pointer group"
        @click="goDetail(item)"
      >
        <!-- Cover / Thumbnail -->
        <div :class="['h-48 flex items-center justify-center text-4xl p-3', sourceBg[item.source] ?? 'bg-gray-50']">
          <img v-if="item.cover_url" :src="item.cover_url" :alt="item.title" class="h-full w-full object-contain drop-shadow-sm transition-transform duration-300 group-hover:scale-[1.03]" />
          <span v-else>{{ sourceIcon[item.source] ?? '📄' }}</span>
        </div>

        <!-- Info -->
        <div class="p-4.5">
          <div class="flex items-center gap-1.5 mb-2">
            <span class="inline-flex px-2 py-0.5 rounded-full text-xs font-medium bg-blue-100 text-blue-700">
              {{ item.type_label }}
            </span>
            <span v-if="item.year" class="text-xs text-gray-400">{{ item.year }}</span>
          </div>
          <h3 class="text-sm font-semibold text-gray-900 line-clamp-2 group-hover:text-blue-700 transition-colors leading-snug">
            {{ item.title }}
          </h3>
          <p class="text-xs text-gray-500 mt-1.5 truncate">{{ item.author }}</p>
          <p v-if="item.study_program" class="text-xs text-gray-400 truncate">{{ item.study_program }}</p>

          <div class="mt-3 flex items-center justify-between">
            <button class="text-xs text-blue-600 group-hover:text-blue-700 font-semibold">Lihat detail <span class="inline-block transition-transform group-hover:translate-x-0.5">→</span></button>
            <span v-if="item.has_file" class="text-xs text-green-600" title="File tersedia">📎</span>
          </div>
        </div>
      </div>
    </div>

    <!-- Pagination -->
    <div v-if="lastPage > 1" class="flex items-center justify-center gap-2">
      <button
        v-for="p in lastPage"
        :key="p"
        :disabled="p === currentPage"
        :class="[
          'w-9 h-9 rounded-lg text-sm font-medium transition-colors',
          p === currentPage ? 'bg-blue-600 text-white' : 'bg-white border border-gray-300 text-gray-600 hover:bg-gray-50',
        ]"
        @click="load(p)"
      >
        {{ p }}
      </button>
    </div>
  </div>
</template>
