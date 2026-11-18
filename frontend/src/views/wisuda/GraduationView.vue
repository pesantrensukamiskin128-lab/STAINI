<script setup lang="ts">
import { ref, reactive, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { useToast } from 'vue-toastification'
import { useAuthStore } from '@/stores/auth'
import { PlusIcon, EyeIcon, PencilIcon, TrashIcon } from '@heroicons/vue/24/outline'
import DataTable from '@/components/ui/DataTable.vue'
import BaseModal from '@/components/ui/BaseModal.vue'
import api from '@/services/api'
import { toDateInput } from '@/utils/formValue'

const router = useRouter()
const toast = useToast()
const auth = useAuthStore()
const isAdmin = auth.user?.roles?.includes('SUPER_ADMIN') || auth.user?.roles?.includes('ADMIN_AKADEMIK')
const isMahasiswa = auth.hasRole('MAHASISWA')

const activeView = ref<'periods' | 'registrations'>('registrations')
const periods = ref<any[]>([])
const registrations = ref<any[]>([])
const pagination = ref({ total: 0, currentPage: 1, lastPage: 1 })
const loading = ref(true)
const deletingRegistrationId = ref<number | null>(null)
const filterPeriod = ref('')
const filterStatus = ref('')
const search = ref('')
const dashboard = ref<any>(null)
const academicYears = ref<any[]>([])

// Period modal
const periodModal = ref(false); const periodSaving = ref(false); const editingPeriodId = ref<number|null>(null)
const periodForm = reactive({ name: '', academic_year_id: '', registration_start: '', registration_end: '', graduation_date: '', venue: '', description: '', is_active: false })

// Register modal
const regModal = ref(false); const regSaving = ref(false)
const regForm = reactive({ period_id: '', toga_size: '', phone: '', address_current: '', thesis_title: '', gpa: '', impression_message: '' })
const regProfile = reactive({ name: '', nim: '', birth: '', study_program: '' })
const regFiles = reactive<{ payment_proof: File | null; photo: File | null }>({ payment_proof: null, photo: null })

const statusColor: Record<string, string> = {
  DRAFT: 'bg-gray-100 text-gray-600', SUBMITTED: 'bg-blue-100 text-blue-700',
  VERIFIKASI_AKADEMIK: 'bg-indigo-100 text-indigo-700', VERIFIKASI_KEUANGAN: 'bg-yellow-100 text-yellow-700',
  VERIFIKASI_PERPUSTAKAAN: 'bg-purple-100 text-purple-700', APPROVED: 'bg-green-100 text-green-700',
  REJECTED: 'bg-red-100 text-red-600', WISUDA: 'bg-emerald-100 text-emerald-700',
}

const columns = [
  { key: 'student', label: 'Mahasiswa' }, { key: 'period', label: 'Periode' },
  { key: 'gpa', label: 'IPK' }, { key: 'predicate', label: 'Predikat' },
  { key: 'status', label: 'Status' }, { key: 'aksi', label: '', class: 'text-right' },
]

onMounted(async () => {
  const [ayRes, dRes] = await Promise.all([api.get('/academic-years/all'), api.get('/graduation/dashboard')])
  academicYears.value = ayRes.data
  dashboard.value = dRes.data
  await loadPeriods()
  loadRegistrations()
})

async function loadPeriods() {
  const { data } = await api.get('/graduation/periods', { params: { per_page: 50 } })
  periods.value = data.data ?? data
}

async function loadRegistrations(page = 1) {
  loading.value = true
  try {
    const { data } = await api.get('/graduation/registrations', { params: { period_id: filterPeriod.value, status: filterStatus.value, search: search.value, page } })
    registrations.value = data.data
    pagination.value = { total: data.total, currentPage: data.current_page, lastPage: data.last_page }
  } finally { loading.value = false }
}

function openCreatePeriod() { editingPeriodId.value = null; Object.assign(periodForm, { name: '', academic_year_id: '', registration_start: '', registration_end: '', graduation_date: '', venue: '', description: '', is_active: false }); periodModal.value = true }
function openEditPeriod(p: any) { editingPeriodId.value = p.id; Object.assign(periodForm, { name: p.name, academic_year_id: p.academic_year_id ?? p.academic_year?.id ?? '', registration_start: toDateInput(p.registration_start), registration_end: toDateInput(p.registration_end), graduation_date: toDateInput(p.graduation_date), venue: p.venue ?? '', description: p.description ?? '', is_active: p.is_active }); periodModal.value = true }

async function savePeriod() {
  periodSaving.value = true
  try {
    if (editingPeriodId.value) { await api.put(`/graduation/periods/${editingPeriodId.value}`, periodForm) }
    else { await api.post('/graduation/periods', periodForm) }
    toast.success('Periode wisuda berhasil disimpan.'); periodModal.value = false; loadPeriods()
  } catch (e: any) { toast.error(e?.response?.data?.message ?? 'Gagal.') }
  finally { periodSaving.value = false }
}

async function deletePeriod(p: any) {
  if (!confirm(`Hapus periode "${p.name}"?`)) return
  try { await api.delete(`/graduation/periods/${p.id}`); toast.success('Dihapus.'); loadPeriods() }
  catch (e: any) { toast.error(e?.response?.data?.message ?? 'Gagal.') }
}

async function openRegister() {
  const active = periods.value.find((p: any) => p.is_active)
  Object.assign(regForm, { period_id: active?.id ?? '', toga_size: '', phone: '', address_current: '', thesis_title: '', gpa: '', impression_message: '' })
  regFiles.payment_proof = null; regFiles.photo = null
  try {
    const { data: p } = await api.get('/graduation/my-profile')
    const birthDate = p.birth_date ? formatDate(p.birth_date) : ''
    Object.assign(regProfile, { name: p.name ?? '', nim: p.nim ?? '', birth: [p.birth_place, birthDate].filter(Boolean).join(', '), study_program: p.study_program ?? '' })
    regForm.phone = p.phone ?? ''
    regForm.thesis_title = p.thesis_title ?? ''
  } catch (e: any) { toast.error(e?.response?.data?.message ?? 'Gagal memuat data mahasiswa.'); return }
  regModal.value = true
}

function onRegFile(field: 'payment_proof' | 'photo', e: Event) {
  regFiles[field] = (e.target as HTMLInputElement).files?.[0] ?? null
}

async function submitRegister() {
  if (!regFiles.payment_proof || !regFiles.photo) { toast.error('Bukti pembayaran dan pas foto wajib diunggah.'); return }
  regSaving.value = true
  try {
    const fd = new FormData()
    Object.entries(regForm).forEach(([k, v]) => fd.append(k, String(v ?? '')))
    fd.append('payment_proof', regFiles.payment_proof)
    fd.append('photo', regFiles.photo)
    await api.post('/graduation/register', fd)
    toast.success('Pendaftaran wisuda berhasil!'); regModal.value = false; loadRegistrations()
  } catch (e: any) {
    const errs = e?.response?.data?.errors
    toast.error((errs && Object.values(errs).flat()[0] as string) || e?.response?.data?.message || 'Gagal.')
  }
  finally { regSaving.value = false }
}

async function deleteRegistration(registration: any) {
  const studentName = registration.student?.name ?? 'mahasiswa'
  const status = registration.status.replace(/_/g, ' ')
  if (!confirm(`Hapus pendaftaran wisuda ${studentName} dengan status ${status}? Data pendaftaran, dokumen, dan verifikasinya akan dihapus. Status mahasiswa tidak diubah.`)) return

  deletingRegistrationId.value = registration.id
  try {
    await api.delete(`/graduation/registrations/${registration.id}`)
    toast.success('Pendaftaran wisuda berhasil dihapus.')
    const page = registrations.value.length === 1 && pagination.value.currentPage > 1
      ? pagination.value.currentPage - 1
      : pagination.value.currentPage
    try {
      await Promise.all([
        loadRegistrations(page),
        loadPeriods(),
        api.get('/graduation/dashboard').then(({ data }) => { dashboard.value = data }),
      ])
    } catch {
      toast.error('Pendaftaran terhapus, tetapi sebagian ringkasan gagal diperbarui. Muat ulang halaman.')
    }
  } catch (e: any) {
    toast.error(e?.response?.data?.message ?? 'Gagal menghapus pendaftaran wisuda.')
  } finally {
    deletingRegistrationId.value = null
  }
}

function formatDate(d: string) { return d ? new Date(d).toLocaleDateString('id-ID', { day: 'numeric', month: 'short', year: 'numeric' }) : '-' }
</script>

<template>
  <div class="space-y-5">
    <div class="flex items-center justify-between">
      <div>
        <h1 class="text-xl font-bold text-gray-900">Wisuda</h1>
        <p class="text-sm text-gray-500 mt-0.5">Pendaftaran dan pengelolaan wisuda</p>
      </div>
      <div class="flex items-center gap-2">
        <button v-if="isAdmin" class="px-3 py-2 text-sm border border-gray-300 text-gray-700 hover:bg-gray-50 rounded-lg" @click="openCreatePeriod">+ Periode</button>
        <button v-if="isMahasiswa" class="flex items-center gap-2 px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium rounded-lg" @click="openRegister"><PlusIcon class="w-4 h-4" /> Daftar Wisuda</button>
      </div>
    </div>

    <!-- Dashboard -->
    <div v-if="dashboard" class="grid grid-cols-2 md:grid-cols-5 gap-3">
      <div class="rounded-lg border border-blue-200 bg-blue-50 p-3 text-center"><p class="text-xl font-bold text-blue-700">{{ dashboard.submitted }}</p><p class="text-[10px] text-blue-600">Disubmit</p></div>
      <div class="rounded-lg border border-yellow-200 bg-yellow-50 p-3 text-center"><p class="text-xl font-bold text-yellow-700">{{ dashboard.in_process }}</p><p class="text-[10px] text-yellow-600">Verifikasi</p></div>
      <div class="rounded-lg border border-green-200 bg-green-50 p-3 text-center"><p class="text-xl font-bold text-green-700">{{ dashboard.approved }}</p><p class="text-[10px] text-green-600">Disetujui</p></div>
      <div class="rounded-lg border border-emerald-200 bg-emerald-50 p-3 text-center"><p class="text-xl font-bold text-emerald-700">{{ dashboard.graduated }}</p><p class="text-[10px] text-emerald-600">Wisuda</p></div>
      <div class="rounded-lg border border-gray-200 bg-gray-50 p-3 text-center"><p class="text-xl font-bold text-gray-700">{{ dashboard.total }}</p><p class="text-[10px] text-gray-600">Total</p></div>
    </div>

    <!-- Periods (admin) -->
    <div v-if="isAdmin && periods.length" class="bg-white rounded-xl border border-gray-200 p-4">
      <h3 class="text-sm font-semibold text-gray-800 mb-3">Periode Wisuda</h3>
      <div class="space-y-2">
        <div v-for="p in periods" :key="p.id" class="flex items-center justify-between p-3 rounded-lg border border-gray-100 hover:bg-gray-50">
          <div>
            <p class="text-sm font-medium text-gray-900">{{ p.name }} <span v-if="p.is_active" class="text-xs text-green-600 font-bold">● Aktif</span></p>
            <p class="text-xs text-gray-500">{{ formatDate(p.graduation_date) }} · {{ p.registrations_count ?? 0 }} pendaftar</p>
          </div>
          <div class="flex items-center gap-1">
            <button class="p-1 rounded text-blue-600 hover:bg-blue-50" @click="openEditPeriod(p)"><PencilIcon class="w-4 h-4" /></button>
            <button class="p-1 rounded text-red-500 hover:bg-red-50" @click="deletePeriod(p)"><TrashIcon class="w-4 h-4" /></button>
          </div>
        </div>
      </div>
    </div>

    <!-- Filters -->
    <div class="flex flex-wrap gap-3">
      <select v-model="filterPeriod" class="px-3.5 py-2 rounded-lg border border-gray-300 text-sm" @change="loadRegistrations()">
        <option value="">Semua Periode</option>
        <option v-for="p in periods" :key="p.id" :value="p.id">{{ p.name }}</option>
      </select>
      <select v-if="!isMahasiswa" v-model="filterStatus" class="px-3.5 py-2 rounded-lg border border-gray-300 text-sm" @change="loadRegistrations()">
        <option value="">Semua Status</option>
        <option value="DRAFT">Draft</option><option value="SUBMITTED">Submitted</option>
        <option value="VERIFIKASI_AKADEMIK">Verifikasi Akademik</option><option value="VERIFIKASI_KEUANGAN">Verifikasi Keuangan</option>
        <option value="VERIFIKASI_PERPUSTAKAAN">Verifikasi Perpustakaan</option><option value="APPROVED">Approved</option>
        <option value="REJECTED">Ditolak</option><option value="WISUDA">Wisuda</option>
      </select>
      <input v-if="!isMahasiswa" v-model="search" type="text" placeholder="Cari NIM/nama..." class="px-3.5 py-2 rounded-lg border border-gray-300 text-sm w-52" @input="loadRegistrations()" />
    </div>

    <!-- Registrations Table -->
    <DataTable :columns="columns" :rows="registrations" :loading="loading" :total="pagination.total" :current-page="pagination.currentPage" :last-page="pagination.lastPage" @page-change="loadRegistrations">
      <template #default="{ row }">
        <td class="px-4 py-3"><p class="font-medium text-gray-900 text-sm">{{ row.student?.name }}</p><p class="text-xs text-gray-500">{{ row.student?.nim }} · {{ row.student?.study_program?.code }}</p></td>
        <td class="px-4 py-3 text-xs text-gray-600">{{ row.period?.name ?? '-' }}</td>
        <td class="px-4 py-3 text-sm font-bold text-gray-800">{{ row.gpa ?? '-' }}</td>
        <td class="px-4 py-3 text-sm text-gray-600">{{ row.predicate ?? '-' }}</td>
        <td class="px-4 py-3"><span :class="['inline-flex px-2 py-0.5 rounded-full text-xs font-medium whitespace-nowrap', statusColor[row.status]]">{{ row.status.replace(/_/g, ' ') }}</span></td>
        <td class="px-4 py-3">
          <div class="flex justify-end gap-1">
            <button class="p-1.5 rounded-lg text-blue-600 hover:bg-blue-50" title="Lihat pendaftaran" @click="router.push(`/wisuda/${row.id}`)"><EyeIcon class="w-4 h-4" /></button>
            <button v-if="isAdmin" :disabled="deletingRegistrationId === row.id" class="p-1.5 rounded-lg text-red-600 hover:bg-red-50 disabled:opacity-50" title="Hapus pendaftaran wisuda" :aria-label="`Hapus pendaftaran wisuda ${row.student?.name ?? ''}`" @click="deleteRegistration(row)"><TrashIcon class="w-4 h-4" /></button>
          </div>
        </td>
      </template>
    </DataTable>
  </div>

  <!-- Period Modal -->
  <BaseModal :open="periodModal" :title="editingPeriodId ? 'Edit Periode' : 'Tambah Periode Wisuda'" size="xl" @close="periodModal = false">
    <form class="space-y-4" @submit.prevent="savePeriod">
      <div class="grid grid-cols-2 gap-4">
        <div><label class="block text-sm font-medium text-gray-700 mb-1">Nama <span class="text-red-500">*</span></label><input v-model="periodForm.name" required placeholder="Wisuda Ke-XX 2026" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm" /></div>
        <div><label class="block text-sm font-medium text-gray-700 mb-1">Tahun Akademik</label><select v-model="periodForm.academic_year_id" required class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm"><option value="">-- Pilih --</option><option v-for="y in academicYears" :key="y.id" :value="y.id">{{ y.name }}</option></select></div>
      </div>
      <div class="grid grid-cols-3 gap-4">
        <div><label class="block text-sm font-medium text-gray-700 mb-1">Pendaftaran Mulai</label><input v-model="periodForm.registration_start" type="date" required class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm" /></div>
        <div><label class="block text-sm font-medium text-gray-700 mb-1">Pendaftaran Selesai</label><input v-model="periodForm.registration_end" type="date" required class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm" /></div>
        <div><label class="block text-sm font-medium text-gray-700 mb-1">Tanggal Wisuda</label><input v-model="periodForm.graduation_date" type="date" required class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm" /></div>
      </div>
      <div><label class="block text-sm font-medium text-gray-700 mb-1">Tempat</label><input v-model="periodForm.venue" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm" /></div>
      <label class="flex items-center gap-2 text-sm text-gray-700"><input v-model="periodForm.is_active" type="checkbox" class="rounded" /> Aktifkan periode</label>
    </form>
    <template #footer>
      <button class="px-4 py-2 text-sm text-gray-600 hover:bg-gray-100 rounded-lg" @click="periodModal = false">Batal</button>
      <button :disabled="periodSaving" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 disabled:bg-blue-400 text-white text-sm font-medium rounded-lg" @click="savePeriod">Simpan</button>
    </template>
  </BaseModal>

  <!-- Register Modal -->
  <BaseModal :open="regModal" title="Daftar Wisuda" @close="regModal = false">
    <form class="space-y-4" @submit.prevent="submitRegister">
      <div class="grid grid-cols-2 gap-4">
        <div><label class="block text-sm font-medium text-gray-700 mb-1">Nama</label><input :value="regProfile.name" readonly class="w-full px-3 py-2 border border-gray-200 bg-gray-50 text-gray-600 rounded-lg text-sm" /></div>
        <div><label class="block text-sm font-medium text-gray-700 mb-1">NIM</label><input :value="regProfile.nim" readonly class="w-full px-3 py-2 border border-gray-200 bg-gray-50 text-gray-600 rounded-lg text-sm" /></div>
        <div><label class="block text-sm font-medium text-gray-700 mb-1">Tempat/Tgl. Lahir</label><input :value="regProfile.birth" readonly class="w-full px-3 py-2 border border-gray-200 bg-gray-50 text-gray-600 rounded-lg text-sm" /></div>
        <div><label class="block text-sm font-medium text-gray-700 mb-1">Program Studi</label><input :value="regProfile.study_program" readonly class="w-full px-3 py-2 border border-gray-200 bg-gray-50 text-gray-600 rounded-lg text-sm" /></div>
      </div>
      <div><label class="block text-sm font-medium text-gray-700 mb-1">Periode Wisuda <span class="text-red-500">*</span></label><select v-model="regForm.period_id" required class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm"><option value="">-- Pilih --</option><option v-for="p in periods.filter(x => x.is_active)" :key="p.id" :value="p.id">{{ p.name }}</option></select></div>
      <div class="grid grid-cols-2 gap-4">
        <div><label class="block text-sm font-medium text-gray-700 mb-1">Ukuran Toga</label><select v-model="regForm.toga_size" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm"><option value="">-- Pilih --</option><option value="S">S</option><option value="M">M</option><option value="L">L</option><option value="XL">XL</option><option value="XXL">XXL</option></select></div>
        <div><label class="block text-sm font-medium text-gray-700 mb-1">No. HP</label><input v-model="regForm.phone" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm" /></div>
      </div>
      <div><label class="block text-sm font-medium text-gray-700 mb-1">Alamat Tinggal <span class="text-red-500">*</span></label><textarea v-model="regForm.address_current" required rows="2" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm" /></div>
      <div><label class="block text-sm font-medium text-gray-700 mb-1">Judul Skripsi <span class="text-red-500">*</span></label><textarea v-model="regForm.thesis_title" required rows="2" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm" /></div>
      <div><label class="block text-sm font-medium text-gray-700 mb-1">IPK <span class="text-red-500">*</span></label><input v-model="regForm.gpa" required type="number" step="0.01" min="0" max="4" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm" /></div>
      <div><label class="block text-sm font-medium text-gray-700 mb-1">Kesan dan Pesan <span class="text-red-500">*</span></label><textarea v-model="regForm.impression_message" required rows="3" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm" /></div>
      <div><label class="block text-sm font-medium text-gray-700 mb-1">Bukti Pembayaran Wisuda (atau Deposit) <span class="text-red-500">*</span></label><input type="file" required accept=".jpg,.jpeg,.png,.pdf" class="w-full text-sm" @change="onRegFile('payment_proof', $event)" /><p class="text-xs text-gray-400 mt-1">JPG/PNG/PDF, maks 5 MB.</p></div>
      <div><label class="block text-sm font-medium text-gray-700 mb-1">Pas Foto (Potret) <span class="text-red-500">*</span></label><input type="file" required accept=".jpg,.jpeg,.png" class="w-full text-sm" @change="onRegFile('photo', $event)" /><p class="text-xs text-gray-400 mt-1">JPG/PNG, maks 3 MB.</p></div>
    </form>
    <template #footer>
      <button class="px-4 py-2 text-sm text-gray-600 hover:bg-gray-100 rounded-lg" @click="regModal = false">Batal</button>
      <button :disabled="regSaving" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 disabled:bg-blue-400 text-white text-sm font-medium rounded-lg" @click="submitRegister">Daftar</button>
    </template>
  </BaseModal>
</template>
