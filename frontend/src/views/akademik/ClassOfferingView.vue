<script setup lang="ts">
import { ref, reactive, onMounted, computed } from 'vue'
import { useRouter } from 'vue-router'
import { PlusIcon, PencilIcon, TrashIcon, ExclamationTriangleIcon, ArrowRightOnRectangleIcon } from '@heroicons/vue/24/outline'
import { useAuthStore } from '@/stores/auth'
import { useCrud } from '@/composables/useCrud'
import { useExcel } from '@/composables/useExcel'
import { useToast } from 'vue-toastification'
import DataTable from '@/components/ui/DataTable.vue'
import BaseModal from '@/components/ui/BaseModal.vue'
import ExcelButtons from '@/components/ui/ExcelButtons.vue'
import SearchableSelect from '@/components/ui/SearchableSelect.vue'
import api from '@/services/api'

const auth = useAuthStore()
const canManage = computed(() => auth.hasRole('SUPER_ADMIN') || auth.hasRole('ADMIN_AKADEMIK') || auth.hasPermission('jadwal.create'))
const canUseExcel = computed(() =>
  auth.hasRole(['SUPER_ADMIN', 'ADMIN_AKADEMIK'])
  || (auth.user?.position_codes ?? []).some((code) => ['KAPRODI', 'SEKPRODI'].includes(code)),
)

interface CourseOption {
  id: number; code: string; name: string; credits: number; semester?: number; type?: string
  study_program?: { id: number; code: string; name: string }
  delivery_mode?: 'TATAP_MUKA' | 'NON_TATAP_MUKA'
  pivot?: { curriculum_id?: number; is_primary?: boolean; quota?: number | null }
}

interface CourseMappingForm {
  course_id: number
  quota: number | ''
}

interface ClassItem {
  id: number; study_program_id?: number; semester_id?: number; course_id?: number
  lecturer_id?: number; room_id?: number; name: string; capacity: number; academic_level: number
  day: string; start_time: string; end_time: string; is_active: boolean
  members_count: number
  course?: CourseOption
  offered_courses?: CourseOption[]
  lecturer?: { id: number; full_name?: string; name?: string }
  room?: { id: number; code: string; name: string }
  semester?: { id: number; name: string; academic_year?: { name: string } }
  schedules?: Array<{
    id: number; day: string; start_time: string; end_time: string
    room_id?: number | null; lecturer_id?: number | null
    room?: { id: number; code?: string; name: string }
    lecturer?: { id: number; full_name?: string; name?: string }
  }>
}

interface ScheduleItem {
  id: number
  day: string
  start_time: string
  end_time: string
  room_id?: number | null
  lecturer_id?: number | null
  room?: { id: number; code?: string; name: string }
  lecturer?: { id: number; full_name?: string; name?: string }
}

const toast = useToast()
const router = useRouter()
const { items, pagination, loading, fetchAll, create, update, remove } = useCrud<ClassItem>('/classes')
const { exporting, importing, importErrors, exportExcel, importExcel } = useExcel('/classes')

const semesters = ref<any[]>([])
const programs  = ref<any[]>([])
const courses   = ref<any[]>([])
const lecturers = ref<any[]>([])
const rooms     = ref<any[]>([])
const sharedCourseOptions = ref<CourseOption[]>([])

const filterSemester = ref('')
const filterProgram  = ref('')
const search         = ref('')

const modalOpen    = ref(false)
const scheduleModalOpen = ref(false)
const editingSchedule = ref<ScheduleItem | null>(null)
const editingScheduleClassId = ref<number | null>(null)
const savingSchedule = ref(false)
const editingId    = ref<number | null>(null)
const saving       = ref(false)
const conflictMsg  = ref<string[]>([])
const scheduleConflictMsg = ref<string[]>([])
const sharedMode = ref(false)
const sharedCourseSearch = ref('')
const primaryQuota = ref<number | ''>('')
const additionalCourseMappings = ref<CourseMappingForm[]>([])
const allowCreditMismatch = ref(false)

const form = reactive({
  study_program_id: '', semester_id: '', course_id: '', lecturer_id: '', room_id: '',
  name: '', capacity: 40, academic_level: 1,
  day: '', start_time: '', end_time: '', is_active: true,
})

const scheduleForm = reactive({
  day: '', start_time: '', end_time: '', lecturer_id: '', room_id: '',
})

const days = ['Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu', 'Minggu']

const filteredSharedCourseOptions = computed(() => {
  const query = sharedCourseSearch.value.trim().toLowerCase()
  return sharedCourseOptions.value.filter((course) => {
    if (course.id === Number(form.course_id)) return false
    if (!query) return true
    return [course.code, course.name, course.study_program?.code, course.study_program?.name]
      .some((value) => value?.toLowerCase().includes(query))
  })
})

const hasCreditMismatch = computed(() => {
  const primary = [...courses.value, ...sharedCourseOptions.value]
    .find((course: CourseOption) => course.id === Number(form.course_id))
  if (!primary) return false
  return additionalCourseMappings.value.some((mapping) => {
    const course = sharedCourseOptions.value.find((option) => option.id === mapping.course_id)
    return course && Number(course.credits) !== Number(primary.credits)
  })
})

const selectedCourse = computed(() => [...courses.value, ...sharedCourseOptions.value]
  .find((course: CourseOption) => course.id === Number(form.course_id)))
const isNonFaceToFace = computed(() => selectedCourse.value?.delivery_mode === 'NON_TATAP_MUKA')
const courseOptions = computed(() => courses.value.map((course: CourseOption) => ({
  value: course.id,
  label: `${course.code} - ${course.name} (${course.credits} SKS)`,
  search: `${course.code} ${course.name} ${course.study_program?.code ?? ''}`,
})))
const lecturerOptions = computed(() => lecturers.value.map((lecturer: any) => ({
  value: lecturer.id, label: lecturer.name, search: lecturer.nidn ?? '',
})))
const roomOptions = computed(() => rooms.value.map((room: any) => ({
  value: room.id, label: `${room.code} - ${room.name}`, search: `${room.code} ${room.name}`,
})))

const columns = [
  { key: 'name', label: 'Kelas' }, { key: 'course', label: 'Mata Kuliah' },
  { key: 'lecturer', label: 'Dosen' }, { key: 'schedule', label: 'Jadwal' },
  { key: 'room', label: 'Ruangan' }, { key: 'quota', label: 'Kapasitas' },
  { key: 'aksi', label: 'Aksi', class: 'text-right' },
]

onMounted(async () => {
  const [sRes, pRes, lRes, rRes] = await Promise.all([
    api.get('/academic-years/all'),  // Nanti ganti ke semesters
    api.get('/study-programs/all'),
    api.get('/lecturers/all'),
    api.get('/rooms/all'),
  ])
  // Load semesters dari semua tahun akademik (ambil dari endpoint semesters)
  try {
    const { data } = await api.get('/semesters', { params: { per_page: 50 } })
    semesters.value = data.data ?? data
  } catch {
    semesters.value = []
  }
  programs.value  = pRes.data
  lecturers.value = lRes.data
  rooms.value     = rRes.data

  // Default ke semester aktif
  const active = semesters.value.find((s: any) => s.is_active)
  if (active) filterSemester.value = active.id
  load()
})

async function load(page = 1) {
  await fetchAll({ semester_id: filterSemester.value, study_program_id: filterProgram.value, search: search.value, page })
}

async function loadCourses(resetSelection = true) {
  if (!form.study_program_id) { courses.value = []; return }
  if (resetSelection) form.course_id = ''
  const { data } = await api.get('/courses/all', {
    params: {
      study_program_id: form.study_program_id,
      active_curriculum_only: true,
    },
  })
  courses.value = data
}

function handleImport(file: File) {
  importExcel(file, () => load())
}

async function loadSharedCourseOptions() {
  if (sharedCourseOptions.value.length) return
  const { data } = await api.get('/courses/all', {
    params: { active_curriculum_only: true },
  })
  sharedCourseOptions.value = data
}

async function setSharedMode(enabled: boolean) {
  sharedMode.value = enabled
  if (enabled) {
    await loadSharedCourseOptions()
  } else {
    additionalCourseMappings.value = []
    allowCreditMismatch.value = false
  }
}

function handlePrimaryCourseChange() {
  additionalCourseMappings.value = additionalCourseMappings.value
    .filter((mapping) => mapping.course_id !== Number(form.course_id))
}

function isAdditionalSelected(courseId: number) {
  return additionalCourseMappings.value.some((mapping) => mapping.course_id === courseId)
}

function toggleAdditionalCourse(courseId: number, checked: boolean) {
  if (checked && !isAdditionalSelected(courseId)) {
    additionalCourseMappings.value.push({ course_id: courseId, quota: '' })
  } else if (!checked) {
    additionalCourseMappings.value = additionalCourseMappings.value
      .filter((mapping) => mapping.course_id !== courseId)
  }
}

function mappingForCourse(courseId: number) {
  return additionalCourseMappings.value.find((mapping) => mapping.course_id === courseId)
}

function offeredCoursePayload() {
  return [
    { course_id: Number(form.course_id), quota: primaryQuota.value || null },
    ...additionalCourseMappings.value.map((mapping) => ({
      course_id: mapping.course_id,
      quota: mapping.quota || null,
    })),
  ]
}

function openCreate() {
  editingId.value = null
  conflictMsg.value = []
  Object.assign(form, {
    study_program_id: filterProgram.value || '', semester_id: filterSemester.value || '',
    course_id: '', lecturer_id: '', room_id: '',
    name: '', capacity: 40, academic_level: 1,
    day: '', start_time: '', end_time: '', is_active: true,
  })
  sharedMode.value = false
  sharedCourseSearch.value = ''
  primaryQuota.value = ''
  additionalCourseMappings.value = []
  allowCreditMismatch.value = false
  if (form.study_program_id) loadCourses()
  modalOpen.value = true
}

async function openEdit(item: ClassItem) {
  editingId.value = item.id
  conflictMsg.value = []
  Object.assign(form, {
    study_program_id: item.study_program_id ?? item.course?.study_program?.id ?? '',
    semester_id: item.semester_id ?? item.semester?.id ?? '',
    course_id: item.course_id ?? item.course?.id ?? '',
    lecturer_id: item.lecturer_id ?? item.lecturer?.id ?? '',
    room_id: item.room_id ?? item.room?.id ?? '',
    name: item.name, capacity: Number(item.capacity), academic_level: Number(item.academic_level),
    day: item.day ?? '', start_time: item.start_time ?? '', end_time: item.end_time ?? '',
    is_active: item.is_active,
  })
  await loadCourses(false)
  const mappings = item.offered_courses?.length ? item.offered_courses : (item.course ? [item.course] : [])
  const primary = mappings.find((course) => course.id === item.course?.id)
  primaryQuota.value = primary?.pivot?.quota ?? ''
  additionalCourseMappings.value = mappings
    .filter((course) => course.id !== item.course?.id)
    .map((course) => ({ course_id: course.id, quota: course.pivot?.quota ?? '' }))
  sharedMode.value = additionalCourseMappings.value.length > 0
  sharedCourseSearch.value = ''
  allowCreditMismatch.value = false
  if (sharedMode.value) await loadSharedCourseOptions()
  modalOpen.value = true
}

async function handleSave() {
  saving.value = true
  conflictMsg.value = []
  try {
    const payload = {
      ...form,
      offered_courses: offeredCoursePayload(),
      allow_credit_mismatch: allowCreditMismatch.value,
    }
    if (isNonFaceToFace.value) {
      payload.lecturer_id = ''
      payload.room_id = ''
      payload.day = ''
      payload.start_time = ''
      payload.end_time = ''
    }
    editingId.value ? await update(editingId.value, payload) : await create(payload)
    modalOpen.value = false; load()
  } catch (err: any) {
    // Tampilkan konflik jadwal jika ada
    if (err?.response?.data?.conflicts) {
      conflictMsg.value = err.response.data.conflicts
    } else {
      toast.error(err?.response?.data?.message ?? 'Gagal menyimpan kelas.')
    }
  } finally { saving.value = false }
}

function openScheduleEdit(item: ScheduleItem, row: ClassItem) {
  editingSchedule.value = item
  editingScheduleClassId.value = row.id
  scheduleConflictMsg.value = []
  Object.assign(scheduleForm, {
    day: item.day,
    start_time: item.start_time.slice(0, 5),
    end_time: item.end_time.slice(0, 5),
    lecturer_id: String(item.lecturer_id ?? item.lecturer?.id ?? row.lecturer_id ?? ''),
    room_id: String(item.room_id ?? item.room?.id ?? row.room_id ?? ''),
  })
  scheduleModalOpen.value = true
}

async function handleScheduleSave() {
  if (!editingSchedule.value || editingScheduleClassId.value === null) return
  savingSchedule.value = true
  scheduleConflictMsg.value = []
  try {
    const payload = {
      ...scheduleForm,
      lecturer_id: scheduleForm.lecturer_id || null,
      room_id: scheduleForm.room_id || null,
    }
    await api.put(`/classes/${editingScheduleClassId.value}/schedules/${editingSchedule.value.id}`, payload)
    scheduleModalOpen.value = false
    toast.success('Jadwal diperbarui. Perubahan langsung terlihat pada KRS mahasiswa.')
    await load()
  } catch (err: any) {
    if (err?.response?.data?.conflicts) {
      scheduleConflictMsg.value = err.response.data.conflicts
    } else {
      toast.error(err?.response?.data?.message ?? 'Gagal memperbarui jadwal.')
    }
  } finally {
    savingSchedule.value = false
  }
}

async function handleDelete(item: ClassItem) {
  if (!confirm(`Hapus kelas "${item.name}" (${item.course?.name})?`)) return
  await remove(item.id); load()
}
</script>

<template>
  <div class="space-y-5">
    <div class="flex items-center justify-between">
      <div>
        <h1 class="text-xl font-bold text-gray-900">Pembagian Kelas & Jadwal</h1>
        <p class="text-sm text-gray-500 mt-0.5">Kelola kelas, jadwal, dan alokasi dosen per semester</p>
      </div>
      <div v-if="canManage" class="flex items-center gap-2">
        <ExcelButtons
          v-if="canUseExcel"
          :exporting="exporting"
          :importing="importing"
          :import-errors="importErrors"
          :export-params="{ semester_id: filterSemester, study_program_id: filterProgram, search }"
          template-type="class-offerings"
          @export="exportExcel"
          @import="handleImport"
        />
        <button class="flex items-center gap-2 px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium rounded-lg" @click="openCreate">
          <PlusIcon class="w-4 h-4" /> Tambah Kelas
        </button>
      </div>
    </div>

    <div class="flex flex-wrap gap-3">
      <select v-model="filterSemester" class="px-3.5 py-2 rounded-lg border border-gray-300 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500" @change="load()">
        <option value="">Semua Semester</option>
        <option v-for="s in semesters" :key="s.id" :value="s.id">{{ s.name }}</option>
      </select>
      <select v-model="filterProgram" class="px-3.5 py-2 rounded-lg border border-gray-300 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500" @change="load()">
        <option value="">Semua Prodi</option>
        <option v-for="p in programs" :key="p.id" :value="p.id">{{ p.code }} - {{ p.name }}</option>
      </select>
      <input v-model="search" type="text" placeholder="Cari kelas / MK..." class="px-3.5 py-2 rounded-lg border border-gray-300 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 w-52" @input="load()" />
    </div>

    <DataTable :columns="columns" :rows="items" :loading="loading" :total="pagination.total" :current-page="pagination.currentPage" :last-page="pagination.lastPage" @page-change="load">
      <template #default="{ row }">
        <td class="px-4 py-3 font-semibold text-blue-700 text-sm">{{ row.name }}</td>
        <td class="px-4 py-3">
          <p class="font-medium text-gray-900 text-sm">{{ row.course?.name }}</p>
          <p class="text-xs text-gray-500">{{ row.course?.code }} · {{ row.course?.credits }} SKS</p>
          <p v-if="(row.offered_courses?.length ?? 0) > 1" class="text-xs text-violet-600 mt-0.5">
            Kelas gabungan · {{ row.offered_courses?.length }} mata kuliah
          </p>
        </td>
        <td class="px-4 py-3 text-gray-700 text-sm">{{ row.course?.delivery_mode === 'NON_TATAP_MUKA' ? 'Tidak diperlukan' : (row.lecturer?.name ?? row.lecturer?.full_name ?? '-') }}</td>
        <td class="px-4 py-3 text-gray-600 text-xs">
          <template v-if="row.course?.delivery_mode === 'NON_TATAP_MUKA'">
            <span class="text-gray-400">Tidak diperlukan</span>
          </template>
          <template v-else-if="row.schedules?.length">
            <div v-for="s in row.schedules" :key="s.id" class="flex items-center gap-1 whitespace-nowrap">
              {{ s.day }}, {{ s.start_time }} – {{ s.end_time }}
              <span v-if="s.room" class="text-gray-400 ml-1">({{ s.room.name }})</span>
              <button
                v-if="canManage"
                type="button"
                class="rounded p-1 text-blue-600 hover:bg-blue-50"
                title="Edit jadwal"
                @click="openScheduleEdit(s, row)"
              ><PencilIcon class="h-3.5 w-3.5" /></button>
            </div>
          </template>
          <span v-else class="text-gray-400">Belum dijadwalkan</span>
        </td>
        <td class="px-4 py-3 text-gray-600 text-xs">{{ row.course?.delivery_mode === 'NON_TATAP_MUKA' ? 'Tidak diperlukan' : (row.room?.name ?? '-') }}</td>
        <td class="px-4 py-3">
          <span class="text-sm font-medium" :class="row.members_count >= row.capacity ? 'text-red-600' : 'text-green-600'">
            {{ row.members_count ?? 0 }}/{{ row.capacity }}
          </span>
        </td>
        <td class="px-4 py-3">
          <div class="flex items-center justify-end gap-1">
            <button class="p-1.5 rounded-lg text-green-600 hover:bg-green-50" title="Masuk Kelas" @click="router.push(`/perkuliahan/${row.id}`)"><ArrowRightOnRectangleIcon class="w-4 h-4" /></button>
            <button v-if="canManage" class="p-1.5 rounded-lg text-blue-600 hover:bg-blue-50" @click="openEdit(row)"><PencilIcon class="w-4 h-4" /></button>
            <button v-if="canManage" class="p-1.5 rounded-lg text-red-500 hover:bg-red-50" @click="handleDelete(row)"><TrashIcon class="w-4 h-4" /></button>
          </div>
        </td>
      </template>
    </DataTable>
  </div>

  <BaseModal :open="modalOpen" :title="editingId ? 'Edit Kelas' : 'Tambah Kelas'" size="xl" @close="modalOpen = false">
    <!-- Konflik jadwal warning -->
    <div v-if="conflictMsg.length" class="mb-4 p-3 bg-red-50 border border-red-200 rounded-lg">
      <div class="flex items-start gap-2">
        <ExclamationTriangleIcon class="w-5 h-5 text-red-500 shrink-0 mt-0.5" />
        <div>
          <p class="text-sm font-semibold text-red-700">Jadwal Bentrok!</p>
          <ul class="text-xs text-red-600 mt-1 space-y-0.5">
            <li v-for="(msg, i) in conflictMsg" :key="i">• {{ msg }}</li>
          </ul>
        </div>
      </div>
    </div>

    <form class="space-y-4" @submit.prevent="handleSave">
      <div class="grid grid-cols-2 gap-4">
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Semester <span class="text-red-500">*</span></label>
          <SearchableSelect v-model="form.semester_id" :disabled="editingId !== null" :options="semesters.map((s: any) => ({ value: s.id, label: s.name }))" search-placeholder="Cari semester..." />
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Program Studi <span class="text-red-500">*</span></label>
          <SearchableSelect v-model="form.study_program_id" :disabled="editingId !== null" :options="programs.map((p: any) => ({ value: p.id, label: `${p.code} - ${p.name}`, search: `${p.code} ${p.name}` }))" search-placeholder="Cari program studi..." @update:model-value="loadCourses()" />
        </div>
      </div>
      <div>
        <label class="block text-sm font-medium text-gray-700 mb-1">Mata Kuliah <span class="text-red-500">*</span></label>
        <SearchableSelect v-model="form.course_id" :disabled="editingId !== null" :options="courseOptions" search-placeholder="Cari kode atau nama mata kuliah..." @update:model-value="handlePrimaryCourseChange" />
        <p v-if="form.study_program_id && courses.length === 0" class="mt-1 text-xs text-amber-600">
          Belum ada mata kuliah pada kurikulum aktif program studi ini.
        </p>
      </div>
      <div class="rounded-lg border border-violet-200 bg-violet-50/50 p-3 space-y-3">
        <label class="flex items-start gap-3 cursor-pointer">
          <input :checked="sharedMode" type="checkbox" class="mt-0.5 rounded border-gray-300 text-violet-600 focus:ring-violet-500" @change="setSharedMode(($event.target as HTMLInputElement).checked)" />
          <span>
            <span class="block text-sm font-medium text-gray-800">Kelas gabungan/lintas program studi</span>
            <span class="block text-xs text-gray-500">Gunakan dosen, jadwal, ruangan, materi, dan presensi yang sama untuk beberapa mata kuliah.</span>
          </span>
        </label>

        <div v-if="sharedMode" class="space-y-3 border-t border-violet-200 pt-3">
          <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
            <div>
              <label class="block text-xs font-medium text-gray-600 mb-1">Kuota mata kuliah utama (opsional)</label>
              <input v-model="primaryQuota" type="number" min="1" :max="form.capacity" placeholder="Mengikuti kapasitas kelas" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm" />
            </div>
            <div>
              <label class="block text-xs font-medium text-gray-600 mb-1">Cari mata kuliah tambahan</label>
              <input v-model="sharedCourseSearch" type="search" placeholder="Kode, nama, atau prodi..." class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm" />
            </div>
          </div>

          <div class="max-h-56 overflow-y-auto rounded-lg border border-gray-200 bg-white divide-y divide-gray-100">
            <label v-for="course in filteredSharedCourseOptions" :key="course.id" class="flex items-center gap-3 px-3 py-2 hover:bg-gray-50">
              <input :checked="isAdditionalSelected(course.id)" type="checkbox" class="rounded border-gray-300 text-violet-600 focus:ring-violet-500" @change="toggleAdditionalCourse(course.id, ($event.target as HTMLInputElement).checked)" />
              <span class="min-w-0 flex-1">
                <span class="block text-sm font-medium text-gray-800 truncate">{{ course.code }} — {{ course.name }}</span>
                <span class="block text-xs text-gray-500">{{ course.study_program?.code }} · {{ course.credits }} SKS</span>
              </span>
              <input
                v-if="isAdditionalSelected(course.id)"
                :value="mappingForCourse(course.id)?.quota"
                type="number"
                min="1"
                :max="form.capacity"
                placeholder="Kuota"
                class="w-24 px-2 py-1.5 border border-gray-300 rounded text-xs"
                @input="mappingForCourse(course.id)!.quota = Number(($event.target as HTMLInputElement).value) || ''"
                @click.stop
              />
            </label>
            <p v-if="filteredSharedCourseOptions.length === 0" class="px-3 py-5 text-center text-xs text-gray-500">
              Tidak ada mata kuliah yang sesuai.
            </p>
          </div>

          <p class="text-xs text-gray-600">Mata kuliah tambahan terpilih: <strong>{{ additionalCourseMappings.length }}</strong></p>

          <label v-if="hasCreditMismatch" class="flex items-start gap-2 rounded-lg bg-amber-50 border border-amber-200 p-2.5">
            <input v-model="allowCreditMismatch" type="checkbox" class="mt-0.5 rounded border-gray-300 text-amber-600 focus:ring-amber-500" />
            <span class="text-xs text-amber-800">Saya memahami terdapat perbedaan SKS. KRS dan transkrip tetap memakai SKS mata kuliah masing-masing mahasiswa.</span>
          </label>
        </div>
      </div>
      <div class="grid grid-cols-3 gap-4">
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Nama Kelas <span class="text-red-500">*</span></label>
          <input v-model="form.name" required placeholder="Kelas A" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-blue-500" />
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Kapasitas</label>
          <input v-model="form.capacity" type="number" min="1" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-blue-500" />
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Tingkat</label>
          <input v-model="form.academic_level" type="number" min="1" max="8" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-blue-500" />
        </div>
      </div>
      <div v-if="!isNonFaceToFace">
        <label class="block text-sm font-medium text-gray-700 mb-1">Dosen Pengampu <span class="text-red-500">*</span></label>
        <SearchableSelect v-model="form.lecturer_id" :options="lecturerOptions" search-placeholder="Cari nama atau NIDN dosen..." />
      </div>
      <div v-else class="rounded-lg border border-amber-200 bg-amber-50 p-3 text-xs text-amber-800">
        Mata kuliah ini ditandai sebagai non-tatap muka. Dosen pengampu, jadwal jam pembelajaran, dan ruangan tidak diperlukan.
      </div>
      <div v-if="!isNonFaceToFace" class="grid grid-cols-3 gap-4">
        <div v-if="!isNonFaceToFace">
          <label class="block text-sm font-medium text-gray-700 mb-1">Hari</label>
          <select v-model="form.day" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
            <option value="">-- Pilih --</option>
            <option v-for="d in days" :key="d" :value="d">{{ d }}</option>
          </select>
        </div>
        <div v-if="!isNonFaceToFace">
          <label class="block text-sm font-medium text-gray-700 mb-1">Jam Mulai</label>
          <input v-model="form.start_time" type="time" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-blue-500" />
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Jam Selesai</label>
          <input v-model="form.end_time" type="time" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-blue-500" />
        </div>
      </div>
      <div v-if="!isNonFaceToFace">
        <label class="block text-sm font-medium text-gray-700 mb-1">Ruangan</label>
        <SearchableSelect v-model="form.room_id" :options="roomOptions" placeholder="-- Belum ditentukan --" search-placeholder="Cari kode atau nama ruangan..." />
      </div>
    </form>
    <template #footer>
      <button class="px-4 py-2 text-sm text-gray-600 hover:bg-gray-100 rounded-lg" @click="modalOpen = false">Batal</button>
      <button :disabled="saving" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 disabled:bg-blue-400 text-white text-sm font-medium rounded-lg" @click="handleSave">
        {{ saving ? 'Menyimpan...' : 'Simpan' }}
      </button>
    </template>
  </BaseModal>

  <BaseModal :open="scheduleModalOpen" title="Edit Jadwal Mata Kuliah" size="md" @close="scheduleModalOpen = false">
    <div v-if="scheduleConflictMsg.length" class="mb-4 rounded-lg border border-red-200 bg-red-50 p-3">
      <p class="text-sm font-semibold text-red-700">Jadwal bentrok!</p>
      <ul class="mt-1 space-y-0.5 text-xs text-red-600">
        <li v-for="(message, index) in scheduleConflictMsg" :key="index">{{ message }}</li>
      </ul>
    </div>
    <form class="space-y-4" @submit.prevent="handleScheduleSave">
      <div>
        <label class="mb-1 block text-sm font-medium text-gray-700">Hari</label>
        <select v-model="scheduleForm.day" required class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm">
          <option v-for="day in days" :key="day" :value="day">{{ day }}</option>
        </select>
      </div>
      <div class="grid grid-cols-2 gap-3">
        <div>
          <label class="mb-1 block text-sm font-medium text-gray-700">Jam Mulai</label>
          <input v-model="scheduleForm.start_time" required type="time" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm" />
        </div>
        <div>
          <label class="mb-1 block text-sm font-medium text-gray-700">Jam Selesai</label>
          <input v-model="scheduleForm.end_time" required type="time" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm" />
        </div>
      </div>
      <div>
        <label class="mb-1 block text-sm font-medium text-gray-700">Dosen</label>
        <SearchableSelect v-model="scheduleForm.lecturer_id" :options="lecturerOptions" placeholder="-- Tidak ditentukan --" search-placeholder="Cari nama atau NIDN dosen..." />
      </div>
      <div>
        <label class="mb-1 block text-sm font-medium text-gray-700">Ruangan</label>
        <SearchableSelect v-model="scheduleForm.room_id" :options="roomOptions" placeholder="-- Tidak ditentukan --" search-placeholder="Cari kode atau nama ruangan..." />
      </div>
      <p class="rounded-lg bg-blue-50 p-3 text-xs text-blue-800">
        Perubahan jadwal akan langsung tampil pada KRS mahasiswa yang mengambil kelas ini. Pastikan tidak menimbulkan bentrok jadwal.
      </p>
    </form>
    <template #footer>
      <button class="rounded-lg px-4 py-2 text-sm text-gray-600 hover:bg-gray-100" @click="scheduleModalOpen = false">Batal</button>
      <button :disabled="savingSchedule" class="rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700 disabled:bg-blue-400" @click="handleScheduleSave">
        {{ savingSchedule ? 'Menyimpan...' : 'Simpan Perubahan' }}
      </button>
    </template>
  </BaseModal>
</template>
