<script setup lang="ts">
import { ref, onMounted, computed } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { ArrowLeftIcon, PlusIcon, TrashIcon, PaperAirplaneIcon, CheckIcon, XMarkIcon } from '@heroicons/vue/24/outline'
import { useToast } from 'vue-toastification'
import { useAuthStore } from '@/stores/auth'
import BaseModal from '@/components/ui/BaseModal.vue'
import api from '@/services/api'
import { extractErrorMessage } from '@/composables/useCrud'
import { getKrsStatusColor, getKrsStatusLabel } from '@/utils/krsStatus'

const route  = useRoute()
const router = useRouter()
const toast  = useToast()
const auth   = useAuthStore()

const krs       = ref<any>(null)
const loading   = ref(false)
const addModal  = ref(false)
const rejectModal = ref(false)
const rejectionNote = ref('')
const rejectionStage = ref<'advisor' | 'kaprodi'>('advisor')
const submitting = ref(false)
const approving  = ref(false)

// Untuk tambah MK
const availableCourses = ref<any[]>([])
const loadingCourses   = ref(false)
const selectedClassByCourse = ref<Record<number, string>>({})

const canManageKrs = computed(() =>
  auth.hasRole(['SUPER_ADMIN', 'ADMIN_AKADEMIK'])
  || (auth.user?.position_codes ?? []).some((code) => ['KAPRODI', 'SEKPRODI'].includes(code)),
)
const canEdit = computed(() =>
  krs.value && ['DRAFT', 'REJECTED'].includes(krs.value.status) &&
  (auth.hasRole('MAHASISWA') || canManageKrs.value)
)
const canEditCourses = computed(() =>
  !!krs.value && (canEdit.value || auth.hasRole(['SUPER_ADMIN', 'ADMIN_AKADEMIK']))
)
const canApprove = computed(() =>
  krs.value?.status === 'SUBMITTED' &&
  (auth.hasRole(['SUPER_ADMIN', 'ADMIN_AKADEMIK']) || Number(krs.value?.advisor_id ?? krs.value?.advisor?.id) === Number(auth.user?.lecturer?.id))
)

const canSignKaprodi = computed(() =>
  krs.value?.status === 'APPROVED' &&
  !!krs.value?.kaprodi_approved_at &&
  !krs.value?.signed_by_kaprodi_at &&
  (auth.hasRole(['SUPER_ADMIN', 'ADMIN_AKADEMIK']) || (auth.user?.position_codes ?? []).includes('KAPRODI'))
)

const canReviewKaprodi = computed(() =>
  krs.value?.status === 'APPROVED' &&
  !krs.value?.kaprodi_approved_at &&
  !krs.value?.signed_by_kaprodi_at &&
  (auth.hasRole(['SUPER_ADMIN', 'ADMIN_AKADEMIK']) || (auth.user?.position_codes ?? []).some((code) => ['KAPRODI', 'SEKPRODI'].includes(code)))
)

onMounted(load)

async function load() {
  loading.value = true
  try {
    const { data } = await api.get(`/krs/${route.params.id}`)
    krs.value = data
  } catch { toast.error('Gagal memuat KRS.') }
  finally { loading.value = false }
}

async function loadAvailableCourses() {
  loadingCourses.value = true
  try {
    const { data } = await api.get('/classes/all', {
      params: {
        semester_id: krs.value?.semester_id,
        study_program_id: krs.value?.student?.study_program?.id,
      },
    })
    const grouped = new Map<number, any>()
    for (const classItem of data) {
      const offered = classItem.offered_courses?.length ? classItem.offered_courses : [classItem.course]
      for (const course of offered.filter(Boolean)) {
        if (Number(course.study_program_id ?? course.study_program?.id) !== Number(krs.value?.student?.study_program?.id)) continue
        if (!grouped.has(course.id)) grouped.set(course.id, { ...course, classes: [] })
        grouped.get(course.id).classes.push(classItem)
      }
    }
    availableCourses.value = Array.from(grouped.values())
    selectedClassByCourse.value = {}
  } finally { loadingCourses.value = false }
}

function openAddModal() {
  loadAvailableCourses()
  addModal.value = true
}

function isEnrolled(courseId: number) {
  return krs.value?.details?.some((d: any) => d.course_id === courseId && d.status === 'AKTIF')
}

function classOptionDetails(classItem: any): string {
  const schedule = classItem.schedules?.find((item: any) => item.is_active !== false) ?? classItem.schedules?.[0]
  const lecturer = schedule?.lecturer?.name ?? schedule?.lecturer?.full_name
    ?? classItem.lecturer?.name ?? classItem.lecturer?.full_name ?? '-'
  const scheduleText = schedule
    ? `${schedule.day} ${schedule.start_time?.slice(0, 5)}-${schedule.end_time?.slice(0, 5)}`
    : '-'
  const room = schedule?.room?.name ?? classItem.room?.name ?? '-'
  return `${lecturer} · ${scheduleText} · ${room}`
}

async function addCourse(courseId: number) {
  const classId = selectedClassByCourse.value[courseId]
  if (!classId) { toast.warning('Pilih kelas terlebih dahulu.'); return }
  try {
    await api.post(`/krs/${krs.value.id}/courses`, { course_id: courseId, class_id: classId })
    toast.success('Mata kuliah ditambahkan.')
    load()
  } catch (err: any) { toast.error(extractErrorMessage(err)) }
}

async function removeCourse(detailId: number, name: string) {
  if (!confirm(`Hapus "${name}" dari KRS?`)) return
  try {
    await api.delete(`/krs/${krs.value.id}/courses/${detailId}`)
    toast.success('Mata kuliah dihapus.')
    load()
  } catch (err: any) { toast.error(extractErrorMessage(err)) }
}

async function handleSubmit() {
  if (!confirm('Ajukan KRS ke dosen wali?')) return
  submitting.value = true
  try {
    await api.post(`/krs/${krs.value.id}/submit`)
    toast.success('KRS berhasil disubmit.')
    load()
  } catch (err: any) { toast.error(extractErrorMessage(err)) }
  finally { submitting.value = false }
}

async function handleApprove() {
  if (!confirm('Setujui KRS ini?')) return
  approving.value = true
  try {
    await api.post(`/krs/${krs.value.id}/approve`)
    toast.success('KRS disetujui.')
    load()
  } catch (err: any) { toast.error(extractErrorMessage(err)) }
  finally { approving.value = false }
}

async function handleApproveKaprodi() {
  if (!confirm('Setujui KRS ini pada tahap program studi?')) return
  approving.value = true
  try {
    await api.post(`/krs/${krs.value.id}/approve-kaprodi`)
    toast.success('KRS disetujui pada tahap prodi.')
    load()
  } catch (err: any) { toast.error(extractErrorMessage(err)) }
  finally { approving.value = false }
}

function openRejectModal(stage: 'advisor' | 'kaprodi') {
  rejectionStage.value = stage
  rejectionNote.value = ''
  rejectModal.value = true
}

async function handleReject() {
  if (!rejectionNote.value.trim()) { toast.warning('Catatan wajib diisi.'); return }
  try {
    const endpoint = rejectionStage.value === 'kaprodi' ? 'reject-kaprodi' : 'reject'
    await api.post(`/krs/${krs.value.id}/${endpoint}`, { note: rejectionNote.value })
    toast.success('KRS ditolak.')
    rejectModal.value = false; rejectionNote.value = ''
    load()
  } catch (err: any) { toast.error(extractErrorMessage(err)) }
}

async function handleSignKaprodi() {
  if (!confirm('Tanda tangani KRS ini sebagai Kaprodi?')) return
  try {
    await api.post(`/krs/${krs.value.id}/sign-kaprodi`)
    toast.success('KRS berhasil ditandatangani Kaprodi.')
    load()
  } catch (err: any) { toast.error(err?.response?.data?.message ?? 'Gagal.') }
}
</script>

<template>
  <div class="space-y-5">
    <!-- Header -->
    <div class="flex items-start gap-3">
      <button class="p-1.5 rounded-lg text-gray-500 hover:bg-gray-100 mt-0.5" @click="router.back()">
        <ArrowLeftIcon class="w-5 h-5" />
      </button>
      <div class="flex-1">
        <div class="flex items-center gap-3 flex-wrap">
          <h1 class="text-xl font-bold text-gray-900">KRS — {{ krs?.student?.name }}</h1>
          <span v-if="krs" :class="['px-2.5 py-1 rounded-full text-xs font-medium', getKrsStatusColor(krs.display_status ?? krs.status)]">{{ getKrsStatusLabel(krs.display_status ?? krs.status) }}</span>
        </div>
        <p class="text-sm text-gray-500 mt-0.5">{{ krs?.student?.nim }} · {{ krs?.student?.study_program?.name }} · {{ krs?.semester?.name }}</p>
      </div>
      <div class="flex gap-2 shrink-0">
        <button v-if="canEditCourses" class="flex items-center gap-1.5 px-3 py-2 bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium rounded-lg" @click="openAddModal">
          <PlusIcon class="w-4 h-4" /> Tambah MK
        </button>
        <button v-if="canEdit && krs?.details?.length" :disabled="submitting || !!krs?.schedule_conflicts?.length" class="flex items-center gap-1.5 px-3 py-2 bg-yellow-500 hover:bg-yellow-600 disabled:bg-gray-300 disabled:cursor-not-allowed text-white text-sm font-medium rounded-lg" @click="handleSubmit">
          <PaperAirplaneIcon class="w-4 h-4" /> {{ submitting ? 'Mengajukan...' : 'Ajukan KRS' }}
        </button>
        <template v-if="canApprove">
          <button :disabled="approving" class="flex items-center gap-1.5 px-3 py-2 bg-green-600 hover:bg-green-700 text-white text-sm font-medium rounded-lg" @click="handleApprove">
            <CheckIcon class="w-4 h-4" /> Setujui
          </button>
          <button class="flex items-center gap-1.5 px-3 py-2 bg-red-600 hover:bg-red-700 text-white text-sm font-medium rounded-lg" @click="openRejectModal('advisor')">
            <XMarkIcon class="w-4 h-4" /> Tolak
          </button>
        </template>
        <template v-if="canReviewKaprodi">
          <button :disabled="approving" class="flex items-center gap-1.5 px-3 py-2 bg-green-600 hover:bg-green-700 disabled:bg-green-300 text-white text-sm font-medium rounded-lg" @click="handleApproveKaprodi">
            <CheckIcon class="w-4 h-4" /> Setujui Prodi
          </button>
          <button class="flex items-center gap-1.5 px-3 py-2 bg-red-600 hover:bg-red-700 text-white text-sm font-medium rounded-lg" @click="openRejectModal('kaprodi')">
            <XMarkIcon class="w-4 h-4" /> Tolak Prodi
          </button>
        </template>
        <button v-if="canSignKaprodi" class="flex items-center gap-1.5 px-3 py-2 bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-medium rounded-lg" @click="handleSignKaprodi">
          ✍ Tanda Tangani sebagai Kaprodi
        </button>
      </div>
    </div>

    <div v-if="loading" class="text-center py-12 text-gray-400">Memuat KRS...</div>

    <template v-else-if="krs">
      <div v-if="krs?.schedule_conflicts?.length" class="rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-800">
        <p class="font-semibold">Jadwal KRS bentrok. Ubah kelas atau hapus salah satu mata kuliah sebelum submit.</p>
        <ul class="mt-2 space-y-1">
          <li v-for="(conflict, index) in krs.schedule_conflicts" :key="index">
            {{ conflict.day }}: {{ conflict.course_a }} ({{ conflict.class_a }}, {{ conflict.start_a }}–{{ conflict.end_a }})
            bentrok dengan {{ conflict.course_b }} ({{ conflict.class_b }}, {{ conflict.start_b }}–{{ conflict.end_b }}).
          </li>
        </ul>
      </div>

      <!-- Info cards -->
      <div class="grid grid-cols-2 sm:grid-cols-4 gap-4">
        <div class="bg-white rounded-xl border border-gray-200 p-4 text-center">
          <p class="text-3xl font-bold text-blue-600">{{ krs.total_credits }}</p>
          <p class="text-xs text-gray-500 mt-1">Total SKS</p>
        </div>
        <div class="bg-white rounded-xl border border-gray-200 p-4 text-center">
          <p class="text-3xl font-bold text-gray-700">{{ krs.details?.filter((d: any) => d.status === 'AKTIF').length ?? 0 }}</p>
          <p class="text-xs text-gray-500 mt-1">Mata Kuliah</p>
        </div>
        <div class="bg-white rounded-xl border border-gray-200 p-4 text-center">
          <p class="text-sm font-semibold text-gray-700 mt-1">{{ krs.advisor?.name ?? krs.advisor?.full_name ?? '-' }}</p>
          <p class="text-xs text-gray-500 mt-1">Dosen Wali</p>
        </div>
        <div v-if="krs.status === 'REJECTED' && (krs.advisor_note || krs.kaprodi_note)" class="bg-amber-50 border border-amber-200 rounded-xl p-4">
          <p class="text-xs font-semibold text-amber-700">{{ krs.rejected_by_stage === 'KAPRODI' ? 'Catatan Prodi:' : 'Catatan Dosen Wali:' }}</p>
          <p class="text-sm text-amber-800 mt-1">{{ krs.rejected_by_stage === 'KAPRODI' ? krs.kaprodi_note : krs.advisor_note }}</p>
        </div>
        <div v-if="krs.kaprodi_approved_at" class="bg-green-50 border border-green-200 rounded-xl p-4">
          <p class="text-xs font-semibold text-green-700">Persetujuan Prodi:</p>
          <p class="text-sm text-green-800 mt-1">
            Disetujui oleh {{ krs.kaprodi_approver?.name ?? 'petugas prodi' }}.
            {{ krs.signed_by_kaprodi_at ? 'KRS sudah ditandatangani.' : 'KRS menunggu tanda tangan Kaprodi.' }}
          </p>
        </div>
      </div>

      <!-- Daftar MK -->
      <div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
        <div class="px-5 py-4 border-b border-gray-200">
          <h2 class="font-semibold text-gray-900">Mata Kuliah yang Diambil</h2>
        </div>
        <div v-if="!krs.details?.length" class="text-center py-10 text-gray-400 text-sm">Belum ada mata kuliah.</div>
        <div v-else class="divide-y divide-gray-100">
          <div v-for="d in krs.details" :key="d.id"
            :class="['flex items-center gap-4 px-5 py-3 group', d.status === 'DIBATALKAN' ? 'opacity-50' : 'hover:bg-gray-50']">
            <div class="flex-1 min-w-0">
              <p class="font-medium text-gray-900 text-sm">{{ d.course?.name }}</p>
              <p class="text-xs text-gray-500 mt-0.5">
                {{ d.course?.code }}
                <template v-if="d.class_">· {{ d.class_.name }} · {{ d.class_.lecturer?.name ?? d.class_.lecturer?.full_name }}</template>
              </p>
            </div>
            <span class="text-sm text-gray-600 shrink-0">{{ d.course?.credits }} SKS</span>
            <button v-if="canEditCourses && d.status === 'AKTIF'"
              class="p-1.5 rounded-lg text-red-500 hover:bg-red-50 opacity-0 group-hover:opacity-100 shrink-0"
              @click="removeCourse(d.id, d.course?.name)">
              <TrashIcon class="w-4 h-4" />
            </button>
          </div>
        </div>
        <div class="px-5 py-3 bg-gray-50 border-t border-gray-200 flex items-center justify-between">
          <p class="text-sm text-gray-500">Batas: <span class="font-semibold">24 SKS</span></p>
          <p class="text-sm font-semibold text-gray-900">Total: {{ krs.total_credits }} SKS</p>
        </div>
      </div>
    </template>
  </div>

  <!-- Modal Tambah MK -->
  <BaseModal :open="addModal" title="Tambah Mata Kuliah" size="xl" @close="addModal = false">
    <div v-if="loadingCourses" class="text-center py-6 text-gray-400 text-sm">Memuat...</div>
    <div v-else-if="!availableCourses.length" class="text-center py-6 text-gray-400 text-sm">Tidak ada MK tersedia.</div>
    <div v-else class="max-h-96 overflow-y-auto divide-y divide-gray-100 border border-gray-200 rounded-lg">
      <div v-for="c in availableCourses" :key="c.id" class="flex items-center gap-3 px-4 py-3 hover:bg-gray-50">
        <div class="flex-1 min-w-0">
          <p class="font-medium text-gray-900 text-sm">{{ c.name }}</p>
          <p class="text-xs text-gray-500">{{ c.code }} · {{ c.credits }} SKS · Smt {{ c.semester }}</p>
        </div>
        <select v-model="selectedClassByCourse[c.id]" :disabled="isEnrolled(c.id)" class="w-48 px-2 py-1.5 border border-gray-300 rounded-lg text-xs">
          <option value="">Pilih kelas</option>
          <option v-for="classItem in c.classes" :key="classItem.id" :value="classItem.id">
            {{ classItem.name }} · {{ classOptionDetails(classItem) }} ({{ classItem.members_count }}/{{ classItem.capacity }})
          </option>
        </select>
        <button :disabled="isEnrolled(c.id)"
          :class="['px-3 py-1.5 rounded-lg text-xs font-medium shrink-0',
            isEnrolled(c.id) ? 'bg-gray-100 text-gray-400' : 'bg-blue-600 hover:bg-blue-700 text-white']"
          @click="addCourse(c.id)">
          {{ isEnrolled(c.id) ? 'Terdaftar' : 'Pilih' }}
        </button>
      </div>
    </div>
    <template #footer>
      <button class="px-4 py-2 text-sm text-gray-600 hover:bg-gray-100 rounded-lg" @click="addModal = false">Selesai</button>
    </template>
  </BaseModal>

  <!-- Modal Tolak -->
  <BaseModal :open="rejectModal" :title="rejectionStage === 'kaprodi' ? 'Tolak KRS pada Tahap Prodi' : 'Tolak KRS'" @close="rejectModal = false">
    <div>
      <label class="block text-sm font-medium text-gray-700 mb-2">Catatan <span class="text-red-500">*</span></label>
      <textarea v-model="rejectionNote" rows="4" placeholder="Alasan penolakan..." class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-blue-500" />
    </div>
    <template #footer>
      <button class="px-4 py-2 text-sm text-gray-600 hover:bg-gray-100 rounded-lg" @click="rejectModal = false">Batal</button>
      <button class="px-4 py-2 bg-red-600 hover:bg-red-700 text-white text-sm font-medium rounded-lg" @click="handleReject">Tolak</button>
    </template>
  </BaseModal>
</template>
