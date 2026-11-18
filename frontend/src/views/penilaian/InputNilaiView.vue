<script setup lang="ts">
import { ref, onMounted, computed } from 'vue'
import { useRoute } from 'vue-router'
import { useToast } from 'vue-toastification'
import { ArrowUpTrayIcon, ArrowDownTrayIcon } from '@heroicons/vue/24/outline'
import BaseModal from '@/components/ui/BaseModal.vue'
import api from '@/services/api'
import { useAuthStore } from '@/stores/auth'

const toast = useToast()
const auth = useAuthStore()
const route = useRoute()
const loading = ref(false)
const saving = ref(false)
const canEdit = computed(() => auth.hasRole('SUPER_ADMIN') || auth.hasRole('ADMIN_AKADEMIK') || auth.hasRole('DOSEN'))

// Filters
const semesters = ref<any[]>([])
const classes = ref<any[]>([])
const filterSemester = ref('')
const filterClass = ref('')

// Data
const grades = ref<any[]>([])
const schema = ref<any>(null)
const classMembers = ref<any[]>([])
const selectedClass = ref<any>(null)

// Components template
const components = ref<any[]>([])

onMounted(async () => {
  const [semRes, schemaRes] = await Promise.all([
    api.get('/semesters', { params: { per_page: 50 } }),
    api.get('/grades/schema'),
  ])
  semesters.value = semRes.data.data ?? semRes.data
  schema.value = schemaRes.data
  const requestedSemester = String(route.query.semester_id ?? '')
  const requestedClass = String(route.query.class_id ?? '')
  const active = semesters.value.find((s: any) => s.is_active)
  filterSemester.value = requestedSemester || active?.id || ''
  if (filterSemester.value) {
    await loadClasses()
    if (requestedClass && classes.value.some((c: any) => String(c.id) === requestedClass)) {
      filterClass.value = requestedClass
      await loadGrades()
    }
  }
})

async function loadClasses() {
  if (!filterSemester.value) return
  const { data } = await api.get('/classes/all', {
    params: { semester_id: filterSemester.value, for_grade_input: true },
  })
  classes.value = data
}

async function loadGrades() {
  if (!filterClass.value) return
  loading.value = true
  selectedClass.value = classes.value.find((c: any) => c.id == filterClass.value)
  try {
    const [gradeResponse, classResponse, gradebookResponse] = await Promise.all([
      api.get('/grades', { params: { class_id: filterClass.value, semester_id: filterSemester.value, per_page: 100 } }),
      api.get(`/classes/${filterClass.value}`),
      api.get(`/lectures/${filterClass.value}/gradebook`),
    ])
    const existing = gradeResponse.data.data ?? gradeResponse.data
    const gradebook = gradebookResponse.data
    components.value = gradebook.components
    const classRes = classResponse
    classMembers.value = classRes.data.members ?? []

    grades.value = classMembers.value.map((m: any) => {
      const existingGrade = existing.find((g: any) => Number(g.student_id) === Number(m.student_id))
      const gradebookStudent = gradebook.students.find((g: any) => Number(g.id) === Number(m.student_id))
      const savedComponents = existingGrade?.components ?? []
      return {
        student_id: m.student_id,
        student: m.student,
        is_provisional: gradebookStudent?.is_provisional ?? true,
        components: components.value.map((component: any) => {
          const saved = savedComponents.find((item: any) => item.key === component.key || item.name === component.name)
          return {
            ...component,
            score: saved?.score ?? (!existingGrade ? gradebookStudent?.component_scores?.[component.key] : null) ?? null,
          }
        }),
        final_score: existingGrade?.final_score ?? null,
        letter_grade: existingGrade?.letter_grade ?? '-',
        grade_point: existingGrade?.grade_point ?? 0,
      }
    })
    grades.value.forEach((grade: any) => {
      if (grade.components.every((component: any) => component.score !== null && component.score !== '' && Number.isFinite(Number(component.score)))) {
        calculateRow(grade)
      }
    })
  } catch (e: any) {
    toast.error(e?.response?.data?.message ?? 'Gagal memuat nilai dan komponen Gradebook.')
  } finally { loading.value = false }
}

const totalWeight = computed(() => components.value.reduce((s, c) => s + Number(c.weight), 0))

function calculateRow(row: any) {
  const score = row.components.reduce((s: number, c: any) => s + (Number(c.score) * Number(c.weight)) / 100, 0)
  row.final_score = Math.round(score * 100) / 100
  // Convert using schema
  if (schema.value?.details) {
    const detail = schema.value.details.find((d: any) => row.final_score >= d.min_score && row.final_score <= d.max_score)
    row.letter_grade = detail?.letter ?? '-'
    row.grade_point = detail?.grade_point ?? 0
  }
}

async function saveAll() {
  if (totalWeight.value !== 100) { toast.error('Total bobot harus 100%.'); return }
  if (grades.value.some(g => g.components.some((c: any) => c.score === null || c.score === '' || !Number.isFinite(Number(c.score)) || Number(c.score) < 0 || Number(c.score) > 100))) {
    toast.error('Lengkapi nilai setiap komponen dengan angka 0–100 sebelum menyimpan nilai akhir.')
    return
  }
  saving.value = true
  try {
    const payload = grades.value.map(g => ({
      student_id: g.student_id,
      course_id: selectedClass.value.course?.id,
      class_id: Number(filterClass.value),
      semester_id: Number(filterSemester.value),
      components: g.components,
    }))
    await api.post('/grades/batch', { grades: payload })
    toast.success('Nilai berhasil disimpan.')
    loadGrades()
  } catch (e: any) { toast.error(e?.response?.data?.message ?? 'Gagal menyimpan.') }
  finally { saving.value = false }
}

// === IMPORT EXCEL ===
const importModal   = ref(false)
const importFile    = ref<File | null>(null)
const importing     = ref(false)
const importResult  = ref<any>(null)

function openImport() {
  if (!filterClass.value) { toast.warning('Pilih kelas terlebih dahulu.'); return }
  if (totalWeight.value !== 100) { toast.warning('Atur komponen penilaian (total 100%) sebelum import.'); return }
  importFile.value = null
  importResult.value = null
  importModal.value = true
}

function onImportFile(e: Event) {
  const f = (e.target as HTMLInputElement).files?.[0]
  importFile.value = f ?? null
  importResult.value = null
}

async function downloadTemplate() {
  if (!filterClass.value) return
  try {
    const resp = await api.get('/grades/template-class', {
      params: {
        class_id: filterClass.value,
        semester_id: filterSemester.value,
        components: JSON.stringify(components.value),
      },
      responseType: 'blob',
    })
    const url = URL.createObjectURL(resp.data)
    const a = document.createElement('a')
    a.href = url
    a.download = `template-nilai-${selectedClass.value?.name ?? 'kelas'}.xlsx`
    a.click()
    URL.revokeObjectURL(url)
  } catch { toast.error('Gagal download template.') }
}

async function doImport() {
  if (!importFile.value) { toast.warning('Pilih file Excel.'); return }
  importing.value = true
  importResult.value = null
  try {
    const fd = new FormData()
    fd.append('file', importFile.value)
    fd.append('class_id', String(filterClass.value))
    fd.append('semester_id', String(filterSemester.value))
    fd.append('components', JSON.stringify(components.value))
    const { data } = await api.post('/grades/import-class', fd, {
      headers: { 'Content-Type': 'multipart/form-data' },
    })
    importResult.value = data
    toast.success(data.message)
    importModal.value = false
    loadGrades()
  } catch (e: any) {
    toast.error(e?.response?.data?.message ?? 'Gagal import.')
  } finally { importing.value = false }
}
</script>

<template>
  <div class="space-y-5">
    <div>
      <h1 class="text-xl font-bold text-gray-900">Input Nilai Akhir</h1>
      <p class="text-sm text-gray-500 mt-0.5">Tinjau nilai aktivitas dari Gradebook, lengkapi bila perlu, lalu simpan sebagai nilai resmi untuk KHS dan transkrip.</p>
    </div>

    <!-- Filters -->
    <div class="flex flex-wrap gap-3 items-end">
      <div>
        <label class="block text-xs font-medium text-gray-700 mb-1">Semester</label>
        <select v-model="filterSemester" class="px-3 py-2 rounded-lg border border-gray-300 text-sm" @change="loadClasses(); filterClass = ''">
          <option value="">-- Pilih Semester --</option>
          <option v-for="s in semesters" :key="s.id" :value="s.id">{{ s.name }}</option>
        </select>
      </div>
      <div>
        <label class="block text-xs font-medium text-gray-700 mb-1">Kelas</label>
        <select v-model="filterClass" class="px-3 py-2 rounded-lg border border-gray-300 text-sm" @change="loadGrades">
          <option value="">-- Pilih Kelas --</option>
          <option v-for="c in classes" :key="c.id" :value="c.id">{{ c.course?.name }} — {{ c.name }} ({{ c.members_count }} mhs)</option>
        </select>
      </div>
    </div>

    <!-- Komponen nilai mengikuti konfigurasi Gradebook kelas -->
    <div v-if="filterClass" class="bg-white rounded-xl border border-gray-200 p-4">
      <div class="flex items-center justify-between mb-3">
        <h3 class="text-sm font-semibold text-gray-800">Komponen Penilaian Kelas</h3>
        <span :class="['text-xs font-bold', totalWeight === 100 ? 'text-green-600' : 'text-red-600']">Total: {{ totalWeight }}%</span>
      </div>
      <div class="flex flex-wrap gap-2">
        <div v-for="component in components" :key="component.key" class="flex items-center gap-1 rounded-lg bg-gray-50 px-2 py-1">
          <span class="text-xs font-medium text-gray-700">{{ component.name }}</span>
          <span class="text-xs text-gray-500">{{ component.weight }}%</span>
          <span v-if="component.key === 'tasks'" class="text-[10px] text-blue-600">termasuk Quiz</span>
        </div>
      </div>
      <p class="mt-2 text-[11px] text-gray-500">Nama komponen dan bobot diatur dari tab Gradebook. Nilai yang sudah tersedia akan terisi otomatis; komponen yang belum dinilai perlu dilengkapi sebelum disahkan.</p>
    </div>

    <!-- Grade Table -->
    <div v-if="loading" class="text-center py-8 text-gray-400">Memuat data...</div>
    <div v-else-if="grades.length" class="bg-white rounded-xl border border-gray-200 overflow-x-auto">
      <table class="w-full text-sm">
        <thead>
          <tr class="bg-gray-50 text-left text-xs text-gray-500 uppercase">
            <th class="px-3 py-2 sticky left-0 bg-gray-50">Mahasiswa</th>
            <th v-for="c in components" :key="c.key" class="px-2 py-2 text-center min-w-[70px]">{{ c.name }}<br><span class="text-gray-400 font-normal">({{ c.weight }}%)</span></th>
            <th class="px-2 py-2 text-center">Akhir</th>
            <th class="px-2 py-2 text-center">Huruf</th>
            <th class="px-2 py-2 text-center">Bobot</th>
            <th class="px-2 py-2 text-center">Status</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="g in grades" :key="g.student_id" class="border-t border-gray-100 hover:bg-blue-50/30">
            <td class="px-3 py-2 sticky left-0 bg-white">
              <p class="font-medium text-gray-900 text-xs">{{ g.student?.name }}</p>
              <p class="text-[10px] text-gray-500">{{ g.student?.nim }}</p>
            </td>
            <td v-for="(c, ci) in g.components" :key="ci" class="px-1 py-1 text-center">
              <input v-model.number="c.score" :disabled="!canEdit" type="number" min="0" max="100" class="w-14 px-1 py-1 border rounded text-xs text-center focus:ring-1 focus:ring-blue-500" @input="calculateRow(g)" />
            </td>
            <td class="px-2 py-2 text-center font-bold text-sm" :class="g.final_score >= 55 ? 'text-green-700' : 'text-red-600'">{{ g.final_score }}</td>
            <td class="px-2 py-2 text-center font-bold text-sm">{{ g.letter_grade }}</td>
            <td class="px-2 py-2 text-center text-gray-600">{{ g.grade_point }}</td>
            <td class="px-2 py-2 text-center text-[10px]">
              <span v-if="g.is_provisional" class="text-amber-600">Belum lengkap</span>
              <span v-else class="text-emerald-700">Siap disahkan</span>
            </td>
          </tr>
        </tbody>
      </table>
    </div>
    <div v-else-if="filterClass" class="text-center py-8 text-gray-400 text-sm">Tidak ada mahasiswa di kelas ini.</div>

    <!-- Save -->
    <div v-if="grades.length" class="flex justify-end gap-2">
      <button
        v-if="canEdit && filterClass"
        class="flex items-center gap-1.5 px-4 py-2.5 bg-green-600 hover:bg-green-700 text-white font-medium rounded-lg text-sm"
        @click="openImport">
        <ArrowUpTrayIcon class="w-4 h-4" /> Import Excel
      </button>
      <button v-if="canEdit" :disabled="saving || totalWeight !== 100 || grades.some(g => g.components.some((c: any) => c.score === null || c.score === ''))" class="px-6 py-2.5 bg-blue-600 hover:bg-blue-700 disabled:bg-blue-400 text-white font-medium rounded-lg text-sm" @click="saveAll">
        {{ saving ? 'Menyimpan...' : 'Sahkan Nilai Akhir' }}
      </button>
    </div>

    <!-- Schema Reference -->
    <div v-if="schema?.details?.length" class="bg-white rounded-xl border border-gray-200 p-4">
      <h3 class="text-xs font-semibold text-gray-600 uppercase mb-2">Skema Nilai: {{ schema.name }}</h3>
      <div class="flex flex-wrap gap-2">
        <span v-for="d in schema.details" :key="d.id" class="text-xs px-2 py-1 bg-gray-50 rounded border border-gray-200">
          {{ d.letter }} ({{ d.min_score }}–{{ d.max_score }}) = {{ d.grade_point }}
        </span>
      </div>
    </div>
  </div>

  <!-- Modal Import Excel -->
  <BaseModal :open="importModal" title="Import Nilai dari Excel" @close="importModal = false">
    <div class="space-y-4">
      <!-- Info komponen -->
      <div class="p-3 bg-blue-50 border border-blue-200 rounded-lg text-xs text-blue-700">
        <p class="font-semibold mb-1">Komponen nilai yang akan diimport:</p>
        <div class="flex flex-wrap gap-2 mt-1">
          <span v-for="c in components" :key="c.name"
            class="px-2 py-0.5 bg-white border border-blue-200 rounded text-blue-700 font-mono">
            {{ c.name.toLowerCase().replace(/[\s-]/g, '_') }} ({{ c.weight }}%)
          </span>
        </div>
        <p class="mt-2 text-blue-600">Nama kolom di Excel harus sesuai dengan yang di atas.</p>
      </div>

      <!-- Download template -->
      <button
        class="w-full flex items-center justify-center gap-2 px-4 py-2.5 border-2 border-dashed border-gray-300 hover:border-blue-400 hover:bg-blue-50 text-gray-600 hover:text-blue-600 text-sm rounded-lg transition-colors"
        @click="downloadTemplate">
        <ArrowDownTrayIcon class="w-4 h-4" />
        Download Template Excel (sudah terisi nama mahasiswa)
      </button>

      <!-- Upload file -->
      <div>
        <label class="text-xs font-medium text-gray-700 block mb-1">Pilih File Excel</label>
        <input
          type="file" accept=".xlsx,.xls,.csv"
          class="w-full text-sm text-gray-600 file:mr-3 file:py-2 file:px-3 file:rounded-lg file:border-0 file:text-xs file:font-medium file:bg-blue-50 file:text-blue-700 hover:file:bg-blue-100 border border-gray-200 rounded-lg cursor-pointer"
          @change="onImportFile" />
        <p class="text-xs text-gray-400 mt-1">Format: .xlsx, .xls, .csv — Maks 10MB</p>
      </div>

      <!-- Hasil import -->
      <div v-if="importResult" :class="['p-3 rounded-lg text-sm', importResult.errors?.length ? 'bg-yellow-50 border border-yellow-200' : 'bg-green-50 border border-green-200']">
        <p :class="importResult.errors?.length ? 'font-medium text-yellow-800' : 'font-medium text-green-800'">
          {{ importResult.message }}
        </p>
        <div v-if="importResult.errors?.length" class="mt-2 space-y-0.5">
          <p class="text-xs text-red-600 font-medium">Detail error:</p>
          <p v-for="(err, i) in importResult.errors" :key="i" class="text-xs text-red-500">{{ err }}</p>
        </div>
      </div>
    </div>

    <template #footer>
      <button class="px-4 py-2 text-sm text-gray-600 hover:bg-gray-100 rounded-lg" @click="importModal = false">Tutup</button>
      <button
        :disabled="importing || !importFile"
        class="px-4 py-2 bg-green-600 hover:bg-green-700 disabled:bg-green-300 text-white text-sm font-medium rounded-lg flex items-center gap-1.5"
        @click="doImport">
        <ArrowUpTrayIcon class="w-4 h-4" />
        {{ importing ? 'Mengimport...' : 'Import Nilai' }}
      </button>
    </template>
  </BaseModal>
</template>
