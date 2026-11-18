<script setup lang="ts">
import { ref, onMounted, computed } from 'vue'
import { useRouter } from 'vue-router'
import { PlusIcon, EyeIcon, TrashIcon } from '@heroicons/vue/24/outline'
import { useToast } from 'vue-toastification'
import { useAuthStore } from '@/stores/auth'
import { useCrud } from '@/composables/useCrud'
import { useExcel } from '@/composables/useExcel'
import DataTable from '@/components/ui/DataTable.vue'
import BaseModal from '@/components/ui/BaseModal.vue'
import ExcelButtons from '@/components/ui/ExcelButtons.vue'
import api from '@/services/api'
import { getKrsStatusColor, getKrsStatusLabel } from '@/utils/krsStatus'

interface Krs {
  id: number; total_credits: number; status: string; display_status?: string; advisor_id?: number | null
  kaprodi_approved_at?: string | null; signed_by_kaprodi_at?: string | null
  student?: { id: number; nim: string; name: string; study_program?: { name: string; code: string } }
  semester?: { id: number; name: string; academic_year?: { name: string } }
  advisor?: { id: number; full_name?: string; name?: string }
}

const router = useRouter()
const toast  = useToast()
const auth   = useAuthStore()
const { items, pagination, loading, fetchAll } = useCrud<Krs>('/krs')
const { exporting, importing, importErrors, exportExcel, importExcel } = useExcel('/krs')

const semesters    = ref<any[]>([])
const filterSem    = ref('')
const filterStatus = ref('')
const filterProgram = ref('')
const filterSemesterMahasiswa = ref('')
const filterAngkatan = ref('')
const search       = ref('')
const creating     = ref(false)
const actingKrsId  = ref<number | null>(null)
const rejectModal  = ref(false)
const rejectTarget = ref<Krs | null>(null)
const rejectionNote = ref('')
const rejecting    = ref(false)
const programs     = ref<any[]>([])
const createModal  = ref(false)
const studentSearch = ref('')
const studentOptions = ref<any[]>([])
const selectedStudentId = ref('')

const statusOptions = [
  { value: 'DRAFT', label: 'Draf' },
  { value: 'SUBMITTED', label: 'Diajukan' },
  { value: 'APPROVED', label: 'Disetujui Dosen Wali' },
  { value: 'DISETUJUI_PRODI', label: 'Disetujui Prodi' },
  { value: 'DITANDATANGANI', label: 'Ditandatangani' },
  { value: 'REJECTED', label: 'Ditolak' },
  { value: 'DITOLAK_PRODI', label: 'Ditolak Prodi' },
  { value: 'CANCELLED', label: 'Dibatalkan' },
]

const columns = [
  { key: 'student', label: 'Mahasiswa' }, { key: 'semester', label: 'Semester' },
  { key: 'credits', label: 'SKS' }, { key: 'advisor', label: 'Dosen Wali' },
  { key: 'status', label: 'Status' }, { key: 'aksi', label: '', class: 'text-right' },
]

const isMahasiswa = auth.hasRole('MAHASISWA')
const canAdminKrs = computed(() =>
  auth.hasRole(['SUPER_ADMIN', 'ADMIN_AKADEMIK'])
  || (auth.user?.position_codes ?? []).some((code) => ['KAPRODI', 'SEKPRODI'].includes(code)),
)

onMounted(async () => {
  try {
    const [{ data }, { data: programData }] = await Promise.all([
      api.get('/semesters', { params: { per_page: 50 } }),
      api.get('/study-programs/all'),
    ])
    semesters.value = data.data ?? data
    programs.value = programData
    const active = semesters.value.find((s: any) => s.is_active)
    if (active) filterSem.value = active.id
  } catch {}
  load()
})

async function load(page = 1) {
  await fetchAll({ semester_id: filterSem.value, study_program_id: filterProgram.value, status: filterStatus.value, search: search.value, current_semester: filterSemesterMahasiswa.value, entry_year: filterAngkatan.value, page })
}

async function loadStudentOptions() {
  if (!canAdminKrs.value) return
  const { data } = await api.get('/krs/student-options', { params: { search: studentSearch.value } })
  studentOptions.value = data
}

async function openCreateModal() {
  if (!filterSem.value) { toast.warning('Pilih semester terlebih dahulu.'); return }
  selectedStudentId.value = ''
  studentSearch.value = ''
  await loadStudentOptions()
  createModal.value = true
}

function handleImport(file: File) {
  importExcel(file, () => load())
}

function canReview(row: Krs) {
  return row.status === 'SUBMITTED'
    && (auth.hasRole(['SUPER_ADMIN', 'ADMIN_AKADEMIK'])
      || Number((row as any).advisor_id ?? row.advisor?.id) === Number(auth.user?.lecturer?.id))
}

function canReviewKaprodi(row: Krs) {
  const approvedByAdvisor = row.status === 'APPROVED'
  const submittedWithoutAdvisor = row.status === 'SUBMITTED' && !(row.advisor_id ?? row.advisor?.id)
  return (approvedByAdvisor || submittedWithoutAdvisor)
    && !row.kaprodi_approved_at && !row.signed_by_kaprodi_at
    && (auth.hasRole(['SUPER_ADMIN', 'ADMIN_AKADEMIK'])
      || (auth.user?.position_codes ?? []).some((code) => ['KAPRODI', 'SEKPRODI'].includes(code)))
}

async function approveKaprodi(row: Krs) {
  if (!confirm(`Setujui KRS ${row.student?.name ?? ''} pada tahap program studi?`)) return
  actingKrsId.value = row.id
  try {
    const { data } = await api.post(`/krs/${row.id}/approve-kaprodi`)
    toast.success(data.message ?? 'KRS disetujui pada tahap prodi.')
    await load(pagination.value.currentPage)
  } catch (e: any) {
    toast.error(e?.response?.data?.message ?? 'Gagal menyetujui KRS pada tahap prodi.')
  } finally {
    actingKrsId.value = null
  }
}

function openRejectKaprodi(row: Krs) {
  rejectTarget.value = row
  rejectionNote.value = ''
  rejectModal.value = true
}

async function rejectKaprodi() {
  if (!rejectTarget.value) return
  if (!rejectionNote.value.trim()) {
    toast.warning('Catatan penolakan wajib diisi.')
    return
  }

  rejecting.value = true
  try {
    const { data } = await api.post(`/krs/${rejectTarget.value.id}/reject-kaprodi`, {
      note: rejectionNote.value.trim(),
    })
    toast.success(data.message ?? 'KRS ditolak pada tahap prodi.')
    rejectModal.value = false
    rejectTarget.value = null
    rejectionNote.value = ''
    await load(pagination.value.currentPage)
  } catch (e: any) {
    toast.error(e?.response?.data?.message ?? 'Gagal menolak KRS pada tahap prodi.')
  } finally {
    rejecting.value = false
  }
}

async function handleDeleteKrs(row: any) {
  if (!confirm(`Hapus KRS mahasiswa "${row.student?.name ?? ''}"? Data tidak dapat dikembalikan.`)) return
  try {
    await api.delete(`/krs/${row.id}`)
    toast.success('KRS berhasil dihapus.')
    load()
  } catch (e: any) { toast.error(e?.response?.data?.message ?? 'Gagal menghapus.') }
}

async function createKrs() {
  if (!filterSem.value) { toast.warning('Pilih semester terlebih dahulu.'); return }
  creating.value = true
  try {
    const studentId = isMahasiswa ? undefined : selectedStudentId.value
    if (!isMahasiswa && !studentId) { toast.warning('Pilih mahasiswa terlebih dahulu.'); return }
    const { data } = await api.post('/krs', { student_id: studentId, semester_id: filterSem.value })
    toast.success(data.message)
    createModal.value = false
    router.push(`/akademik/krs/${data.data.id}`)
  } catch (err: any) {
    toast.error(err?.response?.data?.message || 'Gagal membuat KRS.')
  } finally { creating.value = false }
}
</script>

<template>
  <div class="space-y-5">
    <div class="flex items-center justify-between">
      <div>
        <h1 class="text-xl font-bold text-gray-900">KRS & Perwalian</h1>
        <p class="text-sm text-gray-500 mt-0.5">Kartu Rencana Studi mahasiswa</p>
      </div>
      <div class="flex items-center gap-2">
        <ExcelButtons
          v-if="canAdminKrs"
          :exporting="exporting"
          :importing="importing"
          :import-errors="importErrors"
          :export-params="{ semester_id: filterSem, study_program_id: filterProgram, status: filterStatus, search, current_semester: filterSemesterMahasiswa, entry_year: filterAngkatan }"
          template-type="krs"
          @export="exportExcel"
          @import="handleImport"
        />
        <button v-if="isMahasiswa || canAdminKrs" :disabled="creating"
          class="flex items-center gap-2 px-4 py-2 bg-blue-600 hover:bg-blue-700 disabled:bg-blue-400 text-white text-sm font-medium rounded-lg"
          @click="isMahasiswa ? createKrs() : openCreateModal()">
          <PlusIcon class="w-4 h-4" /> {{ creating ? 'Membuat...' : 'Buat KRS' }}
        </button>
      </div>
    </div>

    <div class="flex flex-wrap gap-3">
      <select v-model="filterSem" class="px-3.5 py-2 rounded-lg border border-gray-300 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500" @change="load()">
        <option value="">Semua Semester</option>
        <option v-for="s in semesters" :key="s.id" :value="s.id">{{ s.name }}</option>
      </select>
      <select v-model="filterStatus" class="px-3.5 py-2 rounded-lg border border-gray-300 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500" @change="load()">
        <option value="">Semua Status</option>
        <option v-for="status in statusOptions" :key="status.value" :value="status.value">{{ status.label }}</option>
      </select>
      <select v-if="!isMahasiswa" v-model="filterProgram" class="px-3.5 py-2 rounded-lg border border-gray-300 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500" @change="load()">
        <option value="">Semua Prodi</option>
        <option v-for="program in programs" :key="program.id" :value="program.id">{{ program.code }} - {{ program.name }}</option>
      </select>
      <label v-if="!isMahasiswa" class="flex items-center gap-2 text-sm text-gray-600">
        Semester mahasiswa
        <input v-model="filterSemesterMahasiswa" type="number" min="1" placeholder="Semua" class="w-24 px-3.5 py-2 rounded-lg border border-gray-300 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500" @change="load()" />
      </label>
      <label v-if="!isMahasiswa" class="flex items-center gap-2 text-sm text-gray-600">
        Angkatan
        <input v-model="filterAngkatan" type="number" min="1" placeholder="Semua" class="w-28 px-3.5 py-2 rounded-lg border border-gray-300 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500" @change="load()" />
      </label>
      <input v-if="!isMahasiswa" v-model="search" type="text" placeholder="Cari NIM/nama..." class="px-3.5 py-2 rounded-lg border border-gray-300 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 w-52" @input="load()" />
    </div>

    <DataTable :columns="columns" :rows="items" :loading="loading" :total="pagination.total" :current-page="pagination.currentPage" :last-page="pagination.lastPage" @page-change="load">
      <template #default="{ row }">
        <td class="px-4 py-3">
          <p class="font-medium text-gray-900 text-sm">{{ row.student?.name }}</p>
          <p class="text-xs text-gray-500">{{ row.student?.nim }} · {{ row.student?.study_program?.code }}</p>
        </td>
        <td class="px-4 py-3 text-gray-700 text-sm">{{ row.semester?.name ?? '-' }}</td>
        <td class="px-4 py-3 text-center">
          <span class="inline-flex items-center justify-center w-9 h-9 rounded-full bg-blue-50 text-blue-700 font-bold text-sm">{{ row.total_credits }}</span>
        </td>
        <td class="px-4 py-3 text-gray-600 text-sm">{{ row.advisor?.name ?? row.advisor?.full_name ?? '-' }}</td>
        <td class="px-4 py-3">
          <div class="flex flex-col items-start gap-1">
            <span :class="['inline-flex px-2 py-0.5 rounded-full text-xs font-medium', getKrsStatusColor(row.display_status ?? row.status)]">{{ getKrsStatusLabel(row.display_status ?? row.status) }}</span>
            <span v-if="row.status === 'APPROVED' && !row.kaprodi_approved_at" class="text-xs text-amber-700">Menunggu persetujuan prodi</span>
            <span v-else-if="row.kaprodi_approved_at && !row.signed_by_kaprodi_at" class="text-xs text-blue-700">Menunggu tanda tangan</span>
          </div>
        </td>
        <td class="px-4 py-3 text-right">
          <div class="flex items-center justify-end gap-1">
            <button class="p-1.5 rounded-lg text-blue-600 hover:bg-blue-50" @click="router.push(`/akademik/krs/${row.id}`)">
              <EyeIcon class="w-4 h-4" />
            </button>
            <button v-if="canReview(row)" class="px-2 py-1 rounded-lg text-xs font-medium text-green-700 bg-green-50 hover:bg-green-100" @click="router.push(`/akademik/krs/${row.id}`)">
              Tinjau
            </button>
            <template v-if="canReviewKaprodi(row)">
              <button :disabled="actingKrsId === row.id" class="px-2 py-1 rounded-lg text-xs font-medium text-white bg-green-600 hover:bg-green-700 disabled:bg-green-300" @click="approveKaprodi(row)">
                {{ actingKrsId === row.id ? 'Memproses...' : 'Setujui' }}
              </button>
              <button :disabled="actingKrsId === row.id" class="px-2 py-1 rounded-lg text-xs font-medium text-red-700 bg-red-50 hover:bg-red-100 disabled:opacity-50" @click="openRejectKaprodi(row)">
                Tolak
              </button>
            </template>
            <button v-if="canAdminKrs && (row.status !== 'APPROVED' || auth.hasRole('SUPER_ADMIN'))" class="p-1.5 rounded-lg text-red-500 hover:bg-red-50" title="Hapus KRS" @click="handleDeleteKrs(row)">
              <TrashIcon class="w-4 h-4" />
            </button>
          </div>
        </td>
      </template>
    </DataTable>
  </div>

  <BaseModal :open="createModal" title="Buat KRS Mahasiswa" @close="createModal = false">
    <div class="space-y-3">
      <div>
        <label class="block text-sm font-medium text-gray-700 mb-1">Semester</label>
        <p class="px-3 py-2 bg-gray-50 border border-gray-200 rounded-lg text-sm text-gray-700">
          {{ semesters.find((semester) => semester.id == filterSem)?.name ?? '-' }}
        </p>
      </div>
      <div>
        <label class="block text-sm font-medium text-gray-700 mb-1">Cari mahasiswa aktif</label>
        <input v-model="studentSearch" type="text" placeholder="NIM atau nama mahasiswa..." class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm" @input="loadStudentOptions" />
      </div>
      <div>
        <label class="block text-sm font-medium text-gray-700 mb-1">Mahasiswa <span class="text-red-500">*</span></label>
        <select v-model="selectedStudentId" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm">
          <option value="">Pilih mahasiswa</option>
          <option v-for="student in studentOptions" :key="student.id" :value="student.id">
            {{ student.nim }} - {{ student.name }} ({{ student.study_program?.code }})
          </option>
        </select>
      </div>
    </div>
    <template #footer>
      <button class="px-4 py-2 text-sm text-gray-600 hover:bg-gray-100 rounded-lg" @click="createModal = false">Batal</button>
      <button :disabled="creating || !selectedStudentId" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 disabled:bg-blue-400 text-white text-sm font-medium rounded-lg" @click="createKrs">
        {{ creating ? 'Membuat...' : 'Buat KRS' }}
      </button>
    </template>
  </BaseModal>

  <BaseModal :open="rejectModal" title="Tolak KRS pada Tahap Prodi" @close="rejectModal = false">
    <div class="space-y-3">
      <p class="text-sm text-gray-600">
        {{ rejectTarget?.student?.name }} · {{ rejectTarget?.student?.nim }}
      </p>
      <label for="krs-prodi-rejection-note" class="block text-sm font-medium text-gray-700">
        Catatan penolakan <span class="text-red-500">*</span>
      </label>
      <textarea
        id="krs-prodi-rejection-note"
        v-model="rejectionNote"
        rows="4"
        maxlength="2000"
        required
        placeholder="Jelaskan alasan penolakan dan hal yang perlu diperbaiki."
        class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
      />
    </div>
    <template #footer>
      <button :disabled="rejecting" class="px-4 py-2 text-sm text-gray-600 hover:bg-gray-100 disabled:opacity-50 rounded-lg" @click="rejectModal = false">Batal</button>
      <button :disabled="rejecting || !rejectionNote.trim()" class="px-4 py-2 bg-red-600 hover:bg-red-700 disabled:bg-red-300 text-white text-sm font-medium rounded-lg" @click="rejectKaprodi">
        {{ rejecting ? 'Memproses...' : 'Tolak KRS' }}
      </button>
    </template>
  </BaseModal>
</template>
