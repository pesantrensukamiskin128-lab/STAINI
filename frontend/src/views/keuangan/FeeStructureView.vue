<script setup lang="ts">
import { onMounted, reactive, ref, computed } from 'vue'
import { PlusIcon, PencilIcon, TrashIcon, FunnelIcon } from '@heroicons/vue/24/outline'
import { useToast } from 'vue-toastification'
import BaseModal from '@/components/ui/BaseModal.vue'
import api from '@/services/api'

interface Option { id: number; name: string; code?: string }
interface FeeStructure {
  id: number
  fee_type_id: number
  study_program_id: number | null
  academic_year_id: number | null
  academic_level: number | null
  class_type: string | null
  amount: number
  note: string | null
  fee_type?: Option
  study_program?: Option | null
  academic_year?: Option | null
}

const toast = useToast()
const items = ref<FeeStructure[]>([])
const feeTypes = ref<Option[]>([])
const studyPrograms = ref<Option[]>([])
const academicYears = ref<Option[]>([])
const loading = ref(true)
const saving = ref(false)
const modalOpen = ref(false)
const editingId = ref<number | null>(null)
const filters = reactive({ fee_type_id: '', study_program_id: '', academic_year_id: '', academic_level: '', class_type: '' })
const form = reactive({ fee_type_id: '', study_program_id: '', academic_year_id: '', academic_level: '', class_type: '', amount: '', note: '' })
const levels = Array.from({ length: 14 }, (_, i) => i + 1)
const hasFilters = computed(() => Object.values(filters).some(Boolean))
const inputClass = 'w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-blue-500'
const fieldClass = 'block text-sm font-medium text-gray-700 space-y-1'
const displayedItems = computed(() => filters.academic_level
  ? items.value.filter(item => String(item.academic_level ?? '') === filters.academic_level)
  : items.value)

onMounted(async () => {
  await Promise.all([loadOptions(), load()])
})

async function loadOptions() {
  const [feeTypeRes, programRes, yearRes] = await Promise.all([
    api.get('/finance/fee-types'),
    api.get('/study-programs/all'),
    api.get('/academic-years/all'),
  ])
  feeTypes.value = feeTypeRes.data?.data ?? feeTypeRes.data
  studyPrograms.value = programRes.data?.data ?? programRes.data
  academicYears.value = yearRes.data?.data ?? yearRes.data
}

async function load() {
  loading.value = true
  try {
    const { academic_level: _academicLevel, ...serverFilters } = filters
    const params = Object.fromEntries(Object.entries(serverFilters).filter(([, value]) => value !== ''))
    const { data } = await api.get('/finance/fee-structures', { params: { ...params, per_page: 100 } })
    items.value = data.data ?? data
  } catch (e: any) {
    toast.error(e?.response?.data?.message ?? 'Gagal memuat struktur biaya.')
  } finally { loading.value = false }
}

function clearFilters() {
  Object.assign(filters, { fee_type_id: '', study_program_id: '', academic_year_id: '', academic_level: '', class_type: '' })
  load()
}

function openCreate() {
  editingId.value = null
  Object.assign(form, { fee_type_id: '', study_program_id: '', academic_year_id: '', academic_level: '', class_type: '', amount: '', note: '' })
  modalOpen.value = true
}

function openEdit(item: FeeStructure) {
  editingId.value = item.id
  Object.assign(form, {
    fee_type_id: String(item.fee_type_id),
    study_program_id: item.study_program_id ? String(item.study_program_id) : '',
    academic_year_id: item.academic_year_id ? String(item.academic_year_id) : '',
    academic_level: item.academic_level ? String(item.academic_level) : '',
    class_type: item.class_type ?? '',
    amount: String(item.amount),
    note: item.note ?? '',
  })
  modalOpen.value = true
}

async function handleSave() {
  saving.value = true
  const payload = {
    fee_type_id: Number(form.fee_type_id),
    study_program_id: form.study_program_id ? Number(form.study_program_id) : null,
    academic_year_id: form.academic_year_id ? Number(form.academic_year_id) : null,
    academic_level: form.academic_level ? Number(form.academic_level) : null,
    class_type: form.class_type || null,
    amount: Number(form.amount),
    note: form.note || null,
  }
  try {
    if (editingId.value) {
      await api.put(`/finance/fee-structures/${editingId.value}`, payload)
      toast.success('Struktur biaya berhasil diupdate.')
    } else {
      await api.post('/finance/fee-structures', payload)
      toast.success('Struktur biaya berhasil ditambahkan.')
    }
    modalOpen.value = false
    await load()
  } catch (e: any) {
    toast.error(e?.response?.data?.message ?? 'Gagal menyimpan struktur biaya.')
  } finally { saving.value = false }
}

async function handleDelete(item: FeeStructure) {
  if (!confirm(`Hapus struktur "${item.fee_type?.name ?? 'ini'}"?`)) return
  try {
    await api.delete(`/finance/fee-structures/${item.id}`)
    toast.success('Struktur biaya berhasil dihapus.')
    await load()
  } catch (e: any) { toast.error(e?.response?.data?.message ?? 'Gagal menghapus struktur biaya.') }
}

function formatCurrency(value: number) {
  return new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', minimumFractionDigits: 0 }).format(value || 0)
}
</script>

<template>
  <div class="space-y-5">
    <div class="flex items-center justify-between gap-4">
      <div>
        <h1 class="text-xl font-bold text-gray-900">Struktur Biaya</h1>
        <p class="text-sm text-gray-500 mt-0.5">Atur biaya berdasarkan program studi, tahun akademik, dan tingkat.</p>
      </div>
      <button class="flex items-center gap-2 px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium rounded-lg" @click="openCreate">
        <PlusIcon class="w-4 h-4" /> Tambah Struktur
      </button>
    </div>

    <div class="bg-white rounded-xl border border-gray-200 p-4">
      <div class="flex items-center gap-2 mb-3 text-sm font-semibold text-gray-700"><FunnelIcon class="w-4 h-4" /> Filter</div>
      <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3">
        <select v-model="filters.fee_type_id" :class="inputClass"><option value="">Semua Jenis Tagihan</option><option v-for="item in feeTypes" :key="item.id" :value="item.id">{{ item.name }}</option></select>
        <select v-model="filters.study_program_id" :class="inputClass"><option value="">Semua Program Studi</option><option v-for="item in studyPrograms" :key="item.id" :value="item.id">{{ item.name }}</option></select>
        <select v-model="filters.academic_year_id" :class="inputClass"><option value="">Semua Tahun Akademik</option><option v-for="item in academicYears" :key="item.id" :value="item.id">{{ item.name }}</option></select>
        <select v-model="filters.academic_level" :class="inputClass"><option value="">Semua Tingkat</option><option v-for="level in levels" :key="level" :value="level">Tingkat {{ level }}</option></select>
        <select v-model="filters.class_type" :class="inputClass"><option value="">Semua Kelas</option><option value="REGULER">Reguler</option><option value="KHUSUS">Khusus</option><option value="HYBRID">Hybrid</option></select>
      </div>
      <div class="flex justify-end gap-2 mt-3">
        <button v-if="hasFilters" class="px-3 py-1.5 text-sm text-gray-600 hover:bg-gray-100 rounded-lg" @click="clearFilters">Reset</button>
        <button class="px-3 py-1.5 text-sm bg-gray-800 text-white rounded-lg" @click="load">Terapkan</button>
      </div>
    </div>

    <div v-if="loading" class="text-center text-gray-400 py-12">Memuat...</div>
    <div v-else-if="!displayedItems.length" class="bg-white rounded-xl border border-gray-200 text-center text-gray-500 py-12">Belum ada struktur biaya.</div>
    <div v-else class="bg-white rounded-xl border border-gray-200 overflow-x-auto">
      <table class="w-full text-sm">
        <thead><tr class="bg-gray-50 text-left text-xs text-gray-500 uppercase tracking-wide">
          <th class="px-4 py-3">Jenis Tagihan</th><th class="px-4 py-3">Program Studi</th><th class="px-4 py-3">Tahun Akademik</th><th class="px-4 py-3 text-center">Tingkat</th><th class="px-4 py-3 text-center">Kelas</th><th class="px-4 py-3 text-right">Nominal</th><th class="px-4 py-3">Catatan</th><th class="px-4 py-3 text-right">Aksi</th>
        </tr></thead>
        <tbody><tr v-for="item in displayedItems" :key="item.id" class="border-t border-gray-100 hover:bg-gray-50">
          <td class="px-4 py-3 font-medium text-gray-900">{{ item.fee_type?.name ?? '-' }}</td>
          <td class="px-4 py-3 text-gray-600">{{ item.study_program?.name ?? 'Semua Program Studi' }}</td>
          <td class="px-4 py-3 text-gray-600">{{ item.academic_year?.name ?? 'Semua Tahun' }}</td>
          <td class="px-4 py-3 text-center text-gray-600">{{ item.academic_level ? `Tingkat ${item.academic_level}` : 'Semua' }}</td>
          <td class="px-4 py-3 text-center text-gray-600">{{ item.class_type ?? 'Semua' }}</td>
          <td class="px-4 py-3 text-right font-medium text-gray-900">{{ formatCurrency(item.amount) }}</td>
          <td class="px-4 py-3 text-gray-500 max-w-xs truncate">{{ item.note || '-' }}</td>
          <td class="px-4 py-3"><div class="flex justify-end gap-1"><button class="p-1.5 rounded-lg text-blue-600 hover:bg-blue-50" @click="openEdit(item)"><PencilIcon class="w-4 h-4" /></button><button class="p-1.5 rounded-lg text-red-500 hover:bg-red-50" @click="handleDelete(item)"><TrashIcon class="w-4 h-4" /></button></div></td>
        </tr></tbody>
      </table>
    </div>
  </div>

  <BaseModal :open="modalOpen" :title="editingId ? 'Edit Struktur Biaya' : 'Tambah Struktur Biaya'" @close="modalOpen = false">
    <form class="space-y-4" @submit.prevent="handleSave">
      <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
        <label :class="fieldClass">Jenis Tagihan <span class="text-red-500">*</span><select v-model="form.fee_type_id" required :class="inputClass"><option value="">Pilih jenis</option><option v-for="item in feeTypes" :key="item.id" :value="item.id">{{ item.name }}</option></select></label>
        <label :class="fieldClass">Program Studi<select v-model="form.study_program_id" :class="inputClass"><option value="">Semua program studi</option><option v-for="item in studyPrograms" :key="item.id" :value="item.id">{{ item.name }}</option></select></label>
        <label :class="fieldClass">Tahun Akademik<select v-model="form.academic_year_id" :class="inputClass"><option value="">Semua tahun akademik</option><option v-for="item in academicYears" :key="item.id" :value="item.id">{{ item.name }}</option></select></label>
        <label :class="fieldClass">Tingkat<select v-model="form.academic_level" :class="inputClass"><option value="">Semua tingkat</option><option v-for="level in levels" :key="level" :value="level">{{ level }}</option></select></label>
        <label :class="fieldClass">Kelas SPP<select v-model="form.class_type" :class="inputClass"><option value="">Semua kelas</option><option value="REGULER">Reguler</option><option value="KHUSUS">Khusus</option><option value="HYBRID">Hybrid</option></select><span class="text-xs text-gray-400">Dipakai khusus untuk SPP.</span></label>
        <label :class="fieldClass">Nominal <span class="text-red-500">*</span><input v-model="form.amount" required min="0" type="number" :class="inputClass" placeholder="0" /></label>
      </div>
      <label :class="fieldClass">Catatan<textarea v-model="form.note" rows="2" :class="inputClass" /></label>
    </form>
    <template #footer><button class="px-4 py-2 text-sm text-gray-600 hover:bg-gray-100 rounded-lg" @click="modalOpen = false">Batal</button><button :disabled="saving" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 disabled:bg-blue-400 text-white text-sm font-medium rounded-lg" @click="handleSave">{{ saving ? 'Menyimpan...' : 'Simpan' }}</button></template>
  </BaseModal>
</template>
