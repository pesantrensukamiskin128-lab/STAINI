<script setup lang="ts">
import { onMounted, ref, watch } from 'vue'
import { useToast } from 'vue-toastification'
import api from '@/services/api'

type ReviewStatus = 'PENDING' | 'APPROVED' | 'REJECTED'
type JournalReview = {
  id: number
  meeting_number: number
  meeting_date: string
  topic: string
  description: string | null
  learning_activity: string | null
  delivery_mode: string | null
  latitude: number | null
  longitude: number | null
  photo_url: string | null
  review_status: ReviewStatus
  reviewed_at: string | null
  review_note: string | null
  class_?: { id: number; name?: string; course?: { code?: string; name?: string; study_program?: { name?: string } }; semester?: { name?: string } }
  lecturer?: { name?: string; full_name?: string }
  reviewer?: { name?: string }
}

const toast = useToast()
const status = ref<ReviewStatus>('PENDING')
const journals = ref<JournalReview[]>([])
const loading = ref(false)
const savingId = ref<number | null>(null)
const currentPage = ref(1)
const lastPage = ref(1)
const rejectionId = ref<number | null>(null)
const rejectionNote = ref('')

const lecturerFilter = ref('')
const courseFilter = ref('')
const programFilter = ref<number | ''>('')
const programs = ref<{ id: number; name: string }[]>([])
let filterTimer: ReturnType<typeof setTimeout> | undefined

watch([status, programFilter], () => {
  currentPage.value = 1
  void loadJournals()
})

watch([lecturerFilter, courseFilter], () => {
  clearTimeout(filterTimer)
  filterTimer = setTimeout(() => {
    currentPage.value = 1
    void loadJournals()
  }, 400)
})

function resetFilters() {
  lecturerFilter.value = ''
  courseFilter.value = ''
  programFilter.value = ''
}

onMounted(async () => {
  void loadJournals()
  try {
    const { data } = await api.get('/study-programs/all')
    programs.value = Array.isArray(data) ? data : (data.data ?? [])
  } catch {
    programs.value = []
  }
})

async function loadJournals() {
  loading.value = true
  try {
    const { data } = await api.get('/finance/honor/journal-reviews', {
      params: {
        status: status.value,
        page: currentPage.value,
        per_page: 50,
        lecturer: lecturerFilter.value.trim() || undefined,
        course: courseFilter.value.trim() || undefined,
        study_program_id: programFilter.value || undefined,
      },
    })
    journals.value = data.data
    currentPage.value = data.current_page
    lastPage.value = data.last_page
  } catch (error: any) {
    toast.error(error?.response?.data?.message ?? 'Daftar jurnal gagal dimuat.')
  } finally {
    loading.value = false
  }
}

async function changePage(page: number) {
  if (page < 1 || page > lastPage.value) return
  currentPage.value = page
  await loadJournals()
}

function beginRejection(journal: JournalReview) {
  rejectionId.value = journal.id
  rejectionNote.value = ''
}

async function review(journal: JournalReview, decision: 'APPROVED' | 'REJECTED') {
  const note = rejectionNote.value.trim()
  if (decision === 'REJECTED' && !note) {
    toast.error('Isi alasan penolakan agar dosen dapat memperbaiki jurnal.')
    return
  }

  savingId.value = journal.id
  try {
    await api.patch(`/finance/honor/journal-reviews/${journal.id}`, {
      status: decision,
      ...(decision === 'REJECTED' ? { review_note: note } : {}),
    })
    toast.success(decision === 'APPROVED'
      ? 'Jurnal disetujui dan dihitung satu kali untuk honor.'
      : 'Jurnal ditolak dan dikembalikan untuk diperbaiki.')
    rejectionId.value = null
    rejectionNote.value = ''
    await loadJournals()
  } catch (error: any) {
    toast.error(error?.response?.data?.message ?? 'Keputusan jurnal gagal disimpan.')
  } finally {
    savingId.value = null
  }
}

function formatDate(date?: string | null) {
  const datePart = String(date ?? '').slice(0, 10)
  if (!/^\d{4}-\d{2}-\d{2}$/.test(datePart)) return '-'
  return new Date(`${datePart}T00:00:00`).toLocaleDateString('id-ID', {
    day: 'numeric',
    month: 'long',
    year: 'numeric',
  })
}
</script>

<template>
  <div class="max-w-6xl mx-auto space-y-5">
    <header class="flex flex-wrap items-end justify-between gap-3">
      <div>
        <h1 class="text-xl font-bold text-gray-900">Pemeriksaan Jurnal Perkuliahan</h1>
        <p class="mt-1 text-sm text-gray-500">Periksa isi jurnal dan foto bukti sebelum pertemuan masuk ke perhitungan honor dosen.</p>
      </div>
      <label class="text-sm text-gray-600">
        Status
        <select v-model="status" class="ml-2 rounded-lg border border-gray-300 bg-white px-3 py-2">
          <option value="PENDING">Menunggu pemeriksaan</option>
          <option value="APPROVED">Disetujui</option>
          <option value="REJECTED">Ditolak</option>
        </select>
      </label>
    </header>

    <div class="grid gap-3 rounded-xl border border-gray-200 bg-white p-4 md:grid-cols-4">
      <input v-model="lecturerFilter" type="search" placeholder="Cari nama dosen" class="rounded-lg border border-gray-300 px-3 py-2 text-sm" />
      <select v-model="programFilter" class="rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm">
        <option value="">Semua prodi</option>
        <option v-for="p in programs" :key="p.id" :value="p.id">{{ p.name }}</option>
      </select>
      <input v-model="courseFilter" type="search" placeholder="Cari matakuliah (nama/kode)" class="rounded-lg border border-gray-300 px-3 py-2 text-sm" />
      <button type="button" class="rounded-lg border border-gray-300 px-3 py-2 text-sm text-gray-600 hover:bg-gray-50" @click="resetFilters">Reset filter</button>
    </div>

    <div v-if="loading" class="rounded-xl border border-gray-200 bg-white py-12 text-center text-sm text-gray-500">
      Memuat jurnal...
    </div>
    <div v-else-if="journals.length === 0" class="rounded-xl border border-gray-200 bg-white py-12 text-center text-sm text-gray-500">
      Tidak ada jurnal {{ status === 'PENDING' ? 'yang menunggu pemeriksaan' : status === 'APPROVED' ? 'yang disetujui' : 'yang ditolak' }}.
    </div>

    <article v-for="journal in journals" :key="journal.id" class="overflow-hidden rounded-xl border border-gray-200 bg-white">
      <div class="grid gap-5 p-5 md:grid-cols-[minmax(0,1fr)_240px]">
        <div class="min-w-0 space-y-3">
          <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
              <p class="text-xs font-medium uppercase tracking-wide text-gray-500">
                Pertemuan {{ journal.meeting_number }} · {{ formatDate(journal.meeting_date) }}
              </p>
              <h2 class="mt-1 text-lg font-semibold text-gray-900">{{ journal.topic }}</h2>
              <p class="mt-1 text-sm font-medium text-gray-800">
                {{ journal.class_?.course?.code ? `${journal.class_.course.code} · ` : '' }}{{ journal.class_?.course?.name ?? '-' }}
              </p>
              <p class="text-sm text-gray-600">
                {{ journal.class_?.course?.study_program?.name ? `${journal.class_.course.study_program.name} · ` : '' }}Kelas {{ journal.class_?.name ?? journal.class_?.id ?? '-' }}
                · {{ journal.class_?.semester?.name ?? '-' }}
              </p>
              <p class="text-sm text-gray-600">Dosen: {{ journal.lecturer?.name ?? journal.lecturer?.full_name ?? '-' }}</p>
            </div>
            <span
              :class="[
                'rounded-full px-3 py-1 text-xs font-semibold',
                journal.review_status === 'APPROVED' ? 'bg-green-100 text-green-700'
                  : journal.review_status === 'REJECTED' ? 'bg-red-100 text-red-700'
                    : 'bg-amber-100 text-amber-800',
              ]"
            >
              {{ journal.review_status === 'APPROVED' ? 'Disetujui' : journal.review_status === 'REJECTED' ? 'Ditolak' : 'Menunggu pemeriksaan' }}
            </span>
          </div>

          <div class="grid gap-3 rounded-lg bg-gray-50 p-4 text-sm sm:grid-cols-2">
            <div>
              <p class="text-xs font-medium uppercase text-gray-400">Cara mengajar</p>
              <p class="mt-1 text-gray-800">{{ journal.delivery_mode === 'ONLINE' ? 'Daring' : journal.delivery_mode === 'ONSITE' ? 'Luring' : 'Belum dicatat' }}</p>
            </div>
            <div v-if="journal.latitude != null && journal.longitude != null">
              <p class="text-xs font-medium uppercase text-gray-400">Lokasi</p>
              <a
                class="mt-1 inline-block text-blue-700 hover:underline"
                :href="`https://www.google.com/maps?q=${journal.latitude},${journal.longitude}`"
                target="_blank"
                rel="noopener noreferrer"
              >
                {{ journal.latitude }}, {{ journal.longitude }}
              </a>
            </div>
            <div class="sm:col-span-2">
              <p class="text-xs font-medium uppercase text-gray-400">Uraian jurnal</p>
              <p class="mt-1 whitespace-pre-wrap text-gray-800">{{ journal.description || journal.learning_activity || 'Tidak ada uraian.' }}</p>
            </div>
            <div v-if="journal.learning_activity && journal.description" class="sm:col-span-2">
              <p class="text-xs font-medium uppercase text-gray-400">Aktivitas pembelajaran</p>
              <p class="mt-1 whitespace-pre-wrap text-gray-800">{{ journal.learning_activity }}</p>
            </div>
          </div>

          <p v-if="journal.review_note" class="rounded-lg bg-red-50 px-4 py-3 text-sm text-red-800">
            Catatan pemeriksaan: {{ journal.review_note }}
            <span v-if="journal.reviewer?.name" class="block pt-1 text-xs text-red-600">Oleh {{ journal.reviewer.name }}</span>
          </p>
        </div>

        <div>
          <a v-if="journal.photo_url" :href="journal.photo_url" target="_blank" rel="noopener noreferrer">
            <img :src="journal.photo_url" alt="Foto bukti jurnal perkuliahan" class="max-h-64 w-full rounded-lg border border-gray-200 object-cover">
            <span class="mt-2 block text-center text-xs text-blue-700">Buka foto ukuran penuh</span>
          </a>
          <div v-else class="flex min-h-40 items-center justify-center rounded-lg border border-dashed border-gray-300 bg-gray-50 px-4 text-center text-sm text-gray-500">
            Jurnal ini tidak memiliki foto bukti.
          </div>
        </div>
      </div>

      <div v-if="status === 'PENDING'" class="flex flex-wrap items-center justify-end gap-2 border-t border-gray-100 bg-gray-50 px-5 py-4">
        <template v-if="rejectionId === journal.id">
          <textarea
            v-model="rejectionNote"
            class="min-h-10 flex-1 rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm"
            rows="2"
            maxlength="2000"
            placeholder="Tuliskan alasan penolakan untuk dosen"
          />
          <button class="rounded-lg border border-gray-300 px-4 py-2 text-sm text-gray-700 hover:bg-white" @click="rejectionId = null">
            Batal
          </button>
          <button
            class="rounded-lg bg-red-600 px-4 py-2 text-sm font-medium text-white hover:bg-red-700 disabled:opacity-50"
            :disabled="savingId === journal.id"
            @click="review(journal, 'REJECTED')"
          >
            Kirim penolakan
          </button>
        </template>
        <template v-else>
          <button
            class="rounded-lg border border-red-200 px-4 py-2 text-sm font-medium text-red-700 hover:bg-red-50 disabled:opacity-50"
            :disabled="savingId === journal.id"
            @click="beginRejection(journal)"
          >
            Tolak / minta perbaikan
          </button>
          <button
            class="rounded-lg bg-green-600 px-4 py-2 text-sm font-medium text-white hover:bg-green-700 disabled:opacity-50"
            :disabled="savingId === journal.id"
            @click="review(journal, 'APPROVED')"
          >
            Setujui
          </button>
        </template>
      </div>
    </article>

    <div v-if="lastPage > 1" class="flex items-center justify-between rounded-xl border border-gray-200 bg-white px-4 py-3 text-sm">
      <button class="rounded-lg px-3 py-2 text-blue-700 hover:bg-blue-50 disabled:text-gray-400" :disabled="currentPage <= 1 || loading" @click="changePage(currentPage - 1)">
        Sebelumnya
      </button>
      <span class="text-gray-600">Halaman {{ currentPage }} dari {{ lastPage }}</span>
      <button class="rounded-lg px-3 py-2 text-blue-700 hover:bg-blue-50 disabled:text-gray-400" :disabled="currentPage >= lastPage || loading" @click="changePage(currentPage + 1)">
        Berikutnya
      </button>
    </div>
  </div>
</template>
