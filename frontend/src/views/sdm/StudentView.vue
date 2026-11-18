<script setup lang="ts">
import { ref, reactive, onMounted, computed } from 'vue'
import { useRouter } from 'vue-router'
import { PlusIcon, PencilIcon, TrashIcon, EyeIcon } from '@heroicons/vue/24/outline'
import { useCrud } from '@/composables/useCrud'
import { useExcel } from '@/composables/useExcel'
import { useAuthStore } from '@/stores/auth'
import { useToast } from 'vue-toastification'
import DataTable from '@/components/ui/DataTable.vue'
import BaseModal from '@/components/ui/BaseModal.vue'
import ExcelButtons from '@/components/ui/ExcelButtons.vue'
import api from '@/services/api'
import { toDateInput } from '@/utils/formValue'

const router = useRouter()
const auth = useAuthStore()
const toast = useToast()
const canManage = auth.hasEffectiveAccess(['SUPER_ADMIN', 'ADMIN_UMUM'])
const canCreate = canManage
const canViewStudentRecords = auth.hasEffectiveAccess(['SUPER_ADMIN', 'ADMIN_AKADEMIK', 'DOSEN', 'KAPRODI', 'SEKPRODI'])
const canEditAdvisor = auth.hasEffectiveAccess(['SUPER_ADMIN', 'ADMIN_AKADEMIK', 'ADMIN_UMUM', 'KAPRODI', 'SEKPRODI'])
const canManageBiodata = auth.hasEffectiveAccess(['SUPER_ADMIN', 'ADMIN_AKADEMIK', 'ADMIN_UMUM', 'KAPRODI', 'SEKPRODI'])

// Bulk selection
const selectedIds = ref<number[]>([])
const bulkDeleting = ref(false)

const isAllSelected = computed(() => items.value.length > 0 && items.value.every((i: any) => selectedIds.value.includes(i.id)))

function toggleSelectAll() {
  if (isAllSelected.value) { selectedIds.value = [] }
  else { selectedIds.value = items.value.map((i: any) => i.id) }
}

function toggleSelect(id: number) {
  const idx = selectedIds.value.indexOf(id)
  if (idx >= 0) selectedIds.value.splice(idx, 1)
  else selectedIds.value.push(id)
}

async function bulkDelete() {
  if (!selectedIds.value.length) return
  if (!confirm(`Hapus ${selectedIds.value.length} mahasiswa yang dipilih?`)) return
  bulkDeleting.value = true
  try {
    const { data } = await api.post('/students/bulk-delete', { ids: selectedIds.value })
    toast.success(data.message); selectedIds.value = []; load()
  } catch (e: any) { toast.error(e?.response?.data?.message ?? 'Gagal menghapus.') }
  finally { bulkDeleting.value = false }
}

interface StudyProgram { id: number; name: string; code: string }
interface AcademicYear { id: number; name: string }
interface Lecturer { id: number; name: string }
interface Student {
  id: number; nim: string; name: string; gender: string; status: string
  study_program_id?: number; academic_year_id?: number; advisor_id?: number
  current_semester: number; entry_year: number; email: string; phone: string
  class_type?: string
  birth_place?: string; birth_date?: string; address?: string; origin_school?: string
  domicile_address?: { address?: string | null }
  education_histories?: Array<{ institution_name: string }>
  study_program?: StudyProgram; advisor?: Lecturer; academic_year?: AcademicYear
}

const { items, pagination, loading, fetchAll, create, update, remove } = useCrud<Student>('/students')
const { exporting, importing, importErrors, exportExcel, importExcel } = useExcel('/students')
const programs = ref<StudyProgram[]>([])
const academicYears = ref<AcademicYear[]>([])
const lecturers = ref<Lecturer[]>([])
const search = ref(''); const filterProgram = ref(''); const filterStatus = ref('')
const filterSemester = ref(''); const filterEntryYear = ref('')
const modalOpen = ref(false); const editingId = ref<number | null>(null); const saving = ref(false)
const advisorModalOpen = ref(false)
const advisorStudent = ref<Student | null>(null)
const selectedAdvisorId = ref<number | ''>('')
const savingAdvisor = ref(false)
const biodataModalOpen = ref(false)
const biodataLoading = ref(false)
const savingBiodata = ref(false)
const biodataStudent = ref<Student | null>(null)
const biodataParentKeys = ['father', 'mother', 'guardian'] as const
const biodataParentLabels = { father: 'Ayah', mother: 'Ibu', guardian: 'Wali' }
const biodataForm = reactive({
  student: { name: '', gender: '', birth_place: '', birth_date: '', email: '', phone: '', home_phone: '' },
  profile: { religion: '', nik: '', nisn: '', blood_type: '', nationality: 'Indonesia' },
  address: { address: '', village: '', district: '', city: '', province: '', postal_code: '', rt: '', rw: '', hamlet: '' },
  parents: {
    father: { name: '', nik: '', birth_date: '', occupation: '', phone: '', email: '', education: '', income: '', is_alive: true },
    mother: { name: '', nik: '', birth_date: '', occupation: '', phone: '', email: '', education: '', income: '', is_alive: true },
    guardian: { name: '', nik: '', birth_date: '', occupation: '', phone: '', email: '', education: '', income: '', is_alive: true },
  },
  education: { institution_name: '', institution_address: '', major: '', graduation_year: '', diploma_number: '' },
  documents: { diploma_link: '', family_card_link: '', identity_link: '' },
})

const form = reactive({
  study_program_id: '', academic_year_id: '', advisor_id: '', nim: '', name: '',
  gender: 'L', birth_place: '', birth_date: '', email: '', phone: '',
  address: '', origin_school: '', entry_year: new Date().getFullYear(), status: 'Aktif', class_type: 'REGULER',
})

const studentStatuses = ['Aktif', 'Nonaktif', 'Cuti', 'Lulus', 'DO', 'Mengundurkan Diri']

const statusColor: Record<string, string> = {
  Aktif: 'bg-green-100 text-green-700', Cuti: 'bg-yellow-100 text-yellow-700',
  Lulus: 'bg-blue-100 text-blue-700', DO: 'bg-red-100 text-red-700',
  Nonaktif: 'bg-slate-100 text-slate-700', 'Mengundurkan Diri': 'bg-gray-100 text-gray-600',
}

const columns = [
  { key: 'select', label: '', class: 'w-8' },
  { key: 'nim', label: 'NIM' }, { key: 'name', label: 'Nama' }, { key: 'program', label: 'Prodi' },
  { key: 'semester', label: 'Smt' }, { key: 'entry_year', label: 'Angkatan' },
  { key: 'status', label: 'Status' }, { key: 'aksi', label: 'Aksi', class: 'text-right' },
]

onMounted(async () => {
  load()
  const [pRes, aRes, lRes] = await Promise.all([
    api.get('/study-programs/all'),
    api.get('/academic-years/all'),
    api.get('/lecturers/all'),
  ])
  programs.value = pRes.data
  academicYears.value = aRes.data
  lecturers.value = lRes.data
})

async function load(page = 1) {
  await fetchAll({ search: search.value, study_program_id: filterProgram.value, status: filterStatus.value, current_semester: filterSemester.value, entry_year: filterEntryYear.value, page })
}

function applyFilters() {
  selectedIds.value = []
  load()
}

function openCreate() {
  editingId.value = null
  Object.assign(form, { study_program_id: '', academic_year_id: '', advisor_id: '', nim: '', name: '', gender: 'L', birth_place: '', birth_date: '', email: '', phone: '', address: '', origin_school: '', entry_year: new Date().getFullYear(), status: 'Aktif', class_type: 'REGULER' })
  modalOpen.value = true
}

function openEdit(item: Student) {
  editingId.value = item.id
  Object.assign(form, {
    study_program_id: item.study_program_id ?? item.study_program?.id ?? '',
    academic_year_id: item.academic_year_id ?? item.academic_year?.id ?? '',
    advisor_id: item.advisor_id ?? item.advisor?.id ?? '',
    nim: item.nim, name: item.name, gender: item.gender ?? 'L',
    birth_place: item.birth_place ?? '', birth_date: toDateInput(item.birth_date),
    email: item.email ?? '', phone: item.phone ?? '',
    address: item.address ?? item.domicile_address?.address ?? '',
    origin_school: item.origin_school ?? item.education_histories?.[0]?.institution_name ?? '',
    entry_year: Number(item.entry_year), status: item.status, class_type: item.class_type ?? 'REGULER',
  })
  modalOpen.value = true
}

async function handleSave() {
  saving.value = true
  try {
    editingId.value ? await update(editingId.value, form) : await create(form)
    modalOpen.value = false; load()
  } catch { } finally { saving.value = false }
}

function openAdvisorEdit(item: Student) {
  advisorStudent.value = item
  selectedAdvisorId.value = item.advisor_id ?? item.advisor?.id ?? ''
  advisorModalOpen.value = true
}

async function saveAdvisor() {
  if (!advisorStudent.value) return

  savingAdvisor.value = true
  try {
    const { data } = await api.patch(`/students/${advisorStudent.value.id}/advisor`, {
      advisor_id: selectedAdvisorId.value === '' ? null : selectedAdvisorId.value,
    })
    toast.success(data.message ?? 'Pembimbing akademik berhasil diperbarui.')
    advisorModalOpen.value = false
    await load(pagination.value.currentPage)
  } catch (e: any) {
    toast.error(e?.response?.data?.message ?? 'Gagal memperbarui pembimbing akademik.')
  } finally {
    savingAdvisor.value = false
  }
}

async function openBiodata(item: Student) {
  biodataStudent.value = item
  biodataModalOpen.value = true
  biodataLoading.value = true
  try {
    const { data } = await api.get(`/students/${item.id}/biodata`)
    Object.assign(biodataForm.student, data.student ?? {})
    biodataForm.student.birth_date = toDateInput(data.student?.birth_date)
    Object.assign(biodataForm.profile, data.profile ?? {})
    biodataForm.profile.nationality = data.profile?.nationality || 'Indonesia'
    Object.assign(biodataForm.address, data.address ?? {})
    Object.assign(biodataForm.education, data.education ?? {})
    Object.assign(biodataForm.documents, data.documents ?? {})
    for (const key of biodataParentKeys) {
      Object.assign(biodataForm.parents[key], data.parents?.[key] ?? {
        name: '', nik: '', birth_date: '', occupation: '', phone: '', email: '', education: '', income: '', is_alive: true,
      })
      biodataForm.parents[key].birth_date = toDateInput(data.parents?.[key]?.birth_date)
    }
  } catch (e: any) {
    biodataModalOpen.value = false
    toast.error(e?.response?.data?.message ?? 'Gagal memuat biodata mahasiswa.')
  } finally {
    biodataLoading.value = false
  }
}

async function saveBiodata() {
  if (!biodataStudent.value || savingBiodata.value) return
  savingBiodata.value = true
  try {
    const { data } = await api.put(`/students/${biodataStudent.value.id}/biodata`, biodataForm)
    toast.success(data.message ?? 'Biodata mahasiswa berhasil diperbarui.')
    biodataModalOpen.value = false
    await load(pagination.value.currentPage)
  } catch (e: any) {
    const firstError = e?.response?.data?.errors
      ? Object.values(e.response.data.errors).flat()[0]
      : undefined
    toast.error(firstError ?? e?.response?.data?.message ?? 'Gagal menyimpan biodata mahasiswa.')
  } finally {
    savingBiodata.value = false
  }
}

async function handleDelete(item: Student) {
  if (!confirm(`Hapus mahasiswa "${item.name}" (${item.nim})?`)) return
  await remove(item.id); load()
}
</script>

<template>
  <div class="space-y-5">
    <div class="flex items-center justify-between">
      <div>
        <h1 class="text-xl font-bold text-gray-900">Data Mahasiswa</h1>
        <p class="text-sm text-gray-500 mt-0.5">Kelola data mahasiswa</p>
      </div>
      <div class="flex items-center gap-2">
        <ExcelButtons
          :exporting="exporting"
          :importing="canCreate ? importing : false"
          :import-errors="importErrors"
          :export-params="{ study_program_id: filterProgram, status: filterStatus, current_semester: filterSemester, entry_year: filterEntryYear }"
          template-type="students"
          :hide-import="!canCreate"
          @export="exportExcel($event, 'mahasiswa.xlsx')"
          @import="importExcel($event, () => load())"
        />
        <button v-if="canCreate" class="flex items-center gap-2 px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium rounded-lg transition-colors" @click="openCreate">
          <PlusIcon class="w-4 h-4" /> Tambah Mahasiswa
        </button>
      </div>
    </div>
    <div class="flex flex-wrap items-center gap-3">
      <input v-model="search" type="text" placeholder="Cari NIM atau nama..." class="px-3.5 py-2 rounded-lg border border-gray-300 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 w-56" @input="applyFilters" />
      <select v-model="filterProgram" class="px-3.5 py-2 rounded-lg border border-gray-300 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500" @change="applyFilters">
        <option value="">Semua Prodi</option>
        <option v-for="p in programs" :key="p.id" :value="p.id">{{ p.code }}</option>
      </select>
      <select v-model="filterStatus" class="px-3.5 py-2 rounded-lg border border-gray-300 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500" @change="applyFilters">
        <option value="">Semua Status</option>
        <option v-for="s in studentStatuses" :key="s" :value="s">{{ s }}</option>
      </select>
      <label class="flex items-center gap-2 text-sm text-gray-600">
        Semester
        <input v-model="filterSemester" type="number" min="1" placeholder="Semua" class="w-24 px-3.5 py-2 rounded-lg border border-gray-300 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500" @change="applyFilters" />
      </label>
      <label class="flex items-center gap-2 text-sm text-gray-600">
        Angkatan
        <input v-model="filterEntryYear" type="number" min="1" placeholder="Semua" class="w-28 px-3.5 py-2 rounded-lg border border-gray-300 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500" @change="applyFilters" />
      </label>
      <!-- Bulk delete -->
      <button v-if="canManage && selectedIds.length" :disabled="bulkDeleting" class="ml-auto px-3 py-2 bg-red-600 hover:bg-red-700 disabled:bg-red-400 text-white text-xs font-medium rounded-lg inline-flex items-center gap-1.5" @click="bulkDelete">
        <TrashIcon class="w-3.5 h-3.5" /> Hapus {{ selectedIds.length }} dipilih
      </button>
    </div>

    <DataTable :columns="columns" :rows="items" :loading="loading" :total="pagination.total" :current-page="pagination.currentPage" :last-page="pagination.lastPage" @page-change="load">
      <template #header-select>
        <input type="checkbox" :checked="isAllSelected" @change="toggleSelectAll" class="rounded border-gray-300" />
      </template>
      <template #default="{ row }">
        <td class="px-4 py-3"><input type="checkbox" :checked="selectedIds.includes(row.id)" @change="toggleSelect(row.id)" class="rounded border-gray-300" /></td>
        <td class="px-4 py-3 font-mono text-xs font-medium text-gray-700">{{ row.nim }}</td>
        <td class="px-4 py-3 font-medium text-gray-900">{{ row.name }}</td>
        <td class="px-4 py-3 text-gray-600 text-xs">{{ row.study_program?.code ?? '-' }}</td>
        <td class="px-4 py-3 text-center text-gray-600">{{ row.current_semester }}</td>
        <td class="px-4 py-3 text-center text-gray-600">{{ row.entry_year }}</td>
        <td class="px-4 py-3"><span :class="['inline-flex px-2 py-0.5 rounded-full text-xs font-medium', statusColor[row.status] ?? 'bg-gray-100 text-gray-500']">{{ row.status }}</span></td>
        <td class="px-4 py-3">
          <div class="flex items-center justify-end gap-1">
            <button class="p-1.5 rounded-lg text-gray-500 hover:bg-gray-100" @click="router.push(`/sdm/students/${row.id}`)"><EyeIcon class="w-4 h-4" /></button>
            <button v-if="canViewStudentRecords" class="p-1.5 rounded-lg text-indigo-600 hover:bg-indigo-50" title="Riwayat akademik" @click="router.push(`/penilaian/khs?student_id=${row.id}`)"><span class="text-[11px] font-semibold">KHS</span></button>
            <button v-if="canViewStudentRecords" class="p-1.5 rounded-lg text-emerald-600 hover:bg-emerald-50" title="Tagihan keuangan" @click="router.push(`/keuangan/mahasiswa/${row.id}`)"><span class="text-[11px] font-semibold">Rp</span></button>
            <button v-if="canManageBiodata" class="p-1.5 rounded-lg text-amber-700 hover:bg-amber-50" :title="`Biodata lengkap ${row.name}`" :aria-label="`Biodata lengkap ${row.name}`" @click="openBiodata(row)"><span class="text-[11px] font-semibold">BO</span></button>
            <button v-if="canEditAdvisor" class="p-1.5 rounded-lg text-violet-600 hover:bg-violet-50" title="Edit Pembimbing Akademik" :aria-label="`Edit Pembimbing Akademik ${row.name}`" @click="openAdvisorEdit(row)"><PencilIcon class="w-4 h-4" /></button>
            <button v-if="canManage" class="p-1.5 rounded-lg text-blue-600 hover:bg-blue-50" @click="openEdit(row)"><PencilIcon class="w-4 h-4" /></button>
            <button v-if="canManage" class="p-1.5 rounded-lg text-red-500 hover:bg-red-50" @click="handleDelete(row)"><TrashIcon class="w-4 h-4" /></button>
          </div>
        </td>
      </template>
    </DataTable>
  </div>

  <BaseModal :open="modalOpen" :title="editingId ? 'Edit Mahasiswa' : 'Tambah Mahasiswa'" size="xl" @close="modalOpen = false">
    <form class="space-y-4" @submit.prevent="handleSave">
      <div class="grid grid-cols-2 gap-4">
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Program Studi <span class="text-red-500">*</span></label>
          <select v-model="form.study_program_id" required class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
            <option value="">-- Pilih --</option>
            <option v-for="p in programs" :key="p.id" :value="p.id">{{ p.code }} - {{ p.name }}</option>
          </select>
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Tahun Akademik Masuk</label>
          <select v-model="form.academic_year_id" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
            <option value="">-- Pilih --</option>
            <option v-for="a in academicYears" :key="a.id" :value="a.id">{{ a.name }}</option>
          </select>
        </div>
      </div>
      <div class="grid grid-cols-2 gap-4">
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">NIM <span class="text-red-500">*</span></label>
          <input v-model="form.nim" :disabled="!!editingId" required class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 disabled:bg-gray-50" />
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Tahun Masuk</label>
          <input v-model="form.entry_year" type="number" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-blue-500" />
        </div>
      </div>
      <div>
        <label class="block text-sm font-medium text-gray-700 mb-1">Nama Lengkap <span class="text-red-500">*</span></label>
        <input v-model="form.name" required class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-blue-500" />
      </div>
      <div class="grid grid-cols-2 gap-4">
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Jenis Kelamin</label>
          <select v-model="form.gender" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
            <option value="L">Laki-laki</option><option value="P">Perempuan</option>
          </select>
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Status</label>
          <select v-model="form.status" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
            <option v-for="s in studentStatuses" :key="s" :value="s">{{ s }}</option>
          </select>
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Jenis Kelas</label>
          <select v-model="form.class_type" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
            <option value="REGULER">Reguler</option><option value="KHUSUS">Khusus</option><option value="HYBRID">Hybrid</option>
          </select>
          <p class="text-xs text-gray-400 mt-1">Membedakan nominal SPP.</p>
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Email</label>
          <input v-model="form.email" type="email" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-blue-500" />
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">No. HP</label>
          <input v-model="form.phone" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-blue-500" />
        </div>
      </div>
      <div>
        <label class="block text-sm font-medium text-gray-700 mb-1">Dosen Wali / Pembimbing Akademik</label>
        <select v-model="form.advisor_id" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
          <option value="">-- Belum ditentukan --</option>
          <option v-for="l in lecturers" :key="l.id" :value="l.id">{{ l.name }}</option>
        </select>
      </div>
      <div>
        <label class="block text-sm font-medium text-gray-700 mb-1">Alamat</label>
        <textarea v-model="form.address" rows="2" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-blue-500" />
      </div>
      <div>
        <label class="block text-sm font-medium text-gray-700 mb-1">Asal Sekolah</label>
        <input v-model="form.origin_school" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-blue-500" />
      </div>
    </form>
    <template #footer>
      <button class="px-4 py-2 text-sm text-gray-600 hover:bg-gray-100 rounded-lg" @click="modalOpen = false">Batal</button>
      <button :disabled="saving" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 disabled:bg-blue-400 text-white text-sm font-medium rounded-lg" @click="handleSave">{{ saving ? 'Menyimpan...' : 'Simpan' }}</button>
    </template>
  </BaseModal>

  <BaseModal
    :open="biodataModalOpen"
    :title="`Biodata Mahasiswa Lengkap${biodataStudent ? ` · ${biodataStudent.name}` : ''}`"
    size="xl"
    @close="biodataModalOpen = false"
  >
    <div v-if="biodataLoading" class="py-10 text-center text-sm text-gray-500">Memuat biodata...</div>
    <form v-else class="space-y-6" @submit.prevent="saveBiodata">
      <div class="rounded-lg bg-gray-50 px-4 py-3 text-sm text-gray-600">
        NIM <span class="font-mono font-semibold text-gray-900">{{ biodataStudent?.nim }}</span>
        <span v-if="biodataStudent?.study_program?.code"> · {{ biodataStudent.study_program.code }}</span>
      </div>

      <section>
        <h3 class="mb-3 border-b pb-2 text-sm font-semibold text-gray-800">Data Pribadi</h3>
        <div class="grid gap-3 sm:grid-cols-2">
          <label class="text-xs font-medium text-gray-700 sm:col-span-2">Nama Lengkap *
            <input v-model="biodataForm.student.name" required maxlength="255" class="mt-1 w-full rounded-lg border border-gray-300 px-3 py-2 text-sm font-normal" />
          </label>
          <label class="text-xs font-medium text-gray-700">Jenis Kelamin
            <select v-model="biodataForm.student.gender" class="mt-1 w-full rounded-lg border border-gray-300 px-3 py-2 text-sm font-normal"><option value="">Pilih</option><option value="L">Laki-laki</option><option value="P">Perempuan</option></select>
          </label>
          <label class="text-xs font-medium text-gray-700">Agama
            <input v-model="biodataForm.profile.religion" maxlength="50" class="mt-1 w-full rounded-lg border border-gray-300 px-3 py-2 text-sm font-normal" />
          </label>
          <label class="text-xs font-medium text-gray-700">Tempat Lahir
            <input v-model="biodataForm.student.birth_place" maxlength="100" class="mt-1 w-full rounded-lg border border-gray-300 px-3 py-2 text-sm font-normal" />
          </label>
          <label class="text-xs font-medium text-gray-700">Tanggal Lahir
            <input v-model="biodataForm.student.birth_date" type="date" class="mt-1 w-full rounded-lg border border-gray-300 px-3 py-2 text-sm font-normal" />
          </label>
          <label class="text-xs font-medium text-gray-700">Email *
            <input v-model="biodataForm.student.email" required type="email" maxlength="255" class="mt-1 w-full rounded-lg border border-gray-300 px-3 py-2 text-sm font-normal" />
          </label>
          <label class="text-xs font-medium text-gray-700">Nomor HP
            <input v-model="biodataForm.student.phone" type="tel" maxlength="20" class="mt-1 w-full rounded-lg border border-gray-300 px-3 py-2 text-sm font-normal" />
          </label>
          <label class="text-xs font-medium text-gray-700">Telepon Rumah
            <input v-model="biodataForm.student.home_phone" type="tel" maxlength="30" class="mt-1 w-full rounded-lg border border-gray-300 px-3 py-2 text-sm font-normal" />
          </label>
          <label class="text-xs font-medium text-gray-700">NIK
            <input v-model="biodataForm.profile.nik" maxlength="20" class="mt-1 w-full rounded-lg border border-gray-300 px-3 py-2 text-sm font-normal" />
          </label>
          <label class="text-xs font-medium text-gray-700">NISN
            <input v-model="biodataForm.profile.nisn" maxlength="20" class="mt-1 w-full rounded-lg border border-gray-300 px-3 py-2 text-sm font-normal" />
          </label>
          <label class="text-xs font-medium text-gray-700">Golongan Darah
            <select v-model="biodataForm.profile.blood_type" class="mt-1 w-full rounded-lg border border-gray-300 px-3 py-2 text-sm font-normal"><option value="">Pilih</option><option v-for="blood in ['A', 'B', 'AB', 'O']" :key="blood">{{ blood }}</option></select>
          </label>
          <label class="text-xs font-medium text-gray-700">Kewarganegaraan
            <input v-model="biodataForm.profile.nationality" maxlength="50" class="mt-1 w-full rounded-lg border border-gray-300 px-3 py-2 text-sm font-normal" />
          </label>
        </div>
      </section>

      <section>
        <h3 class="mb-3 border-b pb-2 text-sm font-semibold text-gray-800">Alamat Domisili</h3>
        <div class="grid gap-3 sm:grid-cols-2">
          <label class="text-xs font-medium text-gray-700 sm:col-span-2">Alamat
            <textarea v-model="biodataForm.address.address" rows="2" maxlength="2000" class="mt-1 w-full rounded-lg border border-gray-300 px-3 py-2 text-sm font-normal" />
          </label>
          <label class="text-xs font-medium text-gray-700">Dusun<input v-model="biodataForm.address.hamlet" maxlength="100" class="mt-1 w-full rounded-lg border border-gray-300 px-3 py-2 text-sm font-normal" /></label>
          <label class="text-xs font-medium text-gray-700">RT<input v-model="biodataForm.address.rt" maxlength="5" class="mt-1 w-full rounded-lg border border-gray-300 px-3 py-2 text-sm font-normal" /></label>
          <label class="text-xs font-medium text-gray-700">RW<input v-model="biodataForm.address.rw" maxlength="5" class="mt-1 w-full rounded-lg border border-gray-300 px-3 py-2 text-sm font-normal" /></label>
          <label class="text-xs font-medium text-gray-700">Desa/Kelurahan<input v-model="biodataForm.address.village" maxlength="100" class="mt-1 w-full rounded-lg border border-gray-300 px-3 py-2 text-sm font-normal" /></label>
          <label class="text-xs font-medium text-gray-700">Kecamatan<input v-model="biodataForm.address.district" maxlength="100" class="mt-1 w-full rounded-lg border border-gray-300 px-3 py-2 text-sm font-normal" /></label>
          <label class="text-xs font-medium text-gray-700">Kota/Kabupaten<input v-model="biodataForm.address.city" maxlength="100" class="mt-1 w-full rounded-lg border border-gray-300 px-3 py-2 text-sm font-normal" /></label>
          <label class="text-xs font-medium text-gray-700">Provinsi<input v-model="biodataForm.address.province" maxlength="100" class="mt-1 w-full rounded-lg border border-gray-300 px-3 py-2 text-sm font-normal" /></label>
          <label class="text-xs font-medium text-gray-700">Kode Pos<input v-model="biodataForm.address.postal_code" maxlength="10" class="mt-1 w-full rounded-lg border border-gray-300 px-3 py-2 text-sm font-normal" /></label>
        </div>
      </section>

      <section>
        <h3 class="mb-3 border-b pb-2 text-sm font-semibold text-gray-800">Orang Tua dan Wali</h3>
        <div class="space-y-3">
          <fieldset v-for="key in biodataParentKeys" :key="key" class="grid gap-3 rounded-lg border border-gray-200 p-4 sm:grid-cols-2">
            <legend class="px-1 text-sm font-medium text-gray-700">{{ biodataParentLabels[key] }}</legend>
            <label class="text-xs font-medium text-gray-700">Nama<input v-model="biodataForm.parents[key].name" maxlength="255" class="mt-1 w-full rounded-lg border border-gray-300 px-3 py-2 text-sm font-normal" /></label>
            <label class="text-xs font-medium text-gray-700">NIK<input v-model="biodataForm.parents[key].nik" maxlength="20" class="mt-1 w-full rounded-lg border border-gray-300 px-3 py-2 text-sm font-normal" /></label>
            <label class="text-xs font-medium text-gray-700">Tanggal Lahir<input v-model="biodataForm.parents[key].birth_date" type="date" class="mt-1 w-full rounded-lg border border-gray-300 px-3 py-2 text-sm font-normal" /></label>
            <label class="text-xs font-medium text-gray-700">Pekerjaan<input v-model="biodataForm.parents[key].occupation" maxlength="100" class="mt-1 w-full rounded-lg border border-gray-300 px-3 py-2 text-sm font-normal" /></label>
            <label class="text-xs font-medium text-gray-700">Nomor HP<input v-model="biodataForm.parents[key].phone" type="tel" maxlength="20" class="mt-1 w-full rounded-lg border border-gray-300 px-3 py-2 text-sm font-normal" /></label>
            <label class="text-xs font-medium text-gray-700">Email<input v-model="biodataForm.parents[key].email" type="email" maxlength="255" class="mt-1 w-full rounded-lg border border-gray-300 px-3 py-2 text-sm font-normal" /></label>
            <label class="text-xs font-medium text-gray-700">Pendidikan Terakhir<input v-model="biodataForm.parents[key].education" maxlength="50" class="mt-1 w-full rounded-lg border border-gray-300 px-3 py-2 text-sm font-normal" /></label>
            <label class="text-xs font-medium text-gray-700">Penghasilan<input v-model="biodataForm.parents[key].income" maxlength="50" class="mt-1 w-full rounded-lg border border-gray-300 px-3 py-2 text-sm font-normal" /></label>
            <label class="flex items-center gap-2 text-sm text-gray-700"><input v-model="biodataForm.parents[key].is_alive" type="checkbox" class="rounded border-gray-300 text-blue-600" />Masih hidup</label>
          </fieldset>
        </div>
      </section>

      <section>
        <h3 class="mb-3 border-b pb-2 text-sm font-semibold text-gray-800">Pendidikan Sebelumnya</h3>
        <div class="grid gap-3 sm:grid-cols-2">
          <label class="text-xs font-medium text-gray-700">Nama Sekolah (SMA/MA/SMK)<input v-model="biodataForm.education.institution_name" maxlength="255" class="mt-1 w-full rounded-lg border border-gray-300 px-3 py-2 text-sm font-normal" /></label>
          <label class="text-xs font-medium text-gray-700">Jurusan<input v-model="biodataForm.education.major" maxlength="255" class="mt-1 w-full rounded-lg border border-gray-300 px-3 py-2 text-sm font-normal" /></label>
          <label class="text-xs font-medium text-gray-700 sm:col-span-2">Alamat Sekolah<input v-model="biodataForm.education.institution_address" maxlength="255" class="mt-1 w-full rounded-lg border border-gray-300 px-3 py-2 text-sm font-normal" /></label>
          <label class="text-xs font-medium text-gray-700">Tahun Lulus<input v-model="biodataForm.education.graduation_year" type="number" min="1900" :max="new Date().getFullYear()" class="mt-1 w-full rounded-lg border border-gray-300 px-3 py-2 text-sm font-normal" /></label>
          <label class="text-xs font-medium text-gray-700">Nomor Ijazah<input v-model="biodataForm.education.diploma_number" maxlength="100" class="mt-1 w-full rounded-lg border border-gray-300 px-3 py-2 text-sm font-normal" /></label>
        </div>
      </section>

      <section>
        <h3 class="mb-3 border-b pb-2 text-sm font-semibold text-gray-800">Tautan Dokumen</h3>
        <div class="space-y-3">
          <label class="block text-xs font-medium text-gray-700">Tautan Ijazah
            <input v-model="biodataForm.documents.diploma_link" type="url" maxlength="255" placeholder="https://" class="mt-1 w-full rounded-lg border border-gray-300 px-3 py-2 text-sm font-normal" />
            <a v-if="biodataForm.documents.diploma_link" :href="biodataForm.documents.diploma_link" target="_blank" rel="noopener noreferrer" class="mt-1 inline-block text-xs text-blue-600 hover:underline">Buka dokumen</a>
          </label>
          <label class="block text-xs font-medium text-gray-700">Tautan Kartu Keluarga
            <input v-model="biodataForm.documents.family_card_link" type="url" maxlength="255" placeholder="https://" class="mt-1 w-full rounded-lg border border-gray-300 px-3 py-2 text-sm font-normal" />
            <a v-if="biodataForm.documents.family_card_link" :href="biodataForm.documents.family_card_link" target="_blank" rel="noopener noreferrer" class="mt-1 inline-block text-xs text-blue-600 hover:underline">Buka dokumen</a>
          </label>
          <label class="block text-xs font-medium text-gray-700">Tautan KTP/Identitas
            <input v-model="biodataForm.documents.identity_link" type="url" maxlength="255" placeholder="https://" class="mt-1 w-full rounded-lg border border-gray-300 px-3 py-2 text-sm font-normal" />
            <a v-if="biodataForm.documents.identity_link" :href="biodataForm.documents.identity_link" target="_blank" rel="noopener noreferrer" class="mt-1 inline-block text-xs text-blue-600 hover:underline">Buka dokumen</a>
          </label>
        </div>
        <p class="mt-2 text-xs text-gray-500">Perubahan pada tautan dokumen akan meminta verifikasi ulang.</p>
      </section>
    </form>
    <template #footer>
      <button class="rounded-lg px-4 py-2 text-sm text-gray-600 hover:bg-gray-100" :disabled="savingBiodata" @click="biodataModalOpen = false">Batal</button>
      <button :disabled="biodataLoading || savingBiodata" class="rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700 disabled:opacity-50" @click="saveBiodata">{{ savingBiodata ? 'Menyimpan...' : 'Simpan Biodata' }}</button>
    </template>
  </BaseModal>

  <BaseModal :open="advisorModalOpen" :title="`Edit Pembimbing Akademik${advisorStudent ? ` · ${advisorStudent.name}` : ''}`" size="md" @close="advisorModalOpen = false">
    <form class="space-y-4" @submit.prevent="saveAdvisor">
      <div>
        <p class="text-sm text-gray-500">NIM {{ advisorStudent?.nim }}</p>
        <label class="block text-sm font-medium text-gray-700 mb-1">Dosen Pembimbing Akademik</label>
        <select v-model="selectedAdvisorId" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
          <option value="">-- Belum ditentukan --</option>
          <option v-for="lecturer in lecturers" :key="lecturer.id" :value="lecturer.id">{{ lecturer.name }}</option>
        </select>
      </div>
    </form>
    <template #footer>
      <button class="px-4 py-2 text-sm text-gray-600 hover:bg-gray-100 rounded-lg" :disabled="savingAdvisor" @click="advisorModalOpen = false">Batal</button>
      <button :disabled="savingAdvisor" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 disabled:bg-blue-400 text-white text-sm font-medium rounded-lg" @click="saveAdvisor">{{ savingAdvisor ? 'Menyimpan...' : 'Simpan' }}</button>
    </template>
  </BaseModal>
</template>
