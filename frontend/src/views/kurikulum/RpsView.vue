<script setup lang="ts">
import { ref, reactive, onMounted, computed } from 'vue'
import { PlusIcon, EyeIcon, TrashIcon, PaperAirplaneIcon, DocumentDuplicateIcon } from '@heroicons/vue/24/outline'
import { useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { useCrud } from '@/composables/useCrud'
import { useToast } from 'vue-toastification'
import DataTable from '@/components/ui/DataTable.vue'
import BaseModal from '@/components/ui/BaseModal.vue'
import api from '@/services/api'

interface Rps {
  id: number; code: string; status: string
  course?: { id: number; name: string; code: string; credits: number }
  academic_year?: { id: number; name: string }
  lecturer?: { id: number; name: string }
}

const router = useRouter()
const auth = useAuthStore()
const toast = useToast()
const { items, pagination, loading, fetchAll, create, remove } = useCrud<Rps>('/rpkps')

// Data sumber
const courses = ref<any[]>([])
const curriculums = ref<any[]>([])
const academicYears = ref<any[]>([])
const lecturers = ref<any[]>([])
const filterStatus = ref('')

// Data khusus dosen (dari penugasan)
const myLecturerId = ref<number | null>(null)
const myCourses = ref<any[]>([])

const modalOpen = ref(false)
const reuseModalOpen = ref(false)
const reusableRpkps = ref<any[]>([])
const loadingReusable = ref(false)
const reuseForm = reactive({ source_id: '', academic_year_id: '', curriculum_id: '', lecturer_id: '' })
const saving = ref(false)
const form = reactive({
  course_id: '', curriculum_id: '', academic_year_id: '',
  semester_id: '', lecturer_id: '', course_description: '',
})

const isAdmin = computed(() => auth.hasRole('SUPER_ADMIN') || auth.hasRole('ADMIN_AKADEMIK'))
const canManage = computed(() => isAdmin.value || auth.hasRole('DOSEN') || auth.hasPermission('rps.create'))

const statusColor: Record<string, string> = {
  DRAFT: 'bg-gray-100 text-gray-600',
  DIAJUKAN: 'bg-yellow-100 text-yellow-700',
  DALAM_PEMERIKSAAN: 'bg-blue-100 text-blue-700',
  REVISI: 'bg-orange-100 text-orange-700',
  DISETUJUI: 'bg-green-100 text-green-700',
  DIKUNCI: 'bg-indigo-100 text-indigo-700',
  DIARSIPKAN: 'bg-gray-100 text-gray-500',
}

const columns = [
  { key: 'code', label: 'Kode' }, { key: 'course', label: 'Mata Kuliah' },
  { key: 'year', label: 'Tahun Akademik' }, { key: 'lecturer', label: 'Dosen' },
  { key: 'status', label: 'Status' }, { key: 'aksi', label: 'Aksi', class: 'text-right' },
]

onMounted(async () => {
  load()

  if (isAdmin.value) {
    // Admin: load semua data untuk dropdown
    const [cRes, curRes, aRes, lRes] = await Promise.all([
      api.get('/courses/all'),
      api.get('/curriculums', { params: { per_page: 100, status: 'Aktif' } }),
      api.get('/academic-years/all'),
      api.get('/lecturers/all'),
    ])
    courses.value = cRes.data
    curriculums.value = curRes.data?.data ?? curRes.data
    academicYears.value = aRes.data
    lecturers.value = lRes.data
  } else {
    // Dosen: load hanya MK yang ditugaskan + data pendukung
    const myRes = await api.get('/rpkps/my-courses')
    myCourses.value = myRes.data.courses ?? []
    myLecturerId.value = myRes.data.lecturer_id ?? null
    const studyProgramId = myCourses.value.find((course: any) => course.study_program_id)?.study_program_id
    const [curRes, aRes] = await Promise.all([
      api.get('/curriculums', {
        params: { per_page: 100, status: 'Aktif', ...(studyProgramId ? { study_program_id: studyProgramId } : {}) },
      }),
      api.get('/academic-years/all'),
    ])
    curriculums.value = curRes.data?.data ?? curRes.data ?? []
    academicYears.value = aRes.data ?? []
  }
})

async function load(page = 1) { await fetchAll({ status: filterStatus.value, page }) }

async function loadCurriculumsForCourse(courseId: string | number) {
  if (!courseId) { form.curriculum_id = ''; return }
  try {
    const studyProgramId = myCourses.value.find((course: any) => String(course.id) === String(courseId))?.study_program_id
    const { data } = await api.get('/curriculums', { params: { per_page: 100, status: 'Aktif', course_id: courseId, ...(studyProgramId ? { study_program_id: studyProgramId } : {}) } })
    curriculums.value = data?.data ?? data ?? []
    form.curriculum_id = curriculums.value.length === 1 ? curriculums.value[0].id : ''
  } catch {
    curriculums.value = []
    form.curriculum_id = ''
    toast.error('Gagal memuat kurikulum untuk mata kuliah tersebut.')
  }
}

function openCreate() {
  if (isAdmin.value) {
    Object.assign(form, { course_id: '', curriculum_id: '', academic_year_id: '', semester_id: '', lecturer_id: '', course_description: '' })
  } else {
    // Dosen: auto-fill lecturer_id dan tahun akademik aktif
    const activeYear = academicYears.value.find((a: any) => a.is_active) ?? academicYears.value[0]
    const activeCurriculum = curriculums.value.length === 1 ? curriculums.value[0] : null
    Object.assign(form, {
      course_id: '',
      curriculum_id: activeCurriculum?.id ?? '',
      academic_year_id: activeYear?.id ?? '',
      semester_id: '',
      lecturer_id: myLecturerId.value ?? '',
      course_description: '',
    })
  }
  modalOpen.value = true
}

async function handleSave() {
  saving.value = true
  try {
    await create(form)
    modalOpen.value = false; load()
  } catch { } finally { saving.value = false }
}

async function handleSubmit(item: Rps) {
  if (!confirm(`Submit RPKPS "${item.code}" untuk review?`)) return
  try {
    await api.post(`/rpkps/${item.id}/submit`)
    toast.success('RPKPS berhasil disubmit.')
    load()
  } catch (err: any) {
    toast.error(err?.response?.data?.message || 'Gagal submit.')
  }
}

async function handleDelete(item: Rps) {
  if (!confirm(`Hapus RPKPS "${item.code}" berstatus ${item.status.replace(/_/g, ' ')}? Dokumen dan seluruh isinya akan dihapus permanen.`)) return
  try {
    await remove(item.id)
    await load()
  } catch (err: any) {
    toast.error(err?.response?.data?.message ?? 'Gagal menghapus RPKPS.')
  }
}

async function openReuse() {
  loadingReusable.value = true
  reuseForm.source_id = ''
  const activeYear = academicYears.value.find((year: any) => year.is_active) ?? academicYears.value[0]
  Object.assign(reuseForm, {
    academic_year_id: activeYear?.id ?? '',
    curriculum_id: '',
    lecturer_id: isAdmin.value ? '' : (myLecturerId.value ?? ''),
  })
  reuseModalOpen.value = true
  try {
    const { data } = await api.get('/rpkps/reusable')
    reusableRpkps.value = data
  } catch (error: any) {
    toast.error(error?.response?.data?.message ?? 'Gagal memuat RPKPS lama.')
  } finally { loadingReusable.value = false }
}

async function reuseRpkps() {
  if (!reuseForm.source_id || !reuseForm.academic_year_id) {
    toast.warning('Pilih RPKPS lama dan tahun akademik tujuan.')
    return
  }
  saving.value = true
  try {
    const { data } = await api.post(`/rpkps/${reuseForm.source_id}/duplicate`, {
      academic_year_id: reuseForm.academic_year_id,
      curriculum_id: reuseForm.curriculum_id || null,
      lecturer_id: reuseForm.lecturer_id || null,
    })
    toast.success(data.message)
    reuseModalOpen.value = false
    router.push(`/rps/${data.data.id}`)
  } catch (error: any) {
    toast.error(error?.response?.data?.message ?? 'Gagal menggunakan RPKPS lama.')
  } finally { saving.value = false }
}
</script>

<template>
  <div class="space-y-5">
    <div class="flex items-center justify-between">
      <div>
        <h1 class="text-xl font-bold text-gray-900">RPKPS / RPS Digital</h1>
        <p class="text-sm text-gray-500 mt-0.5">Rencana Pembelajaran Semester</p>
      </div>
      <div class="flex items-center gap-2">
        <button v-if="canManage" class="flex items-center gap-2 px-4 py-2 bg-indigo-50 hover:bg-indigo-100 text-indigo-700 border border-indigo-200 text-sm font-medium rounded-lg transition-colors" @click="openReuse">
          <DocumentDuplicateIcon class="w-4 h-4" /> Gunakan RPKPS Lama
        </button>
        <button v-if="canManage" class="flex items-center gap-2 px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium rounded-lg transition-colors" @click="openCreate">
          <PlusIcon class="w-4 h-4" /> Buat RPS
        </button>
      </div>
    </div>

    <div class="flex gap-3">
      <select v-model="filterStatus" class="px-3.5 py-2 rounded-lg border border-gray-300 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500" @change="load()">
        <option value="">Semua Status</option>
        <option v-for="s in ['DRAFT','DIAJUKAN','DALAM_PEMERIKSAAN','REVISI','DISETUJUI','DIKUNCI']" :key="s" :value="s">{{ s.replace(/_/g, ' ') }}</option>
      </select>
    </div>

    <DataTable :columns="columns" :rows="items" :loading="loading" :total="pagination.total" :current-page="pagination.currentPage" :last-page="pagination.lastPage" @page-change="load">
      <template #default="{ row }">
        <td class="px-4 py-3 font-mono text-xs text-gray-600">{{ row.code }}</td>
        <td class="px-4 py-3">
          <p class="font-medium text-gray-900 text-sm">{{ row.course?.name }}</p>
          <p class="text-xs text-gray-500">{{ row.course?.code }} · {{ row.course?.credits }} SKS</p>
        </td>
        <td class="px-4 py-3 text-gray-600 text-sm">{{ row.academic_year?.name }}</td>
        <td class="px-4 py-3 text-gray-600 text-sm">{{ row.lecturer?.name }}</td>
        <td class="px-4 py-3">
          <span :class="['inline-flex px-2 py-0.5 rounded-full text-xs font-medium', statusColor[row.status] ?? 'bg-gray-100 text-gray-600']">{{ row.status.replace(/_/g, ' ') }}</span>
        </td>
        <td class="px-4 py-3">
          <div class="flex items-center justify-end gap-1">
            <button class="p-1.5 rounded-lg text-gray-500 hover:bg-gray-100" title="Detail" @click="router.push(`/rps/${row.id}`)">
              <EyeIcon class="w-4 h-4" />
            </button>
            <button v-if="canManage && (row.status === 'DRAFT' || row.status === 'REVISI')" class="p-1.5 rounded-lg text-blue-600 hover:bg-blue-50" title="Submit" @click="handleSubmit(row)">
              <PaperAirplaneIcon class="w-4 h-4" />
            </button>
            <button v-if="canManage && ['DRAFT', 'REVISI', 'DISETUJUI'].includes(row.status)" class="p-1.5 rounded-lg text-red-500 hover:bg-red-50" title="Hapus RPS yang belum dikunci" @click="handleDelete(row)">
              <TrashIcon class="w-4 h-4" />
            </button>
          </div>
        </td>
      </template>
    </DataTable>
  </div>

  <!-- Modal Buat RPKPS -->
  <BaseModal :open="modalOpen" title="Buat RPKPS Baru" size="lg" @close="modalOpen = false">
    <form class="space-y-4" @submit.prevent="handleSave">

      <!-- ========== FORM UNTUK DOSEN ========== -->
      <template v-if="!isAdmin">
        <!-- Info: MK yang ditugaskan -->
        <div v-if="myCourses.length === 0" class="bg-yellow-50 border border-yellow-200 rounded-lg p-4">
          <p class="text-sm text-yellow-800 font-medium">Anda belum ditugaskan mengampu mata kuliah apapun.</p>
          <p class="text-xs text-yellow-600 mt-1">Hubungi Ketua Program Studi atau Admin untuk penugasan mengajar.</p>
        </div>

        <div v-else>
          <!-- Mata Kuliah (hanya yang ditugaskan) -->
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Mata Kuliah yang Diampu <span class="text-red-500">*</span></label>
            <select v-model="form.course_id" required class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-blue-500" @change="loadCurriculumsForCourse(form.course_id)">
              <option value="">-- Pilih Mata Kuliah --</option>
              <option v-for="c in myCourses" :key="c.id" :value="c.id">{{ c.code }} - {{ c.name }} ({{ c.credits }} SKS)</option>
            </select>
            <p class="text-xs text-gray-400 mt-1">Hanya menampilkan MK yang telah ditugaskan kepada Anda</p>
          </div>

          <!-- Kurikulum (auto-select jika hanya 1) -->
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Kurikulum <span class="text-red-500">*</span></label>
            <select v-model="form.curriculum_id" required class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
              <option value="">-- Pilih Kurikulum --</option>
              <option v-for="c in curriculums" :key="c.id" :value="c.id">{{ c.code ?? '' }} {{ c.name }} ({{ c.year }})</option>
            </select>
          </div>

          <!-- Tahun Akademik (auto-select aktif) -->
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Tahun Akademik</label>
            <select v-model="form.academic_year_id" required class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm bg-gray-50">
              <option v-for="a in academicYears" :key="a.id" :value="a.id">{{ a.name }}</option>
            </select>
          </div>

          <!-- Dosen (readonly, otomatis terisi) -->
          <div class="bg-gray-50 rounded-lg p-3 border border-gray-200">
            <p class="text-xs text-gray-500">Dosen Pengampu</p>
            <p class="text-sm font-medium text-gray-800">{{ auth.user?.name }}</p>
            <input type="hidden" v-model="form.lecturer_id" />
          </div>

          <!-- Deskripsi -->
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Deskripsi Mata Kuliah</label>
            <textarea v-model="form.course_description" rows="3" placeholder="Deskripsi singkat mata kuliah..." class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-blue-500" />
          </div>
        </div>
      </template>

      <!-- ========== FORM UNTUK ADMIN/KAPRODI ========== -->
      <template v-else>
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Mata Kuliah <span class="text-red-500">*</span></label>
          <select v-model="form.course_id" required class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
            <option value="">-- Pilih Mata Kuliah --</option>
            <option v-for="c in courses" :key="c.id" :value="c.id">{{ c.code }} - {{ c.name }} ({{ c.credits }} SKS)</option>
          </select>
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Kurikulum <span class="text-red-500">*</span></label>
          <select v-model="form.curriculum_id" required class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
            <option value="">-- Pilih Kurikulum --</option>
            <option v-for="c in curriculums" :key="c.id" :value="c.id">{{ c.code ?? '' }} {{ c.name }} ({{ c.year }})</option>
          </select>
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Tahun Akademik <span class="text-red-500">*</span></label>
          <select v-model="form.academic_year_id" required class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
            <option value="">-- Pilih Tahun Akademik --</option>
            <option v-for="a in academicYears" :key="a.id" :value="a.id">{{ a.name }}</option>
          </select>
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Dosen Pengampu <span class="text-red-500">*</span></label>
          <select v-model="form.lecturer_id" required class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
            <option value="">-- Pilih Dosen --</option>
            <option v-for="l in lecturers" :key="l.id" :value="l.id">{{ l.name }}</option>
          </select>
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Deskripsi Mata Kuliah</label>
          <textarea v-model="form.course_description" rows="3" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-blue-500" />
        </div>
      </template>
    </form>
    <template #footer>
      <button class="px-4 py-2 text-sm text-gray-600 hover:bg-gray-100 rounded-lg" @click="modalOpen = false">Batal</button>
      <button :disabled="saving || (!isAdmin && myCourses.length === 0)" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 disabled:bg-blue-400 text-white text-sm font-medium rounded-lg" @click="handleSave">
        {{ saving ? 'Membuat...' : 'Buat RPKPS' }}
      </button>
    </template>
  </BaseModal>

  <BaseModal :open="reuseModalOpen" title="Gunakan RPKPS Lama" size="lg" @close="reuseModalOpen = false">
    <div class="space-y-4">
      <p class="text-sm text-gray-600">Dokumen lama akan disalin sebagai draf baru. Dokumen sumber tetap utuh dan salinannya dapat direvisi.</p>
      <div>
        <label class="block text-sm font-medium text-gray-700 mb-1">RPKPS sumber <span class="text-red-500">*</span></label>
        <select v-model="reuseForm.source_id" :disabled="loadingReusable" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm">
          <option value="">{{ loadingReusable ? 'Memuat...' : '-- Pilih RPKPS lama --' }}</option>
          <option v-for="source in reusableRpkps" :key="source.id" :value="source.id">
            {{ source.course?.code }} - {{ source.course?.name }} · {{ source.academic_year?.name }} · {{ source.lecturer?.name ?? source.lecturer?.full_name }}
          </option>
        </select>
        <p v-if="!loadingReusable && reusableRpkps.length === 0" class="text-xs text-amber-600 mt-1">Belum ada RPKPS lama yang disetujui untuk mata kuliah yang Anda ampu.</p>
      </div>
      <div>
        <label class="block text-sm font-medium text-gray-700 mb-1">Tahun akademik tujuan <span class="text-red-500">*</span></label>
        <select v-model="reuseForm.academic_year_id" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm">
          <option value="">-- Pilih tahun akademik --</option>
          <option v-for="year in academicYears" :key="year.id" :value="year.id">{{ year.name }}</option>
        </select>
      </div>
      <div>
        <label class="block text-sm font-medium text-gray-700 mb-1">Kurikulum tujuan (opsional)</label>
        <select v-model="reuseForm.curriculum_id" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm">
          <option value="">Gunakan kurikulum dari dokumen lama</option>
          <option v-for="curriculum in curriculums" :key="curriculum.id" :value="curriculum.id">{{ curriculum.name }} ({{ curriculum.year }})</option>
        </select>
      </div>
      <div v-if="isAdmin">
        <label class="block text-sm font-medium text-gray-700 mb-1">Dosen penyusun baru (opsional)</label>
        <select v-model="reuseForm.lecturer_id" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm">
          <option value="">Gunakan dosen dari dokumen lama</option>
          <option v-for="lecturer in lecturers" :key="lecturer.id" :value="lecturer.id">{{ lecturer.name }}</option>
        </select>
      </div>
    </div>
    <template #footer>
      <button class="px-4 py-2 text-sm text-gray-600 hover:bg-gray-100 rounded-lg" @click="reuseModalOpen = false">Batal</button>
      <button :disabled="saving || !reuseForm.source_id || !reuseForm.academic_year_id" class="px-4 py-2 bg-indigo-600 hover:bg-indigo-700 disabled:bg-indigo-400 text-white text-sm font-medium rounded-lg" @click="reuseRpkps">
        {{ saving ? 'Menyalin...' : 'Gunakan sebagai Draf Baru' }}
      </button>
    </template>
  </BaseModal>
</template>
