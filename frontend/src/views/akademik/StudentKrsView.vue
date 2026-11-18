<script setup lang="ts">
import { ref, onMounted, computed } from 'vue'
import { useToast } from 'vue-toastification'
import { PlusIcon, TrashIcon, PaperAirplaneIcon, CheckCircleIcon, ExclamationTriangleIcon } from '@heroicons/vue/24/outline'
import BaseModal from '@/components/ui/BaseModal.vue'
import api from '@/services/api'
import { getKrsStatusColor, getKrsStatusLabel } from '@/utils/krsStatus'

const toast = useToast()
const loading = ref(true)
const student = ref<any>(null)
const krs = ref<any>(null)
const availableClasses = ref<any[]>([])
const activeSemester = ref<any>(null)
const eligibility = ref<any>(null)

// Add course modal
const addModal = ref(false)
const selectedCourseId = ref('')
const selectedClassId = ref('')
const addingCourse = ref(false)

onMounted(async () => {
  try {
    // Get student info
    const { data: me } = await api.get('/auth/me')
    const { data: dashboard } = await api.get('/dashboard')
    student.value = { ...dashboard.student, study_program_id: me.student?.study_program_id }

    // Get active semester
    const { data: semesters } = await api.get('/semesters')
    const semData = semesters.data ?? semesters
    activeSemester.value = semData.find((s: any) => s.is_active) ?? semData[0]

    if (activeSemester.value) {
      await Promise.all([loadKrs(), loadAvailableClasses(), loadEligibility()])
    }
  } catch (e: any) { console.error(e) }
  finally { loading.value = false }
})

async function loadKrs() {
  try {
    const { data } = await api.get('/krs', { params: { semester_id: activeSemester.value?.id } })
    const list = data.data ?? data ?? []
    // Mahasiswa: backend sudah filter by student, ambil yang pertama untuk semester ini
    const found = list.length > 0 ? list[0] : null
    if (found?.id) {
      const { data: detail } = await api.get(`/krs/${found.id}`)
      krs.value = detail
    } else {
      krs.value = null
    }
  } catch { krs.value = null }
}

async function loadAvailableClasses() {
  if (!activeSemester.value) return
  try {
    const { data } = await api.get('/classes', { params: { semester_id: activeSemester.value.id, available_for_krs: 1, per_page: 100 } })
    availableClasses.value = data.data ?? data ?? []
  } catch { availableClasses.value = [] }
}

async function loadEligibility() {
  if (!activeSemester.value) return
  try {
    const { data } = await api.get('/krs/eligibility', { params: { semester_id: activeSemester.value.id } })
    eligibility.value = data
  } catch (e: any) {
    eligibility.value = null
    toast.error(e?.response?.data?.message ?? 'Gagal memeriksa periode KRS dan status keuangan.')
  }
}

// Grup classes by course
const courseOptions = computed(() => {
  const map = new Map<number, { course: any; classes: any[] }>()
  for (const cls of availableClasses.value) {
    const offered = cls.offered_courses?.length ? cls.offered_courses : [cls.course]
    for (const course of offered.filter(Boolean)) {
      if (Number(course.study_program_id ?? course.study_program?.id) !== Number(student.value?.study_program_id)) continue
      if (!map.has(course.id)) {
        map.set(course.id, { course, classes: [] })
      }
      map.get(course.id)!.classes.push(cls)
    }
  }
  return Array.from(map.values())
})

function classOptionDetails(cls: any): string {
  const schedule = cls.schedules?.find((item: any) => item.is_active !== false) ?? cls.schedules?.[0]
  const lecturer = schedule?.lecturer?.name ?? schedule?.lecturer?.full_name
    ?? cls.lecturer?.name ?? cls.lecturer?.full_name ?? '-'
  const scheduleText = schedule
    ? `${schedule.day} ${schedule.start_time?.slice(0, 5)}-${schedule.end_time?.slice(0, 5)}`
    : '-'
  const room = schedule?.room?.name ?? cls.room?.name ?? '-'
  return `${lecturer} · ${scheduleText} · ${room}`
}

// Classes for selected course
const classesForCourse = computed(() => {
  if (!selectedCourseId.value) return []
  return courseOptions.value.find((option) => option.course.id == selectedCourseId.value)?.classes ?? []
})

const isEditable = computed(() => {
  if (!krs.value) return false
  const status = krs.value.status ?? 'DRAFT'
  return (status === 'DRAFT' || status === 'REJECTED') && eligibility.value?.period?.is_open !== false
})

const canCreateKrs = computed(() => eligibility.value?.period?.is_open !== false)
const canSubmitKrs = computed(() => eligibility.value?.can_submit === true && !krs.value?.schedule_conflicts?.length)
const submitTargetLabel = computed(() => krs.value?.advisor_id
  ? 'Ajukan KRS ke Dosen Wali'
  : 'Ajukan KRS untuk Ditinjau Program Studi')

async function createKrs() {
  if (!activeSemester.value) return
  try {
    const { data } = await api.post('/krs', { semester_id: activeSemester.value.id })
    toast.success(data.message)
    // Load detail KRS yang baru dibuat
    if (data.data?.id) {
      const { data: detail } = await api.get(`/krs/${data.data.id}`)
      krs.value = detail
    }
    await loadEligibility()
  } catch (e: any) { toast.error(e?.response?.data?.message ?? 'Gagal membuat KRS.') }
}

function openAddCourse() {
  selectedCourseId.value = ''
  selectedClassId.value = ''
  addModal.value = true
}

async function addCourse() {
  if (!krs.value || !selectedCourseId.value) return
  addingCourse.value = true
  try {
    await api.post(`/krs/${krs.value.id}/courses`, { course_id: selectedCourseId.value, class_id: selectedClassId.value || null })
    toast.success('Mata kuliah ditambahkan.')
    addModal.value = false
    await loadKrs()
    await loadEligibility()
  } catch (e: any) { toast.error(e?.response?.data?.message ?? 'Gagal.') }
  finally { addingCourse.value = false }
}

async function removeCourse(detailId: number) {
  if (!confirm('Hapus mata kuliah ini dari KRS?')) return
  try {
    await api.delete(`/krs/${krs.value.id}/courses/${detailId}`)
    toast.success('Dihapus.')
    await loadKrs()
    await loadEligibility()
  } catch (e: any) { toast.error(e?.response?.data?.message ?? 'Gagal.') }
}

async function submitKrs() {
  if (!confirm('Ajukan KRS? Pastikan semua mata kuliah sudah benar.')) return
  try {
    const { data } = await api.post(`/krs/${krs.value.id}/submit`)
    toast.success(data.message ?? 'KRS berhasil diajukan.')
    await loadKrs()
    await loadEligibility()
  } catch (e: any) { toast.error(e?.response?.data?.message ?? 'Gagal submit.') }
}

async function downloadKrsPdf() {
  try {
    const res = await api.get(`/krs/${krs.value.id}/pdf`, { responseType: 'blob' })
    const url = URL.createObjectURL(new Blob([res.data], { type: 'application/pdf' }))
    const link = document.createElement('a')
    link.href = url
    link.download = `KRS-${student.value?.nim ?? ''}.pdf`
    document.body.appendChild(link); link.click(); link.remove()
    URL.revokeObjectURL(url)
  } catch { toast.error('Gagal mengunduh PDF.') }
}
</script>

<template>
  <div class="space-y-6">
    <div>
      <h1 class="text-xl font-bold text-gray-900">KRS Saya</h1>
      <p class="text-sm text-gray-500 mt-0.5">Kartu Rencana Studi — {{ activeSemester?.name ?? 'Memuat...' }}</p>
    </div>

    <div v-if="loading" class="flex items-center justify-center h-48"><p class="text-gray-400">Memuat...</p></div>

    <template v-else>
      <div v-if="eligibility" class="grid gap-3 md:grid-cols-2">
        <div :class="['rounded-xl border p-4', eligibility.period?.is_open ? 'border-blue-200 bg-blue-50' : 'border-red-200 bg-red-50']">
          <p class="text-sm font-semibold" :class="eligibility.period?.is_open ? 'text-blue-800' : 'text-red-800'">Periode KRS</p>
          <p class="mt-1 text-xs" :class="eligibility.period?.is_open ? 'text-blue-700' : 'text-red-700'">{{ eligibility.period?.message }}</p>
          <p v-if="eligibility.period?.start_date" class="mt-1 text-xs text-gray-500">{{ eligibility.period.start_date }} s.d. {{ eligibility.period.end_date }}</p>
        </div>
        <div :class="['rounded-xl border p-4', eligibility.finance?.is_clear ? 'border-green-200 bg-green-50' : 'border-amber-200 bg-amber-50']">
          <p class="text-sm font-semibold" :class="eligibility.finance?.is_clear ? 'text-green-800' : 'text-amber-800'">Status Keuangan</p>
          <p class="mt-1 text-xs" :class="eligibility.finance?.is_clear ? 'text-green-700' : 'text-amber-700'">{{ eligibility.finance?.message }}</p>
        </div>
      </div>

      <!-- Belum buat KRS -->
      <div v-if="!krs" class="bg-white rounded-xl border border-dashed border-gray-300 p-8 text-center">
        <p class="text-gray-500 mb-4">Anda belum membuat KRS untuk semester ini.</p>
        <button :disabled="!canCreateKrs" class="px-6 py-2.5 bg-blue-600 hover:bg-blue-700 disabled:bg-gray-300 disabled:cursor-not-allowed text-white text-sm font-medium rounded-lg" @click="createKrs">
          Mulai Buat KRS
        </button>
      </div>

      <!-- KRS ada -->
      <template v-else>
        <!-- Status Banner -->
        <div v-if="krs.display_status === 'DITANDATANGANI' || (krs.status === 'APPROVED' && krs.signed_by_kaprodi_at)" class="bg-green-50 border border-green-200 rounded-xl p-4 flex items-center gap-3">
          <CheckCircleIcon class="w-6 h-6 text-green-600 shrink-0" />
          <div class="flex-1">
            <p class="text-sm font-medium text-green-800">KRS Ditandatangani Kaprodi ✓</p>
            <p class="text-xs text-green-600">Persetujuan akhir telah diberikan. Total {{ krs.total_credits }} SKS.</p>
          </div>
          <button class="px-3 py-1.5 bg-red-50 hover:bg-red-100 text-red-700 border border-red-200 text-xs font-medium rounded-lg" @click="downloadKrsPdf">Unduh PDF</button>
        </div>
        <div v-else-if="krs.status === 'APPROVED' && krs.kaprodi_approved_at" class="bg-indigo-50 border border-indigo-200 rounded-xl p-4 flex items-center gap-3">
          <CheckCircleIcon class="w-6 h-6 text-indigo-600 shrink-0" />
          <div class="flex-1">
            <p class="text-sm font-medium text-indigo-800">KRS Disetujui Prodi ✓</p>
            <p class="text-xs text-indigo-600">Menunggu tanda tangan Kaprodi. Total {{ krs.total_credits }} SKS.</p>
          </div>
          <button class="px-3 py-1.5 bg-red-50 hover:bg-red-100 text-red-700 border border-red-200 text-xs font-medium rounded-lg" @click="downloadKrsPdf">Unduh PDF</button>
        </div>
        <div v-else-if="krs.status === 'APPROVED'" class="bg-amber-50 border border-amber-200 rounded-xl p-4 flex items-center gap-3">
          <CheckCircleIcon class="w-6 h-6 text-amber-600 shrink-0" />
          <div class="flex-1">
            <p class="text-sm font-medium text-amber-800">KRS Disetujui Dosen Wali ✓</p>
            <p class="text-xs text-amber-600">Menunggu persetujuan Kaprodi/Sekretaris Prodi. Total {{ krs.total_credits }} SKS.</p>
          </div>
          <button class="px-3 py-1.5 bg-red-50 hover:bg-red-100 text-red-700 border border-red-200 text-xs font-medium rounded-lg" @click="downloadKrsPdf">Unduh PDF</button>
        </div>
        <div v-else-if="krs.status === 'SUBMITTED'" class="bg-yellow-50 border border-yellow-200 rounded-xl p-4 flex items-center gap-3">
          <ExclamationTriangleIcon class="w-6 h-6 text-yellow-600 shrink-0" />
          <div>
            <p class="text-sm font-medium text-yellow-800">Menunggu Persetujuan Dosen Wali</p>
            <p class="text-xs text-yellow-600">KRS Anda sedang ditinjau. Tunggu pemberitahuan.</p>
          </div>
        </div>
        <div v-else-if="krs.status === 'REJECTED'" :class="['rounded-xl border p-4 flex items-center gap-3', krs.rejected_by_stage === 'KAPRODI' ? 'bg-rose-50 border-rose-200' : 'bg-red-50 border-red-200']">
          <ExclamationTriangleIcon :class="['w-6 h-6 shrink-0', krs.rejected_by_stage === 'KAPRODI' ? 'text-rose-600' : 'text-red-600']" />
          <div>
            <p :class="['text-sm font-medium', krs.rejected_by_stage === 'KAPRODI' ? 'text-rose-800' : 'text-red-800']">{{ krs.rejected_by_stage === 'KAPRODI' ? 'KRS Ditolak Prodi' : 'KRS Ditolak' }}</p>
            <p :class="['text-xs', krs.rejected_by_stage === 'KAPRODI' ? 'text-rose-600' : 'text-red-600']">{{ (krs.rejected_by_stage === 'KAPRODI' ? krs.kaprodi_note : krs.advisor_note) || 'Perbaiki KRS Anda dan submit ulang.' }}</p>
          </div>
        </div>

        <!-- Summary -->
        <div class="grid grid-cols-3 gap-4">
          <div class="bg-white rounded-xl border border-gray-200 p-4 text-center">
            <p class="text-2xl font-bold text-blue-700">{{ krs.total_credits }}</p>
            <p class="text-xs text-gray-500">Total SKS</p>
          </div>
          <div class="bg-white rounded-xl border border-gray-200 p-4 text-center">
            <p class="text-2xl font-bold text-gray-700">{{ krs.details?.length ?? 0 }}</p>
            <p class="text-xs text-gray-500">Mata Kuliah</p>
          </div>
          <div class="bg-white rounded-xl border border-gray-200 p-4 text-center">
            <span :class="['px-3 py-1 rounded-full text-sm font-medium', getKrsStatusColor(krs.display_status ?? krs.status)]">{{ getKrsStatusLabel(krs.display_status ?? krs.status) }}</span>
            <p class="text-xs text-gray-500 mt-1">Status</p>
          </div>
        </div>

        <!-- List MK -->
        <div class="bg-white rounded-xl border border-gray-200 p-5">
          <div class="flex items-center justify-between mb-4">
            <h2 class="text-sm font-semibold text-gray-800">Mata Kuliah yang Diambil</h2>
            <button v-if="isEditable" class="flex items-center gap-1 px-3 py-1.5 bg-blue-600 hover:bg-blue-700 text-white text-xs font-medium rounded-lg" @click="openAddCourse">
              <PlusIcon class="w-3.5 h-3.5" /> Tambah MK
            </button>
          </div>

          <div v-if="!krs.details?.length" class="text-center text-gray-400 py-6 text-sm">Belum ada mata kuliah. Klik "Tambah MK" untuk mulai.</div>

          <table v-else class="w-full text-sm">
            <thead><tr class="text-left text-xs text-gray-500 border-b"><th class="pb-2">Kode</th><th class="pb-2">Mata Kuliah</th><th class="pb-2 text-center">SKS</th><th class="pb-2">Kelas</th><th class="pb-2">Dosen</th><th class="pb-2">Jadwal</th><th class="pb-2">Ruangan</th><th v-if="isEditable" class="pb-2"></th></tr></thead>
            <tbody>
              <tr v-for="d in krs.details" :key="d.id" class="border-b border-gray-50">
                <td class="py-2.5 font-mono text-xs text-gray-600">{{ d.course?.code }}</td>
                <td class="py-2.5 font-medium text-gray-900">{{ d.course?.name }}</td>
                <td class="py-2.5 text-center">{{ d.course?.credits }}</td>
                <td class="py-2.5 text-gray-600">{{ d.class_?.name ?? '-' }}</td>
                <td class="py-2.5 text-gray-600 text-xs">{{ d.class_?.lecturer?.name ?? d.class_?.lecturer?.full_name ?? '-' }}</td>
                <td class="py-2.5 text-gray-600 text-xs">{{ d.class_?.schedules?.[0] ? d.class_.schedules[0].day + ' ' + d.class_.schedules[0].start_time?.slice(0,5) + '-' + d.class_.schedules[0].end_time?.slice(0,5) : '-' }}</td>
                <td class="py-2.5 text-gray-600 text-xs">{{ d.class_?.room?.name ?? d.class_?.schedules?.[0]?.room?.name ?? '-' }}</td>
                <td v-if="isEditable" class="py-2.5 text-right">
                  <button class="p-1 text-red-400 hover:text-red-600" @click="removeCourse(d.id)"><TrashIcon class="w-4 h-4" /></button>
                </td>
              </tr>
            </tbody>
          </table>
        </div>

        <div v-if="krs.schedule_conflicts?.length" class="rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-800">
          <p class="font-semibold">Jadwal KRS bentrok. Ubah kelas atau hapus salah satu mata kuliah sebelum submit.</p>
          <ul class="mt-2 space-y-1">
            <li v-for="(conflict, index) in krs.schedule_conflicts" :key="index">
              {{ conflict.day }}: {{ conflict.course_a }} ({{ conflict.class_a }}, {{ conflict.start_a }}–{{ conflict.end_a }})
              bentrok dengan {{ conflict.course_b }} ({{ conflict.class_b }}, {{ conflict.start_b }}–{{ conflict.end_b }}).
            </li>
          </ul>
        </div>

        <!-- Tombol pengajuan -->
        <div v-if="isEditable && krs.details?.length" class="flex justify-end">
          <button :disabled="!canSubmitKrs" class="flex items-center gap-2 px-5 py-2.5 bg-green-600 hover:bg-green-700 disabled:bg-gray-300 disabled:cursor-not-allowed text-white text-sm font-medium rounded-lg" @click="submitKrs">
            <PaperAirplaneIcon class="w-4 h-4" /> {{ submitTargetLabel }}
          </button>
        </div>
      </template>
    </template>
  </div>

  <!-- Modal Tambah MK -->
  <BaseModal :open="addModal" title="Tambah Mata Kuliah" size="lg" @close="addModal = false">
    <form class="space-y-4" @submit.prevent="addCourse">
      <div>
        <label class="block text-sm font-medium text-gray-700 mb-1">Mata Kuliah <span class="text-red-500">*</span></label>
        <select v-model="selectedCourseId" required class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm">
          <option value="">-- Pilih Mata Kuliah --</option>
          <option v-for="opt in courseOptions" :key="opt.course.id" :value="opt.course.id">
            {{ opt.course.code }} - {{ opt.course.name }} ({{ opt.course.credits }} SKS)
          </option>
        </select>
      </div>
      <div v-if="classesForCourse.length">
        <label class="block text-sm font-medium text-gray-700 mb-1">Pilih Kelas</label>
        <select v-model="selectedClassId" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm">
          <option value="">-- Otomatis (belum ditentukan) --</option>
          <option v-for="cls in classesForCourse" :key="cls.id" :value="cls.id">
            {{ cls.name }} — {{ classOptionDetails(cls) }} ({{ cls.members_count ?? 0 }}/{{ cls.capacity }})
          </option>
        </select>
      </div>
      <p v-if="!courseOptions.length" class="text-sm text-gray-400 text-center py-4">Tidak ada kelas yang tersedia untuk semester ini.</p>
    </form>
    <template #footer>
      <button class="px-4 py-2 text-sm text-gray-600 hover:bg-gray-100 rounded-lg" @click="addModal = false">Batal</button>
      <button :disabled="addingCourse || !selectedCourseId" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 disabled:bg-blue-400 text-white text-sm font-medium rounded-lg" @click="addCourse">
        {{ addingCourse ? 'Menambahkan...' : 'Tambah ke KRS' }}
      </button>
    </template>
  </BaseModal>
</template>
