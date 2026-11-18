<script setup lang="ts">
import { ref, reactive, computed, onMounted } from 'vue'
import { PlusIcon, PencilIcon, TrashIcon } from '@heroicons/vue/24/outline'
import { useCrud } from '@/composables/useCrud'
import { useExcel } from '@/composables/useExcel'
import DataTable from '@/components/ui/DataTable.vue'
import BaseModal from '@/components/ui/BaseModal.vue'
import ExcelButtons from '@/components/ui/ExcelButtons.vue'
import api from '@/services/api'

interface StudyProgram { id: number; name: string; code: string }
interface Course {
  id: number; study_program_id?: number; code: string; name: string; credits: number
  course_group?: string | null; theory_credits?: number; practical_credits?: number
  semester: number; type: string; status: boolean; study_program?: StudyProgram
  delivery_mode?: 'TATAP_MUKA' | 'NON_TATAP_MUKA'
}

const { items, pagination, loading, fetchAll, create, update, remove } = useCrud<Course>('/courses')
const { exporting, importing, importErrors, exportExcel, importExcel } = useExcel('/courses')
const programs = ref<StudyProgram[]>([])
const search = ref(''); const filterProgram = ref(''); const filterSemester = ref('')
const modalOpen = ref(false); const editingId = ref<number | null>(null); const saving = ref(false)
const form = reactive({
  study_program_id: '', code: '', name: '', course_group: '',
  theory_credits: 2, practical_credits: 0,
  semester: 1, type: 'Wajib', status: true,
  delivery_mode: 'TATAP_MUKA',
})
const totalCredits = computed(() => Number(form.theory_credits || 0) + Number(form.practical_credits || 0))

const typeColor: Record<string, string> = { Wajib: 'bg-blue-100 text-blue-700', Pilihan: 'bg-yellow-100 text-yellow-700', Praktikum: 'bg-green-100 text-green-700' }
const columns = [
  { key: 'code', label: 'Kode' }, { key: 'name', label: 'Mata Kuliah' }, { key: 'program', label: 'Prodi' },
  { key: 'credits', label: 'SKS' }, { key: 'semester', label: 'Semester' }, { key: 'type', label: 'Jenis' }, { key: 'delivery_mode', label: 'Pelaksanaan' },
  { key: 'status', label: 'Status' }, { key: 'aksi', label: 'Aksi', class: 'text-right' },
]

onMounted(async () => {
  load()
  const { data } = await api.get('/study-programs/all')
  programs.value = data
})

async function load(page = 1) { await fetchAll({ search: search.value, study_program_id: filterProgram.value, semester: filterSemester.value, page }) }

function openCreate() {
  editingId.value = null
  Object.assign(form, { study_program_id: '', code: '', name: '', course_group: '', theory_credits: 2, practical_credits: 0, semester: 1, type: 'Wajib', delivery_mode: 'TATAP_MUKA', status: true })
  modalOpen.value = true
}

function openEdit(item: Course) {
  editingId.value = item.id
  const practicalCredits = Number(item.practical_credits ?? 0)
  const theoryCredits = Number(item.theory_credits ?? 0) || (practicalCredits ? 0 : item.credits)
  Object.assign(form, {
    study_program_id: item.study_program_id ?? item.study_program?.id ?? '', code: item.code, name: item.name,
    course_group: item.course_group ?? '', theory_credits: theoryCredits, practical_credits: practicalCredits,
    semester: Number(item.semester ?? 1), type: item.type, delivery_mode: item.delivery_mode ?? 'TATAP_MUKA', status: item.status,
  })
  modalOpen.value = true
}

async function handleSave() {
  if (totalCredits.value < 1 || totalCredits.value > 6) return
  saving.value = true
  try {
    const payload = { ...form, credits: totalCredits.value }
    editingId.value ? await update(editingId.value, payload) : await create(payload)
    modalOpen.value = false; load()
  } catch { } finally { saving.value = false }
}

async function handleDelete(item: Course) {
  if (!confirm(`Hapus mata kuliah "${item.name}"?`)) return
  await remove(item.id); load()
}
</script>

<template>
  <div class="space-y-5">
    <div class="flex items-center justify-between">
      <div>
        <h1 class="text-xl font-bold text-gray-900">Mata Kuliah</h1>
        <p class="text-sm text-gray-500 mt-0.5">Kelola daftar mata kuliah</p>
      </div>
      <div class="flex items-center gap-2">
        <ExcelButtons
          :exporting="exporting"
          :importing="importing"
          :import-errors="importErrors"
          :export-params="{ study_program_id: filterProgram, semester: filterSemester }"
          template-type="courses"
          @export="exportExcel($event, 'mata-kuliah.xlsx')"
          @import="importExcel($event, () => load())"
        />
        <button class="flex items-center gap-2 px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium rounded-lg transition-colors" @click="openCreate">
          <PlusIcon class="w-4 h-4" /> Tambah MK
        </button>
      </div>
    </div>
    <div class="flex flex-wrap gap-3">
      <input v-model="search" type="text" placeholder="Cari kode atau nama..." class="px-3.5 py-2 rounded-lg border border-gray-300 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 w-56" @input="load()" />
      <select v-model="filterProgram" class="px-3.5 py-2 rounded-lg border border-gray-300 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500" @change="load()">
        <option value="">Semua Prodi</option>
        <option v-for="p in programs" :key="p.id" :value="p.id">{{ p.code }} - {{ p.name }}</option>
      </select>
      <select v-model="filterSemester" class="px-3.5 py-2 rounded-lg border border-gray-300 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500" @change="load()">
        <option value="">Semua Semester</option>
        <option v-for="s in 8" :key="s" :value="s">Semester {{ s }}</option>
      </select>
    </div>
    <ExcelButtons
      class="sm:hidden"
      :exporting="exporting"
      :importing="importing"
      :import-errors="importErrors"
      :export-params="{ study_program_id: filterProgram, semester: filterSemester }"
      @export="exportExcel($event, 'mata-kuliah.xlsx')"
      @import="importExcel($event, () => load())"
    />

    <DataTable :columns="columns" :rows="items" :loading="loading" :total="pagination.total" :current-page="pagination.currentPage" :last-page="pagination.lastPage" @page-change="load">
      <template #default="{ row }">
        <td class="px-4 py-3 font-mono text-xs text-gray-600">{{ row.code }}</td>
        <td class="px-4 py-3 font-medium text-gray-900">{{ row.name }}</td>
        <td class="px-4 py-3 text-gray-600 text-xs">{{ row.study_program?.code ?? '-' }}</td>
        <td class="px-4 py-3 text-center font-medium text-gray-700">
          <span>{{ row.credits }}</span>
          <span class="block text-[10px] font-normal text-gray-400">T {{ row.theory_credits ?? row.credits }} · P {{ row.practical_credits ?? 0 }}</span>
        </td>
        <td class="px-4 py-3 text-center text-gray-700">{{ row.semester }}</td>
        <td class="px-4 py-3"><span :class="['inline-flex px-2 py-0.5 rounded-full text-xs font-medium', typeColor[row.type]]">{{ row.type }}</span></td>
        <td class="px-4 py-3 text-xs">{{ row.delivery_mode === 'NON_TATAP_MUKA' ? 'Non-tatap muka' : 'Tatap muka' }}</td>
        <td class="px-4 py-3"><span :class="['inline-flex px-2 py-0.5 rounded-full text-xs font-medium', row.status ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-500']">{{ row.status ? 'Aktif' : 'Nonaktif' }}</span></td>
        <td class="px-4 py-3">
          <div class="flex items-center justify-end gap-1">
            <button class="p-1.5 rounded-lg text-blue-600 hover:bg-blue-50" @click="openEdit(row)"><PencilIcon class="w-4 h-4" /></button>
            <button class="p-1.5 rounded-lg text-red-500 hover:bg-red-50" @click="handleDelete(row)"><TrashIcon class="w-4 h-4" /></button>
          </div>
        </td>
      </template>
    </DataTable>
  </div>

  <BaseModal :open="modalOpen" :title="editingId ? 'Edit Mata Kuliah' : 'Tambah Mata Kuliah'" size="lg" @close="modalOpen = false">
    <form class="space-y-4" @submit.prevent="handleSave">
      <div>
        <label class="block text-sm font-medium text-gray-700 mb-1">Program Studi <span class="text-red-500">*</span></label>
        <select v-model="form.study_program_id" required class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
          <option value="">-- Pilih Program Studi --</option>
          <option v-for="p in programs" :key="p.id" :value="p.id">{{ p.code }} - {{ p.name }}</option>
        </select>
      </div>
      <div class="grid grid-cols-2 gap-4">
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Kode <span class="text-red-500">*</span></label>
          <input v-model="form.code" required class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-blue-500" />
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Jenis</label>
          <select v-model="form.type" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
            <option v-for="t in ['Wajib','Pilihan','Praktikum']" :key="t" :value="t">{{ t }}</option>
          </select>
        </div>
      </div>
      <div>
        <label class="block text-sm font-medium text-gray-700 mb-1">Pelaksanaan</label>
        <select v-model="form.delivery_mode" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm">
          <option value="TATAP_MUKA">Tatap muka (perlu dosen, jadwal, dan ruangan)</option>
          <option value="NON_TATAP_MUKA">Non-tatap muka (tanpa dosen, jadwal, dan ruangan)</option>
        </select>
      </div>
      <div>
        <label class="block text-sm font-medium text-gray-700 mb-1">Nama Mata Kuliah <span class="text-red-500">*</span></label>
        <input v-model="form.name" required class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-blue-500" />
      </div>
      <div>
        <label class="block text-sm font-medium text-gray-700 mb-1">Rumpun Mata Kuliah</label>
        <input v-model="form.course_group" maxlength="150" placeholder="Contoh: Mata Kuliah Keahlian Program Studi" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-blue-500" />
      </div>
      <div class="grid grid-cols-2 md:grid-cols-5 gap-4">
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">SKS Teori</label>
          <input v-model.number="form.theory_credits" type="number" min="0" max="6" required class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-blue-500" />
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">SKS Praktik</label>
          <input v-model.number="form.practical_credits" type="number" min="0" max="6" required class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-blue-500" />
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Total SKS</label>
          <input :value="totalCredits" readonly class="w-full px-3 py-2 border border-gray-200 bg-gray-50 rounded-lg text-sm font-semibold text-center" />
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Semester</label>
          <input v-model="form.semester" type="number" min="1" max="8" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-blue-500" />
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Status</label>
          <select v-model="form.status" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
            <option :value="true">Aktif</option><option :value="false">Nonaktif</option>
          </select>
        </div>
      </div>
      <p v-if="totalCredits < 1 || totalCredits > 6" class="text-xs text-red-600">Jumlah SKS teori dan praktik harus antara 1 sampai 6.</p>
    </form>
    <template #footer>
      <button class="px-4 py-2 text-sm text-gray-600 hover:bg-gray-100 rounded-lg" @click="modalOpen = false">Batal</button>
      <button :disabled="saving || totalCredits < 1 || totalCredits > 6" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 disabled:bg-blue-400 text-white text-sm font-medium rounded-lg" @click="handleSave">{{ saving ? 'Menyimpan...' : 'Simpan' }}</button>
    </template>
  </BaseModal>
</template>
