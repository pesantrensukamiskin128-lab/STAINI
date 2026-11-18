<script setup lang="ts">
import { ref, reactive, onMounted, computed } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { ArrowLeftIcon, CheckIcon, XMarkIcon, LockClosedIcon, PlusIcon, TrashIcon, PencilIcon, ArrowDownTrayIcon, ArrowUpTrayIcon } from '@heroicons/vue/24/outline'
import { useToast } from 'vue-toastification'
import { useAuthStore } from '@/stores/auth'
import BaseModal from '@/components/ui/BaseModal.vue'
import api from '@/services/api'

const route  = useRoute()
const router = useRouter()
const toast  = useToast()
const auth   = useAuthStore()

const rpkps   = ref<any>(null)
const loading = ref(true)
const saving  = ref(false)
const activeTab = ref('cpl')

const rejectModalOpen = ref(false)
const rejectionNote   = ref('')
const downloadingPdf  = ref(false)
const importingExcel = ref(false)
const excelInput = ref<HTMLInputElement | null>(null)

async function downloadPdf() {
  downloadingPdf.value = true
  try {
    const res = await api.get(`/rpkps/${rpkps.value.id}/pdf`, { responseType: 'blob' })
    const url = URL.createObjectURL(new Blob([res.data], { type: 'application/pdf' }))
    const link = document.createElement('a')
    link.href = url
    link.download = `RPKPS-${rpkps.value.course?.code ?? ''}-${rpkps.value.code}.pdf`
    document.body.appendChild(link); link.click(); link.remove()
    URL.revokeObjectURL(url)
  } catch { toast.error('Gagal download PDF.') }
  finally { downloadingPdf.value = false }
}

async function downloadExcel(url: string, filename: string) {
  try {
    const res = await api.get(url, { responseType: 'blob' })
    const link = document.createElement('a')
    link.href = URL.createObjectURL(res.data); link.download = filename
    document.body.appendChild(link); link.click(); link.remove(); URL.revokeObjectURL(link.href)
  } catch { toast.error('Gagal mengunduh Excel.') }
}

async function importExcel(event: Event) {
  const file = (event.target as HTMLInputElement).files?.[0]
  if (!file) return
  importingExcel.value = true
  try {
    const body = new FormData(); body.append('file', file)
    await api.post(`/rpkps/${rpkps.value.id}/import-excel`, body, { headers: { 'Content-Type': 'multipart/form-data' } })
    toast.success('RPKPS berhasil diimport. Baris yang tidak disertakan tetap dipertahankan.')
    await load()
  } catch (e: any) { toast.error(e?.response?.data?.message ?? 'Gagal import Excel.') }
  finally { importingExcel.value = false; (event.target as HTMLInputElement).value = '' }
}
function openExcelImport() { excelInput.value?.click() }

// Dropdown data
const availableCpls = ref<any[]>([])
const curriculumCourses = ref<any[]>([])  // MK dalam kurikulum untuk dropdown prasyarat
const tabs = [
  { key: 'cpl', label: 'CPL' },
  { key: 'cpmk', label: 'CPMK' },
  { key: 'deskripsi', label: 'Deskripsi & Bahan Kajian' },
  { key: 'mingguan', label: 'Rencana Mingguan' },
  { key: 'asesmen', label: 'Asesmen' },
  { key: 'instrumen', label: 'Instrumen & Rubrik' },
  { key: 'referensi', label: 'Referensi' },
  { key: 'riwayat', label: 'Riwayat' },
]

const statusColor: Record<string, string> = {
  DRAFT: 'bg-gray-100 text-gray-600', DIAJUKAN: 'bg-yellow-100 text-yellow-700',
  DALAM_PEMERIKSAAN: 'bg-blue-100 text-blue-700', REVISI: 'bg-orange-100 text-orange-700',
  DISETUJUI: 'bg-green-100 text-green-700', DIKUNCI: 'bg-indigo-100 text-indigo-700',
}

const isEditable = computed(() =>
  ['DRAFT', 'REVISI'].includes(rpkps.value?.status)
  && (
    auth.hasRole(['SUPER_ADMIN', 'ADMIN_AKADEMIK'])
    || Number(rpkps.value?.lecturer_id) === Number(auth.user?.lecturer?.id)
  ),
)

/** CPL yang terpetakan ke mata kuliah ini dari kurikulum (via cpl_course_mappings) */
const mappedCpls = computed(() => {
  if (!rpkps.value?.course_id || !availableCpls.value.length) return []
  // Jika backend sudah return cpls di rpkps, gunakan itu
  // Kalau tidak, filter dari available CPLs yang punya mapping ke course ini
  if (rpkps.value.cpls?.length) return rpkps.value.cpls
  return []
})
const availableSubCpmks = computed(() =>
  (rpkps.value?.cpmks ?? []).flatMap((cpmk: any) => cpmk.sub_cpmks ?? []),
)

onMounted(load)

async function load() {
  loading.value = true
  try {
    const { data } = await api.get(`/rpkps/${route.params.id}`)
    rpkps.value = data
    const subCpmkCodes = new Set((data.cpmks ?? []).flatMap((cpmk: any) =>
      (cpmk.sub_cpmks ?? []).map((sub: any) => sub.code),
    ))
    rpkps.value.weekly_plans = (data.weekly_plans ?? []).map((plan: any) => {
      const methods = Array.isArray(plan.methods) ? plan.methods.join(' | ') : (plan.methods ?? '')
      if ([8, 16].includes(Number(plan.week_number))) {
        return {
          ...plan,
          methods,
          sub_cpmk: Number(plan.week_number) === 8 ? 'Evaluasi Tengah Semester' : 'Evaluasi Akhir Semester',
          sub_cpmk_codes: [],
          legacy_sub_cpmk_codes: [],
        }
      }
      const savedCodes = String(plan.sub_cpmk ?? '').split(/[|,;\r\n]+/).map((code: string) => code.trim()).filter(Boolean)
      return {
        ...plan,
        methods,
        sub_cpmk_codes: savedCodes.filter((code: string) => subCpmkCodes.has(code)),
        legacy_sub_cpmk_codes: savedCodes.filter((code: string) => !subCpmkCodes.has(code)),
      }
    })
    // Load CPL dan MK dari kurikulum
    if (data.curriculum_id) {
      const { data: cur } = await api.get(`/curriculums/${data.curriculum_id}`)
      availableCpls.value = cur.learning_outcomes ?? []
      curriculumCourses.value = (cur.curriculum_courses ?? []).map((cc: any) => cc.course).filter(Boolean)
    }
  } catch { toast.error('Gagal memuat RPKPS.') }
  finally { loading.value = false }
}

// === SAVE FUNCTIONS ===

async function saveDeskripsi() {
  saving.value = true
  try {
    await api.put(`/rpkps/${rpkps.value.id}`, {
      course_description: rpkps.value.course_description,
      course_scope: rpkps.value.course_scope,
      prerequisites: rpkps.value.prerequisites,
    })
    toast.success('Deskripsi berhasil disimpan.')
  } catch { toast.error('Gagal menyimpan.') }
  finally { saving.value = false }
}

async function syncCpls() {
  const ids = rpkps.value.cpls?.map((c: any) => c.id) ?? []
  saving.value = true
  try {
    await api.post(`/rpkps/${rpkps.value.id}/cpls`, { cpl_ids: ids })
    toast.success('CPL berhasil disimpan.')
  } catch { toast.error('Gagal menyimpan CPL.') }
  finally { saving.value = false }
}

function toggleCpl(cpl: any) {
  const idx = rpkps.value.cpls.findIndex((c: any) => c.id === cpl.id)
  if (idx >= 0) rpkps.value.cpls.splice(idx, 1)
  else rpkps.value.cpls.push(cpl)
}

function isCplSelected(id: number) {
  return rpkps.value?.cpls?.some((c: any) => c.id === id) ?? false
}

// CPMK
type SubCpmkForm = { id?: number; code: string; description: string }
const cpmkForm = reactive<{
  code: string
  description: string
  cpl_ids: number[]
  sub_cpmks: SubCpmkForm[]
}>({ code: '', description: '', cpl_ids: [], sub_cpmks: [] })
const cpmkModalOpen = ref(false)
const editingCpmkId = ref<number | null>(null)
const currentCpmkOrder = computed(() => {
  if (!editingCpmkId.value) return (rpkps.value?.cpmks?.length ?? 0) + 1
  const index = (rpkps.value?.cpmks ?? []).findIndex((cpmk: any) => cpmk.id === editingCpmkId.value)
  return index >= 0 ? index + 1 : 1
})

function openAddCpmk() {
  editingCpmkId.value = null
  Object.assign(cpmkForm, { code: '', description: '', cpl_ids: [], sub_cpmks: [] })
  cpmkModalOpen.value = true
}

function openEditCpmk(cpmk: any) {
  editingCpmkId.value = cpmk.id
  Object.assign(cpmkForm, {
    code: cpmk.code,
    description: cpmk.description,
    cpl_ids: (cpmk.cpls ?? []).map((cpl: any) => cpl.id),
    sub_cpmks: (cpmk.sub_cpmks ?? []).map((sub: any) => ({ id: sub.id, code: sub.code, description: sub.description })),
  })
  cpmkModalOpen.value = true
}

function addSubCpmk() {
  const next = cpmkForm.sub_cpmks.length + 1
  cpmkForm.sub_cpmks.push({ code: `Sub-${currentCpmkOrder.value}.${next}`, description: '' })
}

function removeSubCpmk(index: number) {
  cpmkForm.sub_cpmks.splice(index, 1)
  cpmkForm.sub_cpmks.forEach((sub, subIndex) => {
    sub.code = `Sub-${currentCpmkOrder.value}.${subIndex + 1}`
  })
}

async function saveCpmk() {
  saving.value = true
  try {
    if (editingCpmkId.value) {
      await api.put(`/rpkps/${rpkps.value.id}/cpmks/${editingCpmkId.value}`, cpmkForm)
      toast.success('CPMK berhasil diupdate.')
    } else {
      await api.post(`/rpkps/${rpkps.value.id}/cpmks`, cpmkForm)
      toast.success('CPMK berhasil ditambahkan.')
    }
    cpmkModalOpen.value = false
    Object.assign(cpmkForm, { code: '', description: '', cpl_ids: [], sub_cpmks: [] })
    editingCpmkId.value = null
    load()
  } catch (e: any) {
    toast.error(e?.response?.data?.errors?.sub_cpmks?.[0] ?? e?.response?.data?.message ?? 'Gagal menyimpan CPMK.')
  }
  finally { saving.value = false }
}

function toggleCpmkCpl(cplId: number) {
  const index = cpmkForm.cpl_ids.indexOf(cplId)
  if (index >= 0) cpmkForm.cpl_ids.splice(index, 1)
  else cpmkForm.cpl_ids.push(cplId)
}

async function deleteCpmk(id: number) {
  if (!confirm('Hapus CPMK ini?')) return
  try {
    await api.delete(`/rpkps/${rpkps.value.id}/cpmks/${id}`)
    toast.success('CPMK dihapus.')
    load()
  } catch (e: any) {
    toast.error(e?.response?.data?.errors?.cpmk?.[0] ?? e?.response?.data?.message ?? 'Gagal menghapus CPMK.')
  }
}

// Weekly Plans
function initWeeklyPlans() {
  if (!rpkps.value.weekly_plans?.length) {
    rpkps.value.weekly_plans = Array.from({ length: 16 }, (_, i) => ({
      week_number: i + 1,
      sub_cpmk: i + 1 === 8 ? 'Evaluasi Tengah Semester' : i + 1 === 16 ? 'Evaluasi Akhir Semester' : '',
      sub_cpmk_codes: [],
      legacy_sub_cpmk_codes: [],
      indicators: '', learning_material: '',
      methods: [], offline_learning: '', online_learning: '', duration: '',
      lecturer_activity: '', student_activity: '', assessment_form: '',
      assessment_criteria: '', media: '', weight: 0,
    }))
  }
}

async function saveWeeklyPlans() {
  saving.value = true
  try {
    // Convert methods dari string ke array; pipe adalah pemisah template, koma didukung untuk data lama.
    const plans = rpkps.value.weekly_plans.map((w: any) => ({
      ...w,
      sub_cpmk: [8, 16].includes(Number(w.week_number))
        ? (Number(w.week_number) === 8 ? 'Evaluasi Tengah Semester' : 'Evaluasi Akhir Semester')
        : (w.sub_cpmk_codes?.length
          ? w.sub_cpmk_codes.join('|')
          : w.legacy_sub_cpmk_codes?.length ? w.sub_cpmk : ''),
      sub_cpmk_codes: undefined,
      legacy_sub_cpmk_codes: undefined,
      methods: typeof w.methods === 'string'
        ? w.methods.split(w.methods.includes('|') ? /\s*\|\s*/ : /\s*,\s*/).map((m: string) => m.trim()).filter(Boolean)
        : (w.methods ?? []),
    }))
    await api.post(`/rpkps/${rpkps.value.id}/weekly-plans`, { plans })
    toast.success('Rencana mingguan berhasil disimpan.')
  } catch (e: any) { toast.error(e?.response?.data?.message ?? 'Gagal.') }
  finally { saving.value = false }
}

// Assessments
function initAssessments() {
  if (!rpkps.value.assessments?.length) {
    rpkps.value.assessments = [
      { name: 'Kehadiran & Partisipasi', weight: 10, description: '' },
      { name: 'Tugas', weight: 20, description: '' },
      { name: 'UTS', weight: 30, description: '' },
      { name: 'UAS', weight: 40, description: '' },
    ]
  }
  if (!rpkps.value.grade_scales?.length) {
    rpkps.value.grade_scales = [
      { min_score: 85, max_score: 100, letter: 'A', grade_point: 4 },
      { min_score: 70, max_score: 84.99, letter: 'B', grade_point: 3 },
      { min_score: 55, max_score: 69.99, letter: 'C', grade_point: 2 },
      { min_score: 40, max_score: 54.99, letter: 'D', grade_point: 1 },
      { min_score: 0, max_score: 39.99, letter: 'E', grade_point: 0 },
    ]
  }
}

function addAssessment() {
  rpkps.value.assessments.push({ name: '', weight: 0, description: '' })
}

function removeAssessment(idx: number) {
  rpkps.value.assessments.splice(idx, 1)
}

function addGradeScale() {
  if (!rpkps.value.grade_scales) rpkps.value.grade_scales = []
  rpkps.value.grade_scales.push({ min_score: 0, max_score: 0, letter: '', grade_point: 0 })
}

function removeGradeScale(idx: number) {
  rpkps.value.grade_scales.splice(idx, 1)
}

const totalWeight = computed(() =>
  (rpkps.value?.assessments ?? []).reduce((s: number, a: any) => s + (Number(a.weight) || 0), 0)
)

async function saveAssessments() {
  saving.value = true
  try {
    await api.post(`/rpkps/${rpkps.value.id}/assessments`, {
      assessments: rpkps.value.assessments,
      final_grade_formula: rpkps.value.final_grade_formula,
      grade_scales: rpkps.value.grade_scales ?? [],
    })
    toast.success('Komponen asesmen berhasil disimpan.')
  } catch (e: any) { toast.error(e?.response?.data?.message ?? 'Gagal.') }
  finally { saving.value = false }
}

// Assessment instruments and rubrics
function addInstrument() {
  if (!rpkps.value.assessment_instruments) rpkps.value.assessment_instruments = []
  rpkps.value.assessment_instruments.push({
    rpkps_assessment_id: null, sub_cpmk: '', instrument_name: '', instrument_type: 'Non-tes',
    technique: '', cognitive_level: '', affective_level: '', psychomotor_level: '',
    item_numbers: '', weight: 0, timing: '', description: '',
  })
}

function removeInstrument(idx: number) { rpkps.value.assessment_instruments.splice(idx, 1) }

function addRubric() {
  if (!rpkps.value.rubrics) rpkps.value.rubrics = []
  rpkps.value.rubrics.push({
    rpkps_assessment_id: null, title: '', criteria: '', weight: null,
    excellent: '', good: '', fair: '', poor: '', very_poor: '',
  })
}

function removeRubric(idx: number) { rpkps.value.rubrics.splice(idx, 1) }

async function saveEvaluationTools() {
  saving.value = true
  try {
    await api.post(`/rpkps/${rpkps.value.id}/evaluation-tools`, {
      instruments: rpkps.value.assessment_instruments ?? [],
      rubrics: rpkps.value.rubrics ?? [],
    })
    toast.success('Kisi-kisi instrumen dan rubrik berhasil disimpan.')
    await load()
  } catch (e: any) { toast.error(e?.response?.data?.message ?? 'Gagal.') }
  finally { saving.value = false }
}

// References
function initReferences() {
  if (!rpkps.value.references?.length) {
    rpkps.value.references = []
  }
}

function addReference() {
  rpkps.value.references.push({
    type: 'Utama', category: 'Buku', title: '',
    author: '', year: '', publisher: '', isbn_doi: '', url: '',
  })
}

function removeReference(idx: number) {
  rpkps.value.references.splice(idx, 1)
}

async function saveReferences() {
  saving.value = true
  try {
    await api.post(`/rpkps/${rpkps.value.id}/references`, {
      references: rpkps.value.references,
    })
    toast.success('Referensi berhasil disimpan.')
  } catch (e: any) { toast.error(e?.response?.data?.message ?? 'Gagal.') }
  finally { saving.value = false }
}

// Workflow
async function handleSubmit() {
  if (!confirm('Ajukan RPKPS untuk validasi Kaprodi?')) return
  try {
    await api.post(`/rpkps/${rpkps.value.id}/submit`)
    toast.success('RPKPS berhasil diajukan.')
    load()
  } catch (e: any) { toast.error(e?.response?.data?.message ?? 'Gagal.') }
}

async function handleApprove() {
  if (!confirm('Setujui RPKPS ini?')) return
  try {
    await api.post(`/rpkps/${rpkps.value.id}/approve`)
    toast.success('RPKPS disetujui.')
    load()
  } catch (e: any) { toast.error(e?.response?.data?.message ?? 'Gagal.') }
}

async function handleRevise() {
  if (!rejectionNote.value.trim()) { toast.warning('Catatan wajib diisi.'); return }
  try {
    await api.post(`/rpkps/${rpkps.value.id}/revise`, { note: rejectionNote.value })
    toast.success('RPKPS dikembalikan untuk revisi.')
    rejectModalOpen.value = false
    rejectionNote.value = ''
    load()
  } catch (e: any) { toast.error(e?.response?.data?.message ?? 'Gagal.') }
}

async function handleLock() {
  if (!confirm('Kunci RPKPS? Tidak bisa diubah lagi setelah dikunci.')) return
  try {
    await api.post(`/rpkps/${rpkps.value.id}/lock`)
    toast.success('RPKPS dikunci.')
    load()
  } catch (e: any) { toast.error(e?.response?.data?.message ?? 'Gagal.') }
}

const methodOptions = [
  'Ceramah','Diskusi','Studi Kasus','Problem-Based Learning',
  'Project-Based Learning','Small Group Discussion','Cooperative Learning',
  'Role Play','Simulasi','Seminar','Praktikum','Penelitian',
]
</script>

<template>
  <div v-if="loading" class="flex items-center justify-center h-64"><p class="text-gray-400">Memuat...</p></div>

  <div v-else-if="rpkps" class="space-y-5 max-w-5xl mx-auto">
    <!-- Header -->
    <div class="flex items-start gap-3">
      <button class="p-1.5 rounded-lg text-gray-500 hover:bg-gray-100" @click="router.back()">
        <ArrowLeftIcon class="w-5 h-5" />
      </button>
      <div class="flex-1 min-w-0">
        <div class="flex items-center gap-2 flex-wrap">
          <h1 class="text-lg font-bold text-gray-900 truncate">{{ rpkps.course?.name }}</h1>
          <span :class="['px-2 py-0.5 rounded-full text-xs font-medium', statusColor[rpkps.status]]">
            {{ rpkps.status?.replace(/_/g, ' ') }}
          </span>
        </div>
        <p class="text-xs text-gray-500 mt-0.5">
          {{ rpkps.code }} · {{ rpkps.lecturer?.name ?? rpkps.lecturer?.full_name }} · {{ rpkps.academic_year?.name }}
        </p>
      </div>
      <!-- Actions -->
      <div class="flex gap-2 shrink-0">
        <button class="px-3 py-1.5 bg-red-50 hover:bg-red-100 text-red-700 border border-red-200 text-xs font-medium rounded-lg" @click="downloadPdf">
          PDF
        </button>
        <button v-if="isEditable" class="px-3 py-1.5 bg-emerald-50 hover:bg-emerald-100 text-emerald-700 border border-emerald-200 text-xs font-medium rounded-lg" @click="downloadExcel(`/rpkps/${rpkps.id}/export-excel`, `RPKPS-${rpkps.code}.xlsx`)">
          <ArrowDownTrayIcon class="w-3.5 h-3.5 inline mr-0.5" /> Export Excel
        </button>
        <button v-if="isEditable" class="px-3 py-1.5 bg-sky-50 hover:bg-sky-100 text-sky-700 border border-sky-200 text-xs font-medium rounded-lg" @click="openExcelImport">
          <ArrowUpTrayIcon class="w-3.5 h-3.5 inline mr-0.5" /> Import Excel
        </button>
        <button v-if="isEditable" class="px-3 py-1.5 bg-gray-50 hover:bg-gray-100 text-gray-700 border border-gray-200 text-xs font-medium rounded-lg" @click="downloadExcel('/rpkps/template-excel', 'template-import-RPKPS.xlsx')">
          Template
        </button>
        <input ref="excelInput" type="file" accept=".xlsx,.xls" class="hidden" @change="importExcel" />
        <button v-if="isEditable" class="px-3 py-1.5 bg-blue-600 hover:bg-blue-700 text-white text-xs font-medium rounded-lg" @click="handleSubmit">
          Ajukan Validasi
        </button>
        <button v-if="rpkps.status === 'DIAJUKAN' && auth.hasPermission('rps.approve')" class="px-3 py-1.5 bg-green-600 hover:bg-green-700 text-white text-xs font-medium rounded-lg" @click="handleApprove">
          <CheckIcon class="w-3.5 h-3.5 inline mr-0.5" /> Setujui
        </button>
        <button v-if="rpkps.status === 'DIAJUKAN' && auth.hasPermission('rps.approve')" class="px-3 py-1.5 bg-orange-600 hover:bg-orange-700 text-white text-xs font-medium rounded-lg" @click="rejectModalOpen = true">
          Revisi
        </button>
        <button v-if="rpkps.status === 'DISETUJUI' && auth.hasPermission('rps.approve')" class="px-3 py-1.5 bg-indigo-600 hover:bg-indigo-700 text-white text-xs font-medium rounded-lg" @click="handleLock">
          <LockClosedIcon class="w-3.5 h-3.5 inline mr-0.5" /> Kunci
        </button>
      </div>
    </div>

    <!-- Revision note -->
    <div v-if="rpkps.revision_note" class="p-3 bg-orange-50 border border-orange-200 rounded-lg text-sm">
      <p class="font-semibold text-orange-700 text-xs">Catatan Revisi:</p>
      <p class="text-orange-800 mt-1">{{ rpkps.revision_note }}</p>
    </div>

    <!-- Tabs -->
    <div class="flex gap-1 border-b border-gray-200 overflow-x-auto">
      <button v-for="t in tabs" :key="t.key"
        :class="['px-3 py-2 text-xs font-medium border-b-2 -mb-px transition-colors whitespace-nowrap',
          activeTab === t.key ? 'border-blue-600 text-blue-600' : 'border-transparent text-gray-500 hover:text-gray-700']"
        @click="activeTab = t.key">
        {{ t.label }}
      </button>
    </div>

    <!-- TAB: Deskripsi & Bahan Kajian -->
    <div v-if="activeTab === 'deskripsi'" class="bg-white rounded-xl border border-gray-200 p-5 space-y-4">
      <div>
        <label class="block text-sm font-medium text-gray-700 mb-1">Deskripsi Mata Kuliah</label>
        <textarea v-model="rpkps.course_description" :disabled="!isEditable" rows="4" placeholder="Tuliskan deskripsi singkat mata kuliah..." class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 disabled:bg-gray-50" />
      </div>
      <div>
        <label class="block text-sm font-medium text-gray-700 mb-1">Bahan Kajian</label>
        <textarea v-model="rpkps.course_scope" :disabled="!isEditable" rows="4" placeholder="Daftar bahan kajian / topik utama yang dibahas dalam mata kuliah ini..." class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 disabled:bg-gray-50" />
      </div>
      <div>
        <label class="block text-sm font-medium text-gray-700 mb-1">Mata Kuliah Syarat</label>
        <select v-model="rpkps.prerequisites" :disabled="!isEditable" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 disabled:bg-gray-50">
          <option value="">— Tidak ada prasyarat —</option>
          <option v-for="c in curriculumCourses" :key="c.id" :value="c.code + ' - ' + c.name">
            {{ c.code }} – {{ c.name }}
          </option>
        </select>
        <p class="text-xs text-gray-400 mt-1">Pilih mata kuliah yang harus ditempuh sebelum mengambil mata kuliah ini.</p>
      </div>
      <button v-if="isEditable" :disabled="saving" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 disabled:bg-blue-400 text-white text-sm font-medium rounded-lg" @click="saveDeskripsi">
        {{ saving ? 'Menyimpan...' : 'Simpan' }}
      </button>
    </div>

    <!-- TAB: CPL -->
    <div v-if="activeTab === 'cpl'" class="bg-white rounded-xl border border-gray-200 p-5 space-y-4">
      <p class="text-sm text-gray-500">CPL yang didukung oleh mata kuliah ini (diambil otomatis dari pemetaan kurikulum).</p>

      <div v-if="mappedCpls.length" class="space-y-2">
        <div v-for="cpl in mappedCpls" :key="cpl.id" class="flex items-start gap-3 p-3 rounded-lg bg-gray-50">
          <span class="inline-flex px-2 py-0.5 rounded text-xs font-mono font-bold bg-blue-100 text-blue-700 shrink-0 mt-0.5">
            {{ cpl.code }}
          </span>
          <p class="text-sm text-gray-700">{{ cpl.description }}</p>
        </div>
      </div>

      <div v-else class="text-center py-6">
        <p class="text-sm text-gray-400">Belum ada CPL yang terpetakan untuk mata kuliah ini.</p>
        <p class="text-xs text-gray-400 mt-1">Atur pemetaan CPL–Mata Kuliah di halaman detail Kurikulum.</p>
      </div>
    </div>

    <!-- TAB: CPMK -->
    <div v-if="activeTab === 'cpmk'" class="bg-white rounded-xl border border-gray-200 p-5 space-y-4">
      <div class="flex items-center justify-between">
        <p class="text-sm text-gray-500">Capaian Pembelajaran Mata Kuliah</p>
        <button v-if="isEditable" class="flex items-center gap-1 px-3 py-1.5 bg-blue-600 hover:bg-blue-700 text-white text-xs font-medium rounded-lg" @click="openAddCpmk">
          <PlusIcon class="w-3.5 h-3.5" /> Tambah CPMK
        </button>
      </div>
      <div v-if="rpkps.cpmks?.length" class="space-y-3">
        <div v-for="cpmk in rpkps.cpmks" :key="cpmk.id" class="p-3 bg-gray-50 rounded-lg group">
          <div class="flex items-start justify-between">
            <div>
              <span class="font-mono text-xs font-bold text-purple-700">{{ cpmk.code }}</span>
              <p class="text-sm text-gray-700 mt-0.5">{{ cpmk.description }}</p>
              <div v-if="cpmk.cpls?.length" class="flex flex-wrap gap-1 mt-2">
                <span v-for="cpl in cpmk.cpls" :key="cpl.id" class="px-1.5 py-0.5 rounded bg-blue-100 text-blue-700 text-[10px] font-semibold">{{ cpl.code }}</span>
              </div>
            </div>
            <div v-if="isEditable" class="flex items-center gap-1 opacity-0 group-hover:opacity-100">
              <button class="p-1 text-blue-500 hover:text-blue-700" title="Edit" @click="openEditCpmk(cpmk)">
                <PencilIcon class="w-4 h-4" />
              </button>
              <button class="p-1 text-red-400 hover:text-red-600" title="Hapus" @click="deleteCpmk(cpmk.id)">
                <TrashIcon class="w-4 h-4" />
              </button>
            </div>
          </div>
          <div v-if="cpmk.sub_cpmks?.length" class="mt-2 ml-4 space-y-1">
            <p v-for="sub in cpmk.sub_cpmks" :key="sub.id" class="text-xs text-gray-500">
              <span class="font-mono text-gray-400">{{ sub.code }}</span> {{ sub.description }}
            </p>
          </div>
        </div>
      </div>
      <p v-else class="text-center text-gray-400 text-sm py-4">Belum ada CPMK.</p>
    </div>

    <!-- TAB: Rencana Mingguan -->
    <div v-if="activeTab === 'mingguan'" class="bg-white rounded-xl border border-gray-200 p-5 space-y-4">
      <p class="text-sm text-gray-500">Rencana pembelajaran 16 minggu. Geser tabel ke samping untuk melengkapi seluruh unsur RPS OBE.</p>
      <button v-if="!rpkps.weekly_plans?.length && isEditable" class="px-3 py-1.5 bg-gray-100 hover:bg-gray-200 text-gray-700 text-xs rounded-lg" @click="initWeeklyPlans">
        + Inisialisasi 16 Minggu
      </button>
      <div v-if="rpkps.weekly_plans?.length" class="overflow-x-auto">
        <table class="min-w-[2400px] w-full text-xs border-collapse">
          <thead>
            <tr class="bg-gray-50">
              <th class="border px-2 py-1.5 w-10">Mg</th>
              <th class="border px-2 py-1.5 w-52">Sub-CPMK</th>
              <th class="border px-2 py-1.5 w-52">Indikator</th>
              <th class="border px-2 py-1.5 w-52">Bahan Kajian</th>
              <th class="border px-2 py-1.5 w-44">Metode Umum</th>
              <th class="border px-2 py-1.5 w-56">Pembelajaran Luring</th>
              <th class="border px-2 py-1.5 w-56">Pembelajaran Daring</th>
              <th class="border px-2 py-1.5 w-28">Alokasi Waktu</th>
              <th class="border px-2 py-1.5 w-52">Aktivitas Dosen</th>
              <th class="border px-2 py-1.5 w-52">Aktivitas/Pengalaman Mahasiswa</th>
              <th class="border px-2 py-1.5 w-44">Bentuk/Teknik Penilaian</th>
              <th class="border px-2 py-1.5 w-44">Kriteria Penilaian</th>
              <th class="border px-2 py-1.5 w-40">Media</th>
              <th class="border px-2 py-1.5 w-14">Bobot</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="w in rpkps.weekly_plans" :key="w.week_number" :class="[w.week_number === 8 || w.week_number === 16 ? 'bg-orange-50' : '']">
              <td class="border px-2 py-1 text-center font-bold">{{ w.week_number }}</td>
              <td class="border px-1 py-1">
                <p v-if="[8, 16].includes(Number(w.week_number))" class="px-1 py-1 font-medium text-orange-700">
                  {{ Number(w.week_number) === 8 ? 'Evaluasi Tengah Semester' : 'Evaluasi Akhir Semester' }}
                </p>
                <template v-else>
                  <select v-model="w.sub_cpmk_codes" :disabled="!isEditable" multiple size="4" class="w-full px-1 py-1 text-xs border border-gray-200 rounded focus:ring-1 focus:ring-blue-400">
                    <option v-for="sub in availableSubCpmks" :key="sub.id" :value="sub.code">
                      {{ sub.code }} — {{ sub.description }}
                    </option>
                  </select>
                  <p v-if="w.legacy_sub_cpmk_codes?.length" class="mt-1 text-[10px] text-amber-700">
                    Kode lama dipertahankan: {{ w.legacy_sub_cpmk_codes.join(', ') }}. Pilih Sub-CPMK baru untuk menggantinya.
                  </p>
                </template>
              </td>
              <td class="border px-1 py-1"><textarea v-model="w.indicators" :disabled="!isEditable" rows="3" placeholder="Indikator ketercapaian" class="w-full px-1 py-0.5 text-xs border-0 focus:ring-1 focus:ring-blue-400 rounded" /></td>
              <td class="border px-1 py-1"><textarea v-model="w.learning_material" :disabled="!isEditable" rows="3" class="w-full px-1 py-0.5 text-xs border-0 focus:ring-1 focus:ring-blue-400 rounded" /></td>
              <td class="border px-1 py-1"><input v-model="w.methods" :disabled="!isEditable" placeholder="Ceramah | Diskusi" class="w-full px-1 py-0.5 text-xs border-0 focus:ring-1 focus:ring-blue-400 rounded" /></td>
              <td class="border px-1 py-1"><textarea v-model="w.offline_learning" :disabled="!isEditable" rows="3" placeholder="Bentuk, metode, tugas luring" class="w-full px-1 py-0.5 text-xs border-0 focus:ring-1 focus:ring-blue-400 rounded" /></td>
              <td class="border px-1 py-1"><textarea v-model="w.online_learning" :disabled="!isEditable" rows="3" placeholder="Sinkron/asinkron, LMS, tugas" class="w-full px-1 py-0.5 text-xs border-0 focus:ring-1 focus:ring-blue-400 rounded" /></td>
              <td class="border px-1 py-1"><input v-model="w.duration" :disabled="!isEditable" placeholder="2x50'" class="w-full px-1 py-0.5 text-xs border-0 focus:ring-1 focus:ring-blue-400 rounded text-center" /></td>
              <td class="border px-1 py-1"><textarea v-model="w.lecturer_activity" :disabled="!isEditable" rows="3" class="w-full px-1 py-0.5 text-xs border-0 focus:ring-1 focus:ring-blue-400 rounded" /></td>
              <td class="border px-1 py-1"><textarea v-model="w.student_activity" :disabled="!isEditable" rows="3" class="w-full px-1 py-0.5 text-xs border-0 focus:ring-1 focus:ring-blue-400 rounded" /></td>
              <td class="border px-1 py-1"><textarea v-model="w.assessment_form" :disabled="!isEditable" rows="3" placeholder="Tes/non-tes, tugas, observasi" class="w-full px-1 py-0.5 text-xs border-0 focus:ring-1 focus:ring-blue-400 rounded" /></td>
              <td class="border px-1 py-1"><textarea v-model="w.assessment_criteria" :disabled="!isEditable" rows="3" placeholder="Akurasi, kedalaman, ketepatan" class="w-full px-1 py-0.5 text-xs border-0 focus:ring-1 focus:ring-blue-400 rounded" /></td>
              <td class="border px-1 py-1"><textarea v-model="w.media" :disabled="!isEditable" rows="3" placeholder="LMS, video, modul" class="w-full px-1 py-0.5 text-xs border-0 focus:ring-1 focus:ring-blue-400 rounded" /></td>
              <td class="border px-1 py-1"><input v-model.number="w.weight" :disabled="!isEditable" type="number" min="0" class="w-full px-1 py-0.5 text-xs border-0 focus:ring-1 focus:ring-blue-400 rounded text-center" /></td>
            </tr>
          </tbody>
        </table>
      </div>
      <button v-if="rpkps.weekly_plans?.length && isEditable" :disabled="saving" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 disabled:bg-blue-400 text-white text-sm font-medium rounded-lg" @click="saveWeeklyPlans">
        {{ saving ? 'Menyimpan...' : 'Simpan Rencana Mingguan' }}
      </button>
    </div>

    <!-- TAB: Asesmen -->
    <div v-if="activeTab === 'asesmen'" class="bg-white rounded-xl border border-gray-200 p-5 space-y-4">
      <div class="flex items-center justify-between">
        <p class="text-sm text-gray-500">Komponen penilaian (total harus 100%)</p>
        <span :class="['text-xs font-bold', totalWeight === 100 ? 'text-green-600' : 'text-red-500']">
          Total: {{ totalWeight }}%
        </span>
      </div>
      <button v-if="(!rpkps.assessments?.length || !rpkps.grade_scales?.length) && isEditable" class="px-3 py-1.5 bg-gray-100 hover:bg-gray-200 text-gray-700 text-xs rounded-lg" @click="initAssessments">
        + Gunakan Template Default
      </button>
      <div v-if="rpkps.assessments?.length" class="space-y-2">
        <div v-for="(a, i) in rpkps.assessments" :key="i" class="grid grid-cols-1 md:grid-cols-[1fr_2fr_6rem_2rem] items-center gap-3">
          <input v-model="a.name" :disabled="!isEditable" placeholder="Nama komponen" class="px-3 py-2 border border-gray-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 disabled:bg-gray-50" />
          <input v-model="a.description" :disabled="!isEditable" placeholder="Teknik/instrumen atau keterangan" class="px-3 py-2 border border-gray-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 disabled:bg-gray-50" />
          <div class="flex items-center gap-1 w-24">
            <input v-model.number="a.weight" :disabled="!isEditable" type="number" min="0" max="100" class="w-16 px-2 py-2 border border-gray-300 rounded-lg text-sm text-center focus:outline-none focus:ring-2 focus:ring-blue-500 disabled:bg-gray-50" />
            <span class="text-xs text-gray-500">%</span>
          </div>
          <button v-if="isEditable" class="p-1.5 text-red-400 hover:text-red-600" @click="removeAssessment(Number(i))">
            <TrashIcon class="w-4 h-4" />
          </button>
        </div>
        <button v-if="isEditable" class="text-xs text-blue-600 hover:text-blue-700 font-medium" @click="addAssessment">
          + Tambah Komponen
        </button>
      </div>
      <div class="border-t border-gray-200 pt-4 space-y-2">
        <label class="block text-sm font-medium text-gray-700">Rumus Nilai Akhir</label>
        <textarea v-model="rpkps.final_grade_formula" :disabled="!isEditable" rows="2" placeholder="Contoh: NA = (10% × Partisipasi) + (20% × Tugas) + (30% × UTS) + (40% × UAS)" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm disabled:bg-gray-50" />
      </div>
      <div class="border-t border-gray-200 pt-4 space-y-3">
        <div class="flex items-center justify-between">
          <h3 class="text-sm font-semibold text-gray-800">Konversi Nilai</h3>
          <button v-if="isEditable" class="text-xs text-blue-600" @click="addGradeScale">+ Tambah Rentang</button>
        </div>
        <div v-if="rpkps.grade_scales?.length" class="overflow-x-auto">
          <table class="w-full text-xs border-collapse">
            <thead><tr class="bg-gray-50"><th class="border p-2">Nilai Minimum</th><th class="border p-2">Nilai Maksimum</th><th class="border p-2">Huruf Mutu</th><th class="border p-2">Bobot</th><th v-if="isEditable" class="border p-2 w-10"></th></tr></thead>
            <tbody>
              <tr v-for="(scale, i) in rpkps.grade_scales" :key="i">
                <td class="border p-1"><input v-model.number="scale.min_score" :disabled="!isEditable" type="number" min="0" max="100" step="0.01" class="w-full p-1 text-center disabled:bg-gray-50" /></td>
                <td class="border p-1"><input v-model.number="scale.max_score" :disabled="!isEditable" type="number" min="0" max="100" step="0.01" class="w-full p-1 text-center disabled:bg-gray-50" /></td>
                <td class="border p-1"><input v-model="scale.letter" :disabled="!isEditable" maxlength="5" class="w-full p-1 text-center font-semibold disabled:bg-gray-50" /></td>
                <td class="border p-1"><input v-model.number="scale.grade_point" :disabled="!isEditable" type="number" min="0" max="4" step="0.01" class="w-full p-1 text-center disabled:bg-gray-50" /></td>
                <td v-if="isEditable" class="border p-1 text-center"><button class="text-red-500" @click="removeGradeScale(Number(i))">×</button></td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
      <button v-if="rpkps.assessments?.length && isEditable" :disabled="saving || totalWeight !== 100" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 disabled:bg-blue-400 text-white text-sm font-medium rounded-lg" @click="saveAssessments">
        {{ saving ? 'Menyimpan...' : 'Simpan Asesmen' }}
      </button>
      <p v-if="totalWeight !== 100 && rpkps.assessments?.length" class="text-xs text-red-500">
        Total bobot harus tepat 100%. Saat ini {{ totalWeight }}%.
      </p>
    </div>

    <!-- TAB: Instrumen & Rubrik -->
    <div v-if="activeTab === 'instrumen'" class="space-y-5">
      <section class="bg-white rounded-xl border border-gray-200 p-5 space-y-4">
        <div class="flex items-center justify-between">
          <div><h2 class="font-semibold text-gray-800">Kisi-kisi Instrumen Penilaian</h2><p class="text-xs text-gray-500">Petakan Sub-CPMK, instrumen, ranah capaian, bobot, dan waktu pelaksanaan.</p></div>
          <button v-if="isEditable" class="text-xs text-blue-600 font-medium" @click="addInstrument">+ Tambah Instrumen</button>
        </div>
        <div v-for="(instrument, i) in (rpkps.assessment_instruments ?? [])" :key="i" class="border border-gray-200 rounded-lg p-4 space-y-3">
          <div class="grid grid-cols-1 md:grid-cols-4 gap-3">
            <input v-model="instrument.instrument_name" :disabled="!isEditable" placeholder="Nama instrumen *" class="px-3 py-2 border rounded-lg text-sm disabled:bg-gray-50" />
            <select v-model="instrument.rpkps_assessment_id" :disabled="!isEditable" class="px-3 py-2 border rounded-lg text-sm disabled:bg-gray-50"><option :value="null">-- Komponen asesmen --</option><option v-for="a in rpkps.assessments" :key="a.id" :value="a.id">{{ a.name }}</option></select>
            <select v-model="instrument.instrument_type" :disabled="!isEditable" class="px-3 py-2 border rounded-lg text-sm disabled:bg-gray-50"><option>Tes</option><option>Non-tes</option><option>Proyek</option><option>Portofolio</option><option>Observasi</option></select>
            <input v-model="instrument.technique" :disabled="!isEditable" placeholder="Teknik penilaian" class="px-3 py-2 border rounded-lg text-sm disabled:bg-gray-50" />
          </div>
          <textarea v-model="instrument.sub_cpmk" :disabled="!isEditable" rows="2" placeholder="Sub-CPMK yang diukur" class="w-full px-3 py-2 border rounded-lg text-sm disabled:bg-gray-50" />
          <div class="grid grid-cols-2 md:grid-cols-6 gap-3">
            <input v-model="instrument.cognitive_level" :disabled="!isEditable" placeholder="Kognitif (C1–C6)" class="px-2 py-2 border rounded text-xs disabled:bg-gray-50" />
            <input v-model="instrument.affective_level" :disabled="!isEditable" placeholder="Afektif (A1–A5)" class="px-2 py-2 border rounded text-xs disabled:bg-gray-50" />
            <input v-model="instrument.psychomotor_level" :disabled="!isEditable" placeholder="Psikomotor (P1–P5)" class="px-2 py-2 border rounded text-xs disabled:bg-gray-50" />
            <input v-model="instrument.item_numbers" :disabled="!isEditable" placeholder="Nomor butir" class="px-2 py-2 border rounded text-xs disabled:bg-gray-50" />
            <input v-model.number="instrument.weight" :disabled="!isEditable" type="number" min="0" max="100" placeholder="Bobot %" class="px-2 py-2 border rounded text-xs disabled:bg-gray-50" />
            <input v-model="instrument.timing" :disabled="!isEditable" placeholder="Waktu pelaksanaan" class="px-2 py-2 border rounded text-xs disabled:bg-gray-50" />
          </div>
          <div class="flex gap-2"><textarea v-model="instrument.description" :disabled="!isEditable" rows="2" placeholder="Deskripsi/keterangan instrumen" class="flex-1 px-3 py-2 border rounded-lg text-sm disabled:bg-gray-50" /><button v-if="isEditable" class="self-start p-2 text-red-500" @click="removeInstrument(Number(i))"><TrashIcon class="w-4 h-4" /></button></div>
        </div>
        <p v-if="!rpkps.assessment_instruments?.length" class="text-sm text-gray-400 text-center py-4">Belum ada instrumen penilaian.</p>
      </section>

      <section class="bg-white rounded-xl border border-gray-200 p-5 space-y-4">
        <div class="flex items-center justify-between">
          <div><h2 class="font-semibold text-gray-800">Rubrik Penilaian</h2><p class="text-xs text-gray-500">Setiap baris berisi satu kriteria dengan sampai lima tingkat performa.</p></div>
          <button v-if="isEditable" class="text-xs text-blue-600 font-medium" @click="addRubric">+ Tambah Kriteria</button>
        </div>
        <div v-for="(rubric, i) in (rpkps.rubrics ?? [])" :key="i" class="border border-gray-200 rounded-lg p-4 space-y-3">
          <div class="grid grid-cols-1 md:grid-cols-[1fr_1fr_8rem_5rem_2rem] gap-2 items-center">
            <input v-model="rubric.title" :disabled="!isEditable" placeholder="Judul rubrik" class="px-2 py-2 border rounded text-sm disabled:bg-gray-50" />
            <input v-model="rubric.criteria" :disabled="!isEditable" placeholder="Kriteria *" class="px-2 py-2 border rounded text-sm disabled:bg-gray-50" />
            <select v-model="rubric.rpkps_assessment_id" :disabled="!isEditable" class="px-2 py-2 border rounded text-xs disabled:bg-gray-50"><option :value="null">Asesmen</option><option v-for="a in rpkps.assessments" :key="a.id" :value="a.id">{{ a.name }}</option></select>
            <input v-model.number="rubric.weight" :disabled="!isEditable" type="number" min="0" max="100" placeholder="Bobot" class="px-2 py-2 border rounded text-xs disabled:bg-gray-50" />
            <button v-if="isEditable" class="text-red-500" @click="removeRubric(Number(i))">×</button>
          </div>
          <div class="grid grid-cols-1 md:grid-cols-5 gap-2">
            <textarea v-model="rubric.excellent" :disabled="!isEditable" rows="3" placeholder="Sangat Baik" class="p-2 border rounded text-xs disabled:bg-gray-50" />
            <textarea v-model="rubric.good" :disabled="!isEditable" rows="3" placeholder="Baik" class="p-2 border rounded text-xs disabled:bg-gray-50" />
            <textarea v-model="rubric.fair" :disabled="!isEditable" rows="3" placeholder="Cukup" class="p-2 border rounded text-xs disabled:bg-gray-50" />
            <textarea v-model="rubric.poor" :disabled="!isEditable" rows="3" placeholder="Kurang" class="p-2 border rounded text-xs disabled:bg-gray-50" />
            <textarea v-model="rubric.very_poor" :disabled="!isEditable" rows="3" placeholder="Sangat Kurang" class="p-2 border rounded text-xs disabled:bg-gray-50" />
          </div>
        </div>
        <p v-if="!rpkps.rubrics?.length" class="text-sm text-gray-400 text-center py-4">Belum ada rubrik.</p>
      </section>
      <button v-if="isEditable" :disabled="saving" class="px-4 py-2 bg-blue-600 disabled:bg-blue-400 text-white text-sm font-medium rounded-lg" @click="saveEvaluationTools">{{ saving ? 'Menyimpan...' : 'Simpan Instrumen & Rubrik' }}</button>
    </div>

    <!-- TAB: Referensi -->
    <div v-if="activeTab === 'referensi'" class="bg-white rounded-xl border border-gray-200 p-5 space-y-4">
      <div class="flex items-center justify-between">
        <p class="text-sm text-gray-500">Daftar pustaka</p>
        <button v-if="isEditable" class="text-xs text-blue-600 hover:text-blue-700 font-medium" @click="addReference">+ Tambah Referensi</button>
      </div>
      <div v-if="rpkps.references?.length" class="space-y-3">
        <div v-for="(r, i) in rpkps.references" :key="i" class="p-3 border border-gray-200 rounded-lg space-y-2">
          <div class="flex items-center gap-2">
            <select v-model="r.type" :disabled="!isEditable" class="px-2 py-1 border border-gray-300 rounded text-xs">
              <option value="Utama">Utama</option><option value="Pendukung">Pendukung</option>
            </select>
            <input v-model="r.category" :disabled="!isEditable" required maxlength="100" placeholder="Kategori referensi *" class="w-40 px-2 py-1 border border-gray-300 rounded text-xs disabled:bg-gray-50" />
            <button v-if="isEditable" class="ml-auto p-1 text-red-400 hover:text-red-600" @click="removeReference(Number(i))"><TrashIcon class="w-4 h-4" /></button>
          </div>
          <input v-model="r.title" :disabled="!isEditable" placeholder="Judul *" class="w-full px-2 py-1.5 border border-gray-300 rounded text-sm focus:ring-1 focus:ring-blue-500 disabled:bg-gray-50" />
          <div class="grid grid-cols-2 md:grid-cols-4 gap-2">
            <input v-model="r.author" :disabled="!isEditable" placeholder="Penulis" class="px-2 py-1.5 border border-gray-300 rounded text-xs disabled:bg-gray-50" />
            <input v-model="r.year" :disabled="!isEditable" placeholder="Tahun" class="px-2 py-1.5 border border-gray-300 rounded text-xs disabled:bg-gray-50" />
            <input v-model="r.publisher" :disabled="!isEditable" placeholder="Penerbit" class="px-2 py-1.5 border border-gray-300 rounded text-xs disabled:bg-gray-50" />
            <input v-model="r.isbn_doi" :disabled="!isEditable" placeholder="ISBN/DOI" class="px-2 py-1.5 border border-gray-300 rounded text-xs disabled:bg-gray-50" />
          </div>
          <input v-model="r.url" :disabled="!isEditable" placeholder="URL (opsional)" class="w-full px-2 py-1.5 border border-gray-300 rounded text-xs disabled:bg-gray-50" />
        </div>
      </div>
      <p v-else class="text-center text-gray-400 text-sm py-4">Belum ada referensi.</p>
      <button v-if="rpkps.references?.length && isEditable" :disabled="saving" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 disabled:bg-blue-400 text-white text-sm font-medium rounded-lg" @click="saveReferences">
        {{ saving ? 'Menyimpan...' : 'Simpan Referensi' }}
      </button>
    </div>

    <!-- TAB: Riwayat -->
    <div v-if="activeTab === 'riwayat'" class="bg-white rounded-xl border border-gray-200 p-5 space-y-3">
      <h2 class="text-sm font-semibold text-gray-800">Riwayat Approval</h2>
      <div v-if="rpkps.approvals?.length" class="space-y-2">
        <div v-for="a in rpkps.approvals" :key="a.id" class="flex items-center gap-3 text-xs text-gray-500 p-2 bg-gray-50 rounded">
          <span class="w-20 shrink-0 font-mono">{{ new Date(a.created_at).toLocaleDateString('id-ID') }}</span>
          <span class="font-medium text-gray-700">{{ a.user?.name }}</span>
          <span :class="['px-2 py-0.5 rounded text-xs font-medium', statusColor[a.action] ?? 'bg-gray-100']">{{ a.action?.replace(/_/g, ' ') }}</span>
          <span v-if="a.note" class="text-gray-400 truncate flex-1">{{ a.note }}</span>
        </div>
      </div>
      <p v-else class="text-sm text-gray-400">Belum ada riwayat.</p>
    </div>
  </div>

  <!-- Modal CPMK -->
  <BaseModal :open="cpmkModalOpen" :title="editingCpmkId ? 'Edit CPMK' : 'Tambah CPMK'" @close="cpmkModalOpen = false">
    <form class="space-y-4" @submit.prevent="saveCpmk">
      <div>
        <label class="block text-sm font-medium text-gray-700 mb-1">Kode <span class="text-red-500">*</span></label>
        <input v-model="cpmkForm.code" required placeholder="CPMK-01" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-blue-500" />
      </div>
      <div>
        <label class="block text-sm font-medium text-gray-700 mb-1">Deskripsi <span class="text-red-500">*</span></label>
        <textarea v-model="cpmkForm.description" required rows="3" placeholder="Mahasiswa mampu..." class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-blue-500" />
      </div>
      <div>
        <div class="flex items-center justify-between mb-1">
          <label class="block text-sm font-medium text-gray-700">Sub-CPMK</label>
          <button type="button" class="text-xs text-blue-600 hover:text-blue-800" @click="addSubCpmk">+ Tambah Sub-CPMK</button>
        </div>
        <div v-if="cpmkForm.sub_cpmks.length" class="space-y-2">
          <div v-for="(sub, index) in cpmkForm.sub_cpmks" :key="index" class="flex items-start gap-2">
            <input v-model="sub.code" readonly class="w-28 px-2 py-2 border border-gray-200 bg-gray-50 rounded-lg text-sm font-mono" />
            <textarea v-model="sub.description" required rows="2" placeholder="Mahasiswa mampu..." class="flex-1 px-2 py-2 border border-gray-300 rounded-lg text-sm" />
            <button type="button" class="p-2 text-red-400 hover:text-red-600" title="Hapus Sub-CPMK" @click="removeSubCpmk(index)">
              <TrashIcon class="w-4 h-4" />
            </button>
          </div>
        </div>
        <p v-else class="text-xs text-gray-400">Tambahkan minimal satu Sub-CPMK untuk CPMK ini.</p>
      </div>
      <div>
        <label class="block text-sm font-medium text-gray-700 mb-1">CPL yang didukung</label>
        <p v-if="!rpkps?.cpls?.length" class="text-xs text-amber-600">Pilih CPL RPKPS terlebih dahulu pada tab CPL.</p>
        <div v-else class="max-h-52 overflow-y-auto border border-gray-200 rounded-lg divide-y divide-gray-100">
          <label v-for="cpl in rpkps.cpls" :key="cpl.id" class="flex items-start gap-2 p-2.5 cursor-pointer hover:bg-gray-50">
            <input type="checkbox" :checked="cpmkForm.cpl_ids.includes(cpl.id)" class="mt-0.5 rounded border-gray-300 text-blue-600" @change="toggleCpmkCpl(cpl.id)" />
            <span class="text-xs"><strong class="text-blue-700">{{ cpl.code }}</strong> {{ cpl.description }}</span>
          </label>
        </div>
      </div>
    </form>
    <template #footer>
      <button class="px-4 py-2 text-sm text-gray-600 hover:bg-gray-100 rounded-lg" @click="cpmkModalOpen = false">Batal</button>
      <button :disabled="saving" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 disabled:bg-blue-400 text-white text-sm font-medium rounded-lg" @click="saveCpmk">
        {{ saving ? 'Menyimpan...' : 'Simpan' }}
      </button>
    </template>
  </BaseModal>

  <!-- Modal Revisi -->
  <BaseModal :open="rejectModalOpen" title="Minta Revisi" @close="rejectModalOpen = false">
    <div>
      <label class="block text-sm font-medium text-gray-700 mb-1">Catatan Revisi <span class="text-red-500">*</span></label>
      <textarea v-model="rejectionNote" rows="4" placeholder="Bagian yang perlu diperbaiki..." class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-blue-500" />
    </div>
    <template #footer>
      <button class="px-4 py-2 text-sm text-gray-600 hover:bg-gray-100 rounded-lg" @click="rejectModalOpen = false">Batal</button>
      <button class="px-4 py-2 bg-orange-600 hover:bg-orange-700 text-white text-sm font-medium rounded-lg" @click="handleRevise">Kirim</button>
    </template>
  </BaseModal>
</template>
