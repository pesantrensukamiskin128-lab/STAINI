<script setup lang="ts">
import { ref, onMounted, onUnmounted, computed, nextTick } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { ArrowLeftIcon, PlusIcon, TrashIcon, PencilIcon, CheckCircleIcon, BookOpenIcon, ClipboardDocumentListIcon, MegaphoneIcon, AcademicCapIcon, ArrowDownTrayIcon, ChatBubbleLeftRightIcon } from '@heroicons/vue/24/outline'
import { useToast } from 'vue-toastification'
import { useAuthStore } from '@/stores/auth'
import BaseModal from '@/components/ui/BaseModal.vue'
import api from '@/services/api'
import { discussionTextParts } from '@/utils/discussionText'
import { toDateInput, toDateTimeLocalInput } from '@/utils/formValue'

const route  = useRoute()
const router = useRouter()
const toast  = useToast()
const auth   = useAuthStore()

const classData = ref<any>(null)
const loading   = ref(true)
const activeTab = ref('ringkasan')
const summaryLoading = ref(false)

// Data
const journals      = ref<any[]>([])
const materials     = ref<any[]>([])
const assignments   = ref<any[]>([])
const announcements = ref<any[]>([])
const discussions = ref<any[]>([])
const selectedDiscussion = ref<any>(null)
const discussionsLoading = ref(false)
const discussionModal = ref(false)
const savingDiscussion = ref(false)
const discussionForm = ref({ title: '', content: '' })
const topicTextarea = ref<HTMLTextAreaElement | null>(null)
const replyTextarea = ref<HTMLTextAreaElement | null>(null)
const showTopicEmojis = ref(false)
const showReplyEmojis = ref(false)
const discussionReply = ref('')
const savingDiscussionReply = ref(false)
const discussionEmojis = ['😀', '😊', '😂', '😍', '🤔', '👍', '👏', '🙏', '🎉', '❤️', '✅', '🔥']
const exams         = ref<any[]>([])
const gradebookLoading = ref(false)
const gradebookData = ref<any>({ assignments: [], exams: [], students: [], components: [], cpmks: [], sub_cpmks: [] })
const classSubCpmks = ref<any[]>([])
const subCpmkOptionsLoading = ref(false)
const savingGradebookWeights = ref(false)
const submissionReviewModal = ref(false)
const submissionReviewLoading = ref(false)
const reviewAssignment = ref<any>(null)
const reviewSubmissions = ref<any[]>([])
const gradingSubmissionId = ref<number | null>(null)
const downloadingMembers = ref(false)
const downloadingJournals = ref(false)

const tabs = [
  { key: 'ringkasan', label: 'Ringkasan', icon: BookOpenIcon },
  { key: 'jurnal', label: 'Jurnal & Presensi', icon: ClipboardDocumentListIcon },
  { key: 'materi', label: 'Materi', icon: BookOpenIcon },
  { key: 'tugas', label: 'Tugas', icon: ClipboardDocumentListIcon },
  { key: 'diskusi', label: 'Diskusi', icon: ChatBubbleLeftRightIcon },
  { key: 'ujian', label: 'Ujian', icon: AcademicCapIcon },
  { key: 'pengumuman', label: 'Pengumuman', icon: MegaphoneIcon },
  { key: 'gradebook', label: 'Gradebook', icon: AcademicCapIcon },
]

onMounted(async () => {
  const requestedTab = String(route.query.tab ?? '')
  if (tabs.some(tab => tab.key === requestedTab)) activeTab.value = requestedTab
  try {
    const { data } = await api.get(`/lectures/${route.params.id}`)
    classData.value = data
    loadTab()
  } catch { toast.error('Gagal memuat data kelas.') }
  finally { loading.value = false }
})

async function loadTab() {
  const id = route.params.id
  if (activeTab.value === 'ringkasan') {
    await loadSummary()
  } else if (activeTab.value === 'jurnal') {
    const { data } = await api.get(`/lectures/${id}/journals`)
    journals.value = data
  } else if (activeTab.value === 'materi') {
    const { data } = await api.get(`/lectures/${id}/materials`)
    materials.value = data
  } else if (activeTab.value === 'tugas') {
    const { data } = await api.get(`/lectures/${id}/assignments`)
    assignments.value = data
  } else if (activeTab.value === 'gradebook') {
    await loadGradebook()
  } else if (activeTab.value === 'diskusi') {
    await loadDiscussions()
  } else if (activeTab.value === 'ujian') {
    const { data } = await api.get('/exams', { params: { class_id: id } })
    exams.value = data.data ?? data
  } else if (activeTab.value === 'pengumuman') {
    const { data } = await api.get(`/lectures/${id}/announcements`)
    announcements.value = data
  }

}

function switchTab(key: string) {
  activeTab.value = key
  loadTab().catch(() => toast.error('Gagal memuat data pembelajaran.'))
}

async function loadDiscussions(selectId?: number) {
  discussionsLoading.value = true
  try {
    const { data } = await api.get(`/lectures/${route.params.id}/discussions`)
    discussions.value = data
    const queryDiscussionId = Number(route.query.discussion_id) || undefined
    const discussionId = selectId ?? queryDiscussionId ?? selectedDiscussion.value?.id ?? data[0]?.id
    selectedDiscussion.value = discussionId
      ? (await api.get(`/lectures/${route.params.id}/discussions/${discussionId}`)).data
      : null
  } finally {
    discussionsLoading.value = false
  }
}

async function selectDiscussion(discussion: any) {
  try {
    selectedDiscussion.value = (await api.get(`/lectures/${route.params.id}/discussions/${discussion.id}`)).data
  } catch (e: any) {
    toast.error(e?.response?.data?.message ?? 'Gagal memuat topik diskusi.')
  }
}

async function createDiscussion() {
  if (savingDiscussion.value) return
  savingDiscussion.value = true
  try {
    const { data } = await api.post(`/lectures/${route.params.id}/discussions`, discussionForm.value)
    discussionForm.value = { title: '', content: '' }
    discussionModal.value = false
    await loadDiscussions(data.data.id)
    toast.success('Topik diskusi berhasil dibuat.')
  } catch (e: any) {
    const firstError = e?.response?.data?.errors
      ? Object.values(e.response.data.errors).flat()[0]
      : undefined
    toast.error(firstError ?? e?.response?.data?.message ?? 'Gagal membuat topik diskusi.')
  } finally {
    savingDiscussion.value = false
  }
}

async function sendDiscussionReply() {
  const content = discussionReply.value.trim()
  if (!content || !selectedDiscussion.value || savingDiscussionReply.value) return
  savingDiscussionReply.value = true
  try {
    await api.post(`/lectures/${route.params.id}/discussions/${selectedDiscussion.value.id}/replies`, { content })
    discussionReply.value = ''
    await loadDiscussions(selectedDiscussion.value.id)
    toast.success('Balasan berhasil dikirim.')
  } catch (e: any) {
    toast.error(e?.response?.data?.message ?? 'Gagal mengirim balasan.')
  } finally {
    savingDiscussionReply.value = false
  }
}

async function moderateDiscussion(discussion: any, field: 'is_pinned' | 'is_locked') {
  const updated = { is_pinned: discussion.is_pinned, is_locked: discussion.is_locked }
  updated[field] = !updated[field]
  try {
    await api.patch(`/lectures/${route.params.id}/discussions/${discussion.id}/moderation`, updated)
    await loadDiscussions(discussion.id)
    toast.success('Pengaturan diskusi diperbarui.')
  } catch (e: any) {
    toast.error(e?.response?.data?.message ?? 'Gagal memperbarui diskusi.')
  }
}

async function deleteDiscussion(discussion: any) {
  if (!confirm(`Hapus topik "${discussion.title}" beserta seluruh balasannya?`)) return
  try {
    await api.delete(`/lectures/${route.params.id}/discussions/${discussion.id}`)
    selectedDiscussion.value = null
    await loadDiscussions()
    toast.success('Topik diskusi berhasil dihapus.')
  } catch (e: any) {
    toast.error(e?.response?.data?.message ?? 'Gagal menghapus topik diskusi.')
  }
}

async function deleteDiscussionReply(reply: any) {
  if (!confirm('Hapus balasan ini?')) return
  try {
    await api.delete(`/lectures/${route.params.id}/discussions/${selectedDiscussion.value.id}/replies/${reply.id}`)
    await loadDiscussions(selectedDiscussion.value.id)
    toast.success('Balasan berhasil dihapus.')
  } catch (e: any) {
    toast.error(e?.response?.data?.message ?? 'Gagal menghapus balasan.')
  }
}

function insertDiscussionEmoji(emoji: string, target: 'topic' | 'reply') {
  const textarea = target === 'topic' ? topicTextarea.value : replyTextarea.value
  if (!textarea) return

  const value = target === 'topic' ? discussionForm.value.content : discussionReply.value
  const start = textarea.selectionStart
  const end = textarea.selectionEnd
  const updated = `${value.slice(0, start)}${emoji}${value.slice(end)}`

  if (target === 'topic') {
    discussionForm.value.content = updated
    showTopicEmojis.value = false
  } else {
    discussionReply.value = updated
    showReplyEmojis.value = false
  }

  nextTick(() => {
    textarea.focus()
    textarea.setSelectionRange(start + emoji.length, start + emoji.length)
  })
}

async function openGradebook() {
  activeTab.value = 'gradebook'
  await loadGradebook()
}

async function loadGradebook() {
  gradebookLoading.value = true
  try {
    const { data } = await api.get(`/lectures/${route.params.id}/gradebook`)
    gradebookData.value = data
  } catch {
    toast.error('Gagal memuat gradebook.')
  } finally {
    gradebookLoading.value = false
  }
}

async function loadClassSubCpmks() {
  subCpmkOptionsLoading.value = true
  classSubCpmks.value = []
  try {
    const { data } = await api.get(`/lectures/${route.params.id}/sub-cpmks`)
    classSubCpmks.value = data
  } catch (e: any) {
    toast.error(e?.response?.data?.message ?? 'Daftar Sub-CPMK gagal dimuat.')
  } finally {
    subCpmkOptionsLoading.value = false
  }
}

async function saveGradebookWeights() {
  if (savingGradebookWeights.value) return
  const components = gradebookData.value.components
  if (components.reduce((total: number, component: any) => total + Number(component.weight), 0) !== 100) {
    toast.error('Jumlah seluruh bobot harus tepat 100%.')
    return
  }

  savingGradebookWeights.value = true
  try {
    await api.put(`/lectures/${route.params.id}/gradebook/weights`, { components })
    toast.success('Komponen dan bobot nilai kelas berhasil disimpan.')
    await loadGradebook()
  } catch (e: any) {
    const errors = e?.response?.data?.errors as Record<string, string[]> | undefined
    const firstError = errors ? Object.values(errors).flat()[0] : undefined
    toast.error(firstError ?? e?.response?.data?.message ?? 'Gagal menyimpan bobot nilai.')
  } finally {
    savingGradebookWeights.value = false
  }
}

function gradeFor(student: any, assignmentId: number) {
  return student.grades.find((grade: any) => grade.assignment_id === assignmentId)
}

function examScoreFor(student: any, examId: number) {
  return student.exam_scores.find((score: any) => score.exam_id === examId)
}

function goToFinalGradeInput() {
  router.push({
    path: '/penilaian/nilai',
    query: { class_id: String(classData.value.id), semester_id: String(classData.value.semester_id) },
  })
}

async function saveGrade(grade: any) {
  if (!grade.submission_id || grade.score === null || grade.score === '') return
  try {
    await api.post(`/assignments/submissions/${grade.submission_id}/grade`, { score: grade.score })
    grade.graded_at = new Date().toISOString()
    toast.success('Nilai tersimpan.')
    await openGradebook()
  } catch {
    toast.error('Gagal menyimpan nilai.')
  }
}

async function openSubmissionReview(assignment: any) {
  reviewAssignment.value = assignment
  submissionReviewLoading.value = true
  try {
    const { data } = await api.get(`/lectures/${route.params.id}/assignments/${assignment.id}/submissions`)
    reviewSubmissions.value = data
    submissionReviewModal.value = true
  } catch (e: any) {
    toast.error(e?.response?.data?.message ?? 'Gagal memuat pengumpulan tugas.')
  } finally {
    submissionReviewLoading.value = false
  }
}

async function saveSubmissionGrade(submission: any) {
  if (submission.score === null || submission.score === '' || submission.score === undefined) {
    toast.error('Masukkan nilai sebelum menyimpan.')
    return
  }
  gradingSubmissionId.value = submission.id
  try {
    await api.post(`/assignments/submissions/${submission.id}/grade`, {
      score: submission.score,
      feedback: submission.feedback ?? '',
    })
    submission.graded_at = new Date().toISOString()
    toast.success(`Nilai ${submission.student?.name ?? 'mahasiswa'} berhasil disimpan.`)
  } catch (e: any) {
    const errors = e?.response?.data?.errors as Record<string, string[]> | undefined
    const firstError = errors ? Object.values(errors).flat()[0] : undefined
    toast.error(firstError ?? e?.response?.data?.message ?? 'Gagal menyimpan nilai dan feedback.')
  } finally {
    gradingSubmissionId.value = null
  }
}

async function loadSummary() {
  summaryLoading.value = true
  const id = route.params.id
  const requests = await Promise.allSettled([
    api.get(`/lectures/${id}/journals`),
    api.get(`/lectures/${id}/materials`),
    api.get(`/lectures/${id}/assignments`),
    api.get('/exams', { params: { class_id: id } }),
    api.get(`/lectures/${id}/announcements`),
  ])

  const [journalResult, materialResult, assignmentResult, examResult, announcementResult] = requests
  if (journalResult.status === 'fulfilled') journals.value = journalResult.value.data
  if (materialResult.status === 'fulfilled') materials.value = materialResult.value.data
  if (assignmentResult.status === 'fulfilled') assignments.value = assignmentResult.value.data
  if (examResult.status === 'fulfilled') exams.value = examResult.value.data.data ?? examResult.value.data
  if (announcementResult.status === 'fulfilled') announcements.value = announcementResult.value.data

  if (requests.some((result) => result.status === 'rejected')) {
    toast.warning('Sebagian aktivitas kelas tidak dapat dimuat.')
  }
  summaryLoading.value = false
}

const summaryCards = computed(() => [
  { label: 'Pertemuan', value: journals.value.length, color: 'blue' },
  { label: 'Materi', value: materials.value.length, color: 'emerald' },
  { label: 'Tugas', value: assignments.value.length, color: 'amber' },
  { label: 'Ujian', value: exams.value.length, color: 'violet' },
])

const upcomingActivities = computed(() => {
  const now = Date.now()
  const items = [
    ...assignments.value
      .filter((item) => item.due_date && new Date(item.due_date).getTime() >= now)
      .map((item) => ({ type: 'Tugas', title: item.title, date: item.due_date, tab: 'tugas' })),
    ...exams.value
      .filter((item) => item.start_time && new Date(item.start_time).getTime() >= now)
      .map((item) => ({ type: 'Ujian', title: item.title, date: item.start_time, tab: 'ujian' })),
    ...journals.value
      .filter((item) => item.meeting_date && new Date(item.meeting_date).getTime() >= now)
      .map((item) => ({ type: 'Pertemuan', title: item.topic, date: item.meeting_date, tab: 'jurnal' })),
  ]

  return items
    .sort((left, right) => new Date(left.date).getTime() - new Date(right.date).getTime())
    .slice(0, 5)
})

function formatActivityDate(value: string) {
  return new Date(value).toLocaleString('id-ID', {
    dateStyle: 'medium',
    timeStyle: 'short',
  })
}

async function downloadParticipants(format: 'pdf' | 'excel') {
  downloadingMembers.value = true
  try {
    const response = await api.get(`/lectures/${route.params.id}/members/${format}`, { responseType: 'blob' })
    const link = document.createElement('a')
    link.href = URL.createObjectURL(response.data)
    link.download = `peserta-${route.params.id}.${format === 'pdf' ? 'pdf' : 'xlsx'}`
    document.body.appendChild(link); link.click(); link.remove()
    URL.revokeObjectURL(link.href)
  } catch (e: any) {
    toast.error(e?.response?.data?.message ?? 'Gagal mengunduh daftar peserta.')
  } finally {
    downloadingMembers.value = false
  }

}

async function downloadJournals(format: 'pdf' | 'excel') {
  downloadingJournals.value = true
  try {
    const response = await api.get(`/lectures/${route.params.id}/journals/${format}`, { responseType: 'blob' })
    const link = document.createElement('a')
    link.href = URL.createObjectURL(response.data)
    link.download = `jurnal-pembelajaran-${route.params.id}.${format === 'pdf' ? 'pdf' : 'xlsx'}`
    document.body.appendChild(link); link.click(); link.remove()
    URL.revokeObjectURL(link.href)
  } catch (e: any) {
    toast.error(e?.response?.data?.message ?? 'Gagal mengunduh jurnal pembelajaran.')
  } finally {
    downloadingJournals.value = false
  }
}

// === Jurnal ===
const journalModal = ref(false)
const journalForm = ref({ meeting_number: 1, meeting_date: '', topic: '', description: '', learning_activity: '', status: 'PLANNED', delivery_mode: '', latitude: null as number | null, longitude: null as number | null })
const journalPhoto = ref<File | null>(null)
const journalPhotoPreview = ref<string | null>(null)
const rpsPlans = ref<any[]>([])
const rpsSources = ref<any[]>([])
const selectedRpsId = ref<number | null>(null)
const gettingLocation = ref(false)

// Load rencana dari RPS
async function loadRpsPlans() {
  try {
    const { data } = await api.get(`/lectures/${route.params.id}/rps-plans`)
    rpsSources.value = data.sources ?? []
    const primary = rpsSources.value.find((source: any) => source.rpkps_code === data.rpkps_code) ?? rpsSources.value[0]
    selectedRpsId.value = primary?.id ?? null
    rpsPlans.value = primary?.plans ?? data.plans ?? []
  } catch {
    rpsSources.value = []
    selectedRpsId.value = null
    rpsPlans.value = []
  }
}

function changeRpsSource() {
  const source = rpsSources.value.find((item: any) => item.id === selectedRpsId.value)
  rpsPlans.value = source?.plans ?? []
  fillFromRps()
}

// Auto-fill dari RPS berdasarkan meeting_number
function fillFromRps() {
  const plan = rpsPlans.value.find((p: any) => p.meeting_number === journalForm.value.meeting_number)
  if (plan) {
    journalForm.value.topic = plan.topic || ''
    journalForm.value.description = plan.description || ''
    journalForm.value.learning_activity = plan.learning_activity || ''
  }
}

// Ambil lokasi GPS
function getLocation() {
  if (!navigator.geolocation) { toast.error('Geolocation tidak didukung browser.'); return }
  gettingLocation.value = true
  navigator.geolocation.getCurrentPosition(
    (pos) => {
      journalForm.value.latitude = pos.coords.latitude
      journalForm.value.longitude = pos.coords.longitude
      gettingLocation.value = false
      toast.success('Lokasi berhasil diambil.')
    },
    (err) => {
      gettingLocation.value = false
      toast.error('Gagal ambil lokasi: ' + err.message)
    },
    { enableHighAccuracy: true, timeout: 10000 }
  )
}

// Handle photo
const cameraStream = ref<MediaStream | null>(null)
const videoRef = ref<HTMLVideoElement | null>(null)
const showCamera = ref(false)
const cameraFacingMode = ref<'environment' | 'user'>('environment')
const cameraSwitching = ref(false)

function onJournalPhoto(e: Event) {
  const file = (e.target as HTMLInputElement).files?.[0]
  if (!file) return
  if (journalPhotoPreview.value) URL.revokeObjectURL(journalPhotoPreview.value)
  journalPhoto.value = file
  journalPhotoPreview.value = URL.createObjectURL(file)
}

async function openCamera() {
  if (!navigator.mediaDevices?.getUserMedia) {
    toast.error('Browser ini tidak mendukung akses kamera.')
    return
  }

  cameraSwitching.value = true
  try {
    const stream = await navigator.mediaDevices.getUserMedia({
      video: { facingMode: { ideal: cameraFacingMode.value } },
      audio: false,
    })
    cameraStream.value?.getTracks().forEach(track => track.stop())
    cameraStream.value = stream
    showCamera.value = true
    await nextTick()
    if (videoRef.value) {
      videoRef.value.srcObject = stream
      await videoRef.value.play()
    }
  } catch (err: any) {
    cameraStream.value?.getTracks().forEach(track => track.stop())
    cameraStream.value = null
    showCamera.value = false
    toast.error('Tidak bisa mengakses kamera: ' + (err.message || 'izin kamera ditolak'))
  } finally {
    cameraSwitching.value = false
  }
}

async function switchCamera() {
  cameraFacingMode.value = cameraFacingMode.value === 'environment' ? 'user' : 'environment'
  const previousStream = cameraStream.value
  cameraStream.value = null
  previousStream?.getTracks().forEach(track => track.stop())
  await openCamera()
}

function capturePhoto() {
  if (!videoRef.value?.videoWidth || !videoRef.value.videoHeight) {
    toast.error('Kamera belum siap. Coba lagi sebentar.')
    return
  }
  const canvas = document.createElement('canvas')
  canvas.width = videoRef.value.videoWidth
  canvas.height = videoRef.value.videoHeight
  canvas.getContext('2d')?.drawImage(videoRef.value, 0, 0)
  canvas.toBlob((blob) => {
    if (!blob) return
    const file = new File([blob], `foto-${Date.now()}.jpg`, { type: 'image/jpeg' })
    if (journalPhotoPreview.value) URL.revokeObjectURL(journalPhotoPreview.value)
    journalPhoto.value = file
    journalPhotoPreview.value = URL.createObjectURL(file)
    closeCamera()
  }, 'image/jpeg', 0.85)
}

function closeCamera() {
  if (cameraStream.value) {
    cameraStream.value.getTracks().forEach(t => t.stop())
    cameraStream.value = null
  }
  showCamera.value = false
}

function closeJournalModal() {
  closeCamera()
  journalModal.value = false
}

onUnmounted(() => {
  closeCamera()
  if (journalPhotoPreview.value) URL.revokeObjectURL(journalPhotoPreview.value)
})

async function deleteItem(type: 'journal' | 'material' | 'assignment' | 'announcement', id: number) {
  const labels: Record<string, string> = {
    journal: 'jurnal pertemuan', material: 'materi', assignment: 'tugas', announcement: 'pengumuman',
  }
  const label = labels[type] ?? 'data'
  if (!confirm(`Hapus ${label} ini? Data tidak dapat dikembalikan.`)) return
  const urls: Record<string, string> = {
    journal: `/lectures/journals/${id}`,
    material: `/lectures/materials/${id}`,
    assignment: `/lectures/assignments/${id}`,
    announcement: `/lectures/announcements/${id}`,
  }
  const url = urls[type]
  if (!url) return
  try {
    await api.delete(url)
    toast.success(`${label.charAt(0).toUpperCase() + label.slice(1)} berhasil dihapus.`)
    loadTab()
  } catch (e: any) {
    toast.error(e?.response?.data?.message ?? 'Gagal menghapus.')
  }
}

// === Edit items ===
const editModal = ref(false)
const editType = ref<'journal' | 'material' | 'assignment' | 'announcement' | null>(null)
const editId = ref<number | null>(null)
const editForm = ref<any>({})
const savingEdit = ref(false)

async function openEdit(type: 'journal' | 'material' | 'assignment' | 'announcement', item: any) {
  editType.value = type
  editId.value = item.id
  if (type === 'assignment') await loadClassSubCpmks()
  if (type === 'journal') {
    editForm.value = { meeting_number: item.meeting_number, meeting_date: toDateInput(item.meeting_date), topic: item.topic, description: item.description ?? '', learning_activity: item.learning_activity ?? '', status: item.status, delivery_mode: item.delivery_mode ?? '' }
  } else if (type === 'material') {
    editForm.value = { title: item.title, description: item.description ?? '', file_url: item.file_url ?? '', material_type: item.material_type ?? 'LINK', available_at: item.available_at ? toDateTimeLocalInput(item.available_at) : '', file: null }
  } else if (type === 'assignment') {
    editForm.value = { title: item.title, description: item.description ?? '', instructions: item.instructions ?? '', due_date: toDateTimeLocalInput(item.due_date), max_score: item.max_score ?? 100, sub_cpmk_ids: (item.sub_cpmks ?? []).map((subCpmk: any) => subCpmk.id) }
  } else if (type === 'announcement') {
    editForm.value = { title: item.title, content: item.content }
  }
  editModal.value = true
}

async function saveEdit() {
  if (!editId.value || !editType.value) return
  const urls: Record<string, string> = {
    journal: `/lectures/journals/${editId.value}`,
    material: `/lectures/materials/${editId.value}`,
    assignment: `/lectures/assignments/${editId.value}`,
    announcement: `/lectures/announcements/${editId.value}`,
  }
  const url = urls[editType.value]
  if (!url) return
  savingEdit.value = true
  try {
    await api.put(url, editForm.value)
    toast.success('Berhasil diupdate.')
    editModal.value = false
    loadTab()
  } catch (e: any) {
    toast.error(e?.response?.data?.message ?? 'Gagal mengupdate.')
  } finally { savingEdit.value = false }
}
async function saveJournal() {
  try {
    const fd = new FormData()
    fd.append('meeting_number', String(journalForm.value.meeting_number))
    fd.append('meeting_date', journalForm.value.meeting_date)
    fd.append('topic', journalForm.value.topic)
    fd.append('description', journalForm.value.description || '')
    fd.append('learning_activity', journalForm.value.learning_activity || '')
    fd.append('status', journalForm.value.status)
    fd.append('delivery_mode', journalForm.value.delivery_mode)
    if (journalForm.value.latitude) fd.append('latitude', String(journalForm.value.latitude))
    if (journalForm.value.longitude) fd.append('longitude', String(journalForm.value.longitude))
    if (journalPhoto.value) fd.append('photo', journalPhoto.value)

    await api.post(`/lectures/${route.params.id}/journals`, fd, { headers: { 'Content-Type': 'multipart/form-data' } })
    toast.success('Jurnal berhasil disimpan.')
    closeJournalModal()
    journalPhoto.value = null
    if (journalPhotoPreview.value) URL.revokeObjectURL(journalPhotoPreview.value)
    journalPhotoPreview.value = null
    loadTab()
  } catch (e: any) { toast.error(e?.response?.data?.message ?? 'Gagal.') }
}

async function openJournalModal() {
  closeCamera()
  if (journalPhotoPreview.value) URL.revokeObjectURL(journalPhotoPreview.value)
  journalForm.value = { meeting_number: (journals.value?.length ?? 0) + 1, meeting_date: new Date(Date.now() - new Date().getTimezoneOffset() * 60000).toISOString().slice(0, 10), topic: '', description: '', learning_activity: '', status: 'PLANNED', delivery_mode: '', latitude: null, longitude: null }
  journalPhoto.value = null
  journalPhotoPreview.value = null
  await loadRpsPlans()
  fillFromRps()
  journalModal.value = true
}

// === Materi ===
const materialModal = ref(false)
const savingMaterial = ref(false)
const materialForm = ref({ title: '', description: '', file_url: '', material_type: 'LINK', journal_id: '', available_at: '', is_published: true, file: null as File | null })

function onMaterialFile(event: Event) {
  materialForm.value.file = (event.target as HTMLInputElement).files?.[0] ?? null
}

async function saveMaterial() {
  if (savingMaterial.value) return
  savingMaterial.value = true
  try {
    const payload = new FormData()
    Object.entries(materialForm.value).forEach(([key, value]) => {
      if (value === null || value === '') return
      if (value instanceof File) {
        payload.append(key, value)
      } else if (typeof value === 'boolean') {
        payload.append(key, value ? '1' : '0')
      } else {
        payload.append(key, String(value))
      }
    })
    await api.post(`/lectures/${route.params.id}/materials`, payload)
    toast.success('Materi berhasil ditambahkan.')
    materialModal.value = false
    materialForm.value = { title: '', description: '', file_url: '', material_type: 'LINK', journal_id: '', available_at: '', is_published: true, file: null }
    loadTab()
  } catch (e: any) {
    const errors = e?.response?.data?.errors as Record<string, string[]> | undefined
    const firstError = errors ? Object.values(errors).flat()[0] : undefined
    toast.error(firstError ?? e?.response?.data?.message ?? 'Gagal menyimpan materi.')
  } finally {
    savingMaterial.value = false
  }
}

// === Tugas ===
const assignmentModal = ref(false)
const savingAssignment = ref(false)
const assignmentForm = ref({ title: '', description: '', instructions: '', type: 'INDIVIDU', due_date: '', max_score: 100, is_published: true, allow_late: false, sub_cpmk_ids: [] as number[], attachment: null as File | null })
const assignmentTemplates = ref<any[]>([])
const assignmentTemplatesLoading = ref(false)
const savingAssignmentTemplate = ref(false)
const assignmentTemplateName = ref('')
const selectedAssignmentTemplateId = ref<number | null>(null)
const submissionModal = ref(false)
const savingSubmission = ref(false)
const submissionAssignment = ref<any>(null)
const submissionForm = ref({ content: '', file_url: '', file: null as File | null })

async function saveAssignment() {
  if (savingAssignment.value) return
  savingAssignment.value = true
  try {
    const payload = new FormData()
    Object.entries(assignmentForm.value).forEach(([key, value]) => {
      if (key === 'sub_cpmk_ids') return
      if (value === null || value === '') return
      if (value instanceof File) {
        payload.append(key, value)
      } else if (typeof value === 'boolean') {
        payload.append(key, value ? '1' : '0')
      } else {
        payload.append(key, String(value))
      }
    })
    assignmentForm.value.sub_cpmk_ids.forEach(id => payload.append('sub_cpmk_ids[]', String(id)))
    await api.post(`/lectures/${route.params.id}/assignments`, payload)
    toast.success('Tugas berhasil dibuat.')
    assignmentModal.value = false
    assignmentForm.value = { title: '', description: '', instructions: '', type: 'INDIVIDU', due_date: '', max_score: 100, is_published: true, allow_late: false, sub_cpmk_ids: [], attachment: null }
    loadTab()
  } catch (e: any) {
    const errors = e?.response?.data?.errors as Record<string, string[]> | undefined
    const firstError = errors ? Object.values(errors).flat()[0] : undefined
    toast.error(firstError ?? e?.response?.data?.message ?? 'Gagal membuat tugas.')
  } finally {
    savingAssignment.value = false
  }
}

async function openCreateAssignment() {
  assignmentForm.value = { title: '', description: '', instructions: '', type: 'INDIVIDU', due_date: '', max_score: 100, is_published: true, allow_late: false, sub_cpmk_ids: [], attachment: null }
  selectedAssignmentTemplateId.value = null
  assignmentTemplateName.value = ''
  await Promise.all([loadClassSubCpmks(), loadAssignmentTemplates()])
  assignmentModal.value = true
}

async function loadAssignmentTemplates() {
  assignmentTemplatesLoading.value = true
  try {
    const { data } = await api.get('/assignment-templates')
    assignmentTemplates.value = data
  } catch (e: any) {
    toast.error(e?.response?.data?.message ?? 'Gagal memuat template tugas.')
  } finally {
    assignmentTemplatesLoading.value = false
  }
}

function applyAssignmentTemplate() {
  const template = assignmentTemplates.value.find(item => item.id === selectedAssignmentTemplateId.value)
  if (!template) return
  assignmentForm.value = {
    ...assignmentForm.value,
    title: template.title,
    description: template.description ?? '',
    instructions: template.instructions ?? '',
    type: template.type,
    max_score: template.max_score,
    allow_late: template.allow_late,
    due_date: '',
    sub_cpmk_ids: [],
    attachment: null,
  }
  toast.success('Template diterapkan. Atur deadline dan Sub-CPMK untuk kelas ini.')
}

async function saveAssignmentTemplate() {
  if (savingAssignmentTemplate.value) return
  savingAssignmentTemplate.value = true
  try {
    const { data } = await api.post('/assignment-templates', {
      name: assignmentTemplateName.value,
      title: assignmentForm.value.title,
      description: assignmentForm.value.description || null,
      instructions: assignmentForm.value.instructions || null,
      type: assignmentForm.value.type,
      max_score: assignmentForm.value.max_score,
      allow_late: assignmentForm.value.allow_late,
    })
    assignmentTemplates.value = [...assignmentTemplates.value, data.data].sort((a, b) => a.name.localeCompare(b.name))
    selectedAssignmentTemplateId.value = data.data.id
    assignmentTemplateName.value = ''
    toast.success('Template tugas berhasil disimpan untuk akun Anda.')
  } catch (e: any) {
    const errors = e?.response?.data?.errors as Record<string, string[]> | undefined
    const firstError = errors ? Object.values(errors).flat()[0] : undefined
    toast.error(firstError ?? e?.response?.data?.message ?? 'Gagal menyimpan template tugas.')
  } finally {
    savingAssignmentTemplate.value = false
  }
}

async function deleteAssignmentTemplate() {
  const template = assignmentTemplates.value.find(item => item.id === selectedAssignmentTemplateId.value)
  if (!template || !confirm(`Hapus template "${template.name}"?`)) return
  try {
    await api.delete(`/assignment-templates/${template.id}`)
    assignmentTemplates.value = assignmentTemplates.value.filter(item => item.id !== template.id)
    selectedAssignmentTemplateId.value = null
    toast.success('Template tugas berhasil dihapus.')
  } catch (e: any) {
    toast.error(e?.response?.data?.message ?? 'Gagal menghapus template tugas.')
  }
}

function onAssignmentAttachment(event: Event) {
  assignmentForm.value.attachment = (event.target as HTMLInputElement).files?.[0] ?? null
}

function openSubmission(assignment: any) {
  const previous = assignment.submissions?.[0]
  submissionAssignment.value = assignment
  submissionForm.value = { content: previous?.content ?? '', file_url: previous?.file_url ?? '', file: null }
  submissionModal.value = true
}

function onSubmissionFile(event: Event) {
  submissionForm.value.file = (event.target as HTMLInputElement).files?.[0] ?? null
}

async function saveSubmission() {
  if (!submissionAssignment.value) return
  if (!submissionForm.value.content.trim() && !submissionForm.value.file_url.trim() && !submissionForm.value.file) {
    toast.error('Isi jawaban atau lampirkan file sebelum mengirim.')
    return
  }
  if (savingSubmission.value) return
  savingSubmission.value = true
  try {
    const payload = new FormData()
    Object.entries(submissionForm.value).forEach(([key, value]) => {
      if (value !== null && value !== '') payload.append(key, value instanceof File ? value : String(value))
    })
    await api.post(`/assignments/${submissionAssignment.value.id}/submit`, payload)
    toast.success('Tugas berhasil dikumpulkan.')
    submissionModal.value = false
    loadTab()
  } catch (e: any) {
    const errors = e?.response?.data?.errors as Record<string, string[]> | undefined
    const firstError = errors ? Object.values(errors).flat()[0] : undefined
    toast.error(firstError ?? e?.response?.data?.message ?? 'Gagal mengumpulkan tugas.')
  } finally {
    savingSubmission.value = false
  }
}

// === Pengumuman ===
const annModal = ref(false)
const annForm = ref({ title: '', content: '' })

async function saveAnnouncement() {
  try {
    await api.post(`/lectures/${route.params.id}/announcements`, annForm.value)
    toast.success('Pengumuman berhasil dibuat.')
    annModal.value = false
    annForm.value = { title: '', content: '' }
    loadTab()
  } catch (e: any) { toast.error(e?.response?.data?.message ?? 'Gagal.') }
}

// === Ujian ===
const examModal = ref(false)
const examEditId = ref<number | null>(null)
const examForm = ref({ title: '', type: 'UTS', description: '', start_time: '', end_time: '', duration_minutes: 60, is_online: true, shuffle_questions: true, shuffle_options: true, show_score: false, sub_cpmk_ids: [] as number[] })
const examTypes = ['UTS', 'UAS', 'QUIZ', 'TUGAS_BESAR']
const savingExam = ref(false)

async function openCreateExam() {
  await loadClassSubCpmks()
  examEditId.value = null
  examForm.value = { title: '', type: 'UTS', description: '', start_time: '', end_time: '', duration_minutes: 60, is_online: true, shuffle_questions: true, shuffle_options: true, show_score: false, sub_cpmk_ids: [] }
  examModal.value = true
}

async function openEditExam(exam: any) {
  await loadClassSubCpmks()
  examEditId.value = exam.id
  examForm.value = {
    title: exam.title ?? '',
    type: exam.type ?? 'UTS',
    description: exam.description ?? '',
    start_time: toLocalDatetime(exam.start_time),
    end_time: toLocalDatetime(exam.end_time),
    duration_minutes: exam.duration_minutes ?? 60,
    is_online: exam.is_online ?? true,
    shuffle_questions: exam.shuffle_questions ?? true,
    shuffle_options: exam.shuffle_options ?? true,
    show_score: exam.show_score ?? false,
    sub_cpmk_ids: exam.sub_cpmk_ids ?? (exam.sub_cpmks ?? []).map((subCpmk: any) => subCpmk.id),
  }
  examModal.value = true
}

async function saveExam() {
  savingExam.value = true
  try {
    // Konversi datetime-local ke ISO dengan offset timezone lokal
    const payload = {
      ...examForm.value,
      start_time: localDatetimeToISO(examForm.value.start_time),
      end_time: localDatetimeToISO(examForm.value.end_time),
    }
    if (examEditId.value) {
      await api.put(`/exams/${examEditId.value}`, payload)
      toast.success('Ujian berhasil diupdate.')
    } else {
      await api.post('/exams', { ...payload, class_id: route.params.id })
      toast.success('Ujian berhasil dibuat.')
    }
    examModal.value = false
    examForm.value = { title: '', type: 'UTS', description: '', start_time: '', end_time: '', duration_minutes: 60, is_online: true, shuffle_questions: true, shuffle_options: true, show_score: false, sub_cpmk_ids: [] }
    examEditId.value = null
    loadTab()
  } catch (e: any) { toast.error(e?.response?.data?.message ?? 'Gagal.') }
  finally { savingExam.value = false }
}

async function deleteExam(exam: any) {
  if (!confirm(`Hapus ujian "${exam.title}" beserta soal-soalnya? Ujian yang sudah memiliki peserta tidak dapat dihapus.`)) return
  try { await api.delete(`/exams/${exam.id}`); toast.success('Ujian dihapus.'); await loadTab() }
  catch (e: any) { toast.error(e?.response?.data?.message ?? 'Gagal.') }
}

async function publishExam(exam: any) {
  try { await api.put(`/exams/${exam.id}`, { status: 'PUBLISHED' }); toast.success('Ujian dipublish.'); loadTab() }
  catch (e: any) { toast.error(e?.response?.data?.message ?? 'Gagal.') }
}

const examStatusColor: Record<string, string> = { DRAFT: 'bg-gray-100 text-gray-600', PUBLISHED: 'bg-green-100 text-green-700', ONGOING: 'bg-blue-100 text-blue-700', FINISHED: 'bg-purple-100 text-purple-700' }

/** Konversi ISO datetime string dari server (UTC) ke format datetime-local input (lokal) */
function toLocalDatetime(dt: string | null): string {
  if (!dt) return ''
  const d = new Date(dt)
  if (isNaN(d.getTime())) return ''
  const pad = (n: number) => String(n).padStart(2, '0')
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`
}

/** Format datetime untuk tampilan (bukan input) */
function fmtDatetime(dt: string | null): string {
  if (!dt) return '-'
  return new Date(dt).toLocaleString('id-ID', { day: 'numeric', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' })
}

/** Konversi datetime-local value (tanpa timezone) ke ISO string dengan offset lokal browser */
function localDatetimeToISO(val: string): string {
  if (!val) return ''
  // Dapatkan offset timezone lokal dalam menit, konversi ke ±HH:MM
  const d = new Date(val)
  const offset = -d.getTimezoneOffset()
  const sign = offset >= 0 ? '+' : '-'
  const hh = String(Math.floor(Math.abs(offset) / 60)).padStart(2, '0')
  const mm = String(Math.abs(offset) % 60).padStart(2, '0')
  return `${val}:00${sign}${hh}:${mm}`
}
</script>

<template>
  <div v-if="loading" class="flex items-center justify-center h-64"><p class="text-gray-400">Memuat...</p></div>

  <div v-else-if="classData" class="space-y-5 max-w-5xl mx-auto">
    <!-- Header -->
    <div class="flex items-start gap-3">
      <button class="p-1.5 rounded-lg text-gray-500 hover:bg-gray-100" @click="router.back()"><ArrowLeftIcon class="w-5 h-5" /></button>
      <div class="flex-1">
        <h1 class="text-lg font-bold text-gray-900">{{ classData.course?.name }}</h1>
        <p class="text-sm text-gray-500">{{ classData.name }} · {{ classData.course?.code }} · {{ classData.semester?.name }}</p>
      </div>
      <div class="flex items-center gap-2">
        <button :disabled="downloadingJournals" class="flex items-center gap-1 px-2.5 py-1.5 text-xs text-red-700 bg-red-50 hover:bg-red-100 rounded-lg disabled:opacity-50" @click="downloadJournals('pdf')">
          <ArrowDownTrayIcon class="w-3.5 h-3.5" /> Jurnal PDF
        </button>
        <button :disabled="downloadingJournals" class="flex items-center gap-1 px-2.5 py-1.5 text-xs text-green-700 bg-green-50 hover:bg-green-100 rounded-lg disabled:opacity-50" @click="downloadJournals('excel')">
          <ArrowDownTrayIcon class="w-3.5 h-3.5" /> Jurnal Excel
        </button>
        <button :disabled="downloadingMembers" class="flex items-center gap-1 px-2.5 py-1.5 text-xs text-red-700 bg-red-50 hover:bg-red-100 rounded-lg disabled:opacity-50" @click="downloadParticipants('pdf')">
          <ArrowDownTrayIcon class="w-3.5 h-3.5" /> Presensi Kelas PDF
        </button>
        <button :disabled="downloadingMembers" class="flex items-center gap-1 px-2.5 py-1.5 text-xs text-green-700 bg-green-50 hover:bg-green-100 rounded-lg disabled:opacity-50" @click="downloadParticipants('excel')">
          <ArrowDownTrayIcon class="w-3.5 h-3.5" /> Presensi Kelas Excel
        </button>
        <span class="text-sm text-gray-500">{{ classData.members_count ?? 0 }} mahasiswa</span>
      </div>
    </div>

    <!-- Tabs -->
    <div class="flex gap-1 border-b border-gray-200 overflow-x-auto">
      <button v-for="t in tabs.filter(tab => tab.key !== 'gradebook' || !auth.hasRole('MAHASISWA'))" :key="t.key"
        :class="['px-4 py-2.5 text-sm font-medium border-b-2 -mb-px transition-colors whitespace-nowrap',
          activeTab === t.key ? 'border-blue-600 text-blue-600' : 'border-transparent text-gray-500 hover:text-gray-700']"
        @click="switchTab(t.key)">
        {{ t.label }}
      </button>
    </div>

    <!-- TAB: Ringkasan -->
    <div v-if="activeTab === 'ringkasan'" class="space-y-5">
      <div v-if="summaryLoading" class="flex items-center justify-center rounded-xl border border-gray-200 bg-white py-10">
        <p class="text-sm text-gray-400">Memuat aktivitas pembelajaran...</p>
      </div>
      <template v-else>
        <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
          <button
            v-for="card in summaryCards"
            :key="card.label"
            class="rounded-xl border border-gray-200 bg-white p-4 text-left transition hover:-translate-y-0.5 hover:border-blue-200 hover:shadow-sm"
            @click="switchTab(card.label === 'Pertemuan' ? 'jurnal' : card.label === 'Materi' ? 'materi' : card.label === 'Tugas' ? 'tugas' : 'ujian')"
          >
            <p class="text-xs font-medium text-gray-500">{{ card.label }}</p>
            <p class="mt-1 text-2xl font-bold text-gray-900">{{ card.value }}</p>
          </button>
        </div>

        <div class="grid gap-5 lg:grid-cols-[1.3fr_1fr]">
          <section class="rounded-xl border border-gray-200 bg-white p-4">
            <div class="flex items-center justify-between gap-3">
              <div>
                <h2 class="font-semibold text-gray-900">Aktivitas terdekat</h2>
                <p class="mt-0.5 text-xs text-gray-500">Agenda pembelajaran yang perlu diperhatikan.</p>
              </div>
              <button class="text-xs font-medium text-blue-600 hover:underline" @click="switchTab('tugas')">Lihat tugas</button>
            </div>
            <div v-if="!upcomingActivities.length" class="py-8 text-center text-sm text-gray-400">
              Belum ada aktivitas terjadwal.
            </div>
            <div v-else class="mt-4 divide-y divide-gray-100">
              <button
                v-for="item in upcomingActivities"
                :key="`${item.type}-${item.title}-${item.date}`"
                class="flex w-full items-center gap-3 py-3 text-left first:pt-0 last:pb-0"
                @click="switchTab(item.tab)"
              >
                <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-blue-50 text-xs font-semibold text-blue-700">{{ item.type.slice(0, 2) }}</span>
                <span class="min-w-0 flex-1">
                  <span class="block truncate text-sm font-medium text-gray-800">{{ item.title || 'Aktivitas pembelajaran' }}</span>
                  <span class="mt-0.5 block text-xs text-gray-500">{{ item.type }} · {{ formatActivityDate(item.date) }}</span>
                </span>
              </button>
            </div>
          </section>

          <section class="rounded-xl border border-gray-200 bg-gradient-to-br from-blue-600 to-indigo-700 p-5 text-white">
            <p class="text-xs font-medium uppercase tracking-wide text-blue-100">Ruang pembelajaran</p>
            <h2 class="mt-2 text-xl font-bold">{{ classData.course?.name }}</h2>
            <p class="mt-2 text-sm leading-6 text-blue-100">
              Akses materi, pertemuan, tugas, ujian, dan pengumuman kelas dari satu ruang belajar terpadu.
            </p>
            <button class="mt-5 rounded-lg bg-white/15 px-3 py-2 text-xs font-semibold text-white hover:bg-white/25" @click="switchTab('materi')">
              Mulai belajar dari materi
            </button>
          </section>
        </div>

        <section class="rounded-xl border border-gray-200 bg-white p-4">
          <div class="flex items-center justify-between gap-3">
            <div>
              <h2 class="font-semibold text-gray-900">Pengumuman terbaru</h2>
              <p class="mt-0.5 text-xs text-gray-500">Informasi terakhir dari pengajar.</p>
            </div>
            <button class="text-xs font-medium text-blue-600 hover:underline" @click="switchTab('pengumuman')">Lihat semua</button>
          </div>
          <div v-if="!announcements.length" class="py-6 text-center text-sm text-gray-400">Belum ada pengumuman.</div>
          <div v-else class="mt-4 space-y-3">
            <div v-for="announcement in announcements.slice(0, 3)" :key="announcement.id" class="rounded-lg bg-gray-50 p-3">
              <p class="text-sm font-semibold text-gray-900">{{ announcement.title }}</p>
              <p class="mt-1 line-clamp-2 whitespace-pre-wrap break-words text-sm text-gray-600">
                <template v-for="(part, index) in discussionTextParts(announcement.content)" :key="index">
                  <a v-if="part.href" :href="part.href" target="_blank" rel="noopener noreferrer" class="text-blue-700 underline break-all">{{ part.text }}</a>
                  <template v-else>{{ part.text }}</template>
                </template>
              </p>
              <p class="mt-2 text-xs text-gray-400">{{ new Date(announcement.created_at).toLocaleDateString('id-ID') }}</p>
            </div>
          </div>
        </section>
      </template>
    </div>

    <!-- TAB: Jurnal -->
    <div v-if="activeTab === 'jurnal'" class="space-y-4">
      <div class="flex justify-end">
        <button v-if="!auth.hasRole('MAHASISWA')" class="flex items-center gap-1.5 px-3 py-1.5 bg-blue-600 hover:bg-blue-700 text-white text-xs font-medium rounded-lg" @click="openJournalModal">
          <PlusIcon class="w-3.5 h-3.5" /> Tambah Pertemuan
        </button>
      </div>
      <div v-if="!journals.length" class="text-center py-8 text-gray-400 text-sm">Belum ada jurnal pertemuan.</div>
      <div v-else class="space-y-2">
        <div v-for="j in journals" :key="j.id" class="flex items-center gap-4 p-4 bg-white rounded-xl border border-gray-200 hover:border-blue-200">
          <span class="w-9 h-9 rounded-full bg-blue-100 text-blue-700 flex items-center justify-center font-bold text-sm shrink-0 cursor-pointer" @click="router.push(`/perkuliahan/${route.params.id}/presensi/${j.id}`)">{{ j.meeting_number }}</span>
          <div class="flex-1 min-w-0 cursor-pointer" @click="router.push(`/perkuliahan/${route.params.id}/presensi/${j.id}`)">
            <p class="font-medium text-gray-900 text-sm">{{ j.topic }}</p>
            <p class="text-xs text-gray-500">{{ j.meeting_date }} · {{ j.delivery_mode === 'ONLINE' ? 'Dosen mengajar daring' : j.delivery_mode === 'ONSITE' ? 'Dosen mengajar luring' : 'Cara mengajar belum ditentukan' }} · {{ j.present_count ?? 0 }} hadir dari {{ j.attendances_count ?? 0 }} tercatat</p>
            <div v-if="j.photo_url || (j.latitude != null && j.longitude != null)" class="flex items-center gap-3 mt-1">
              <a v-if="j.photo_url" :href="j.photo_url" target="_blank" class="text-[11px] text-blue-600 hover:underline">Lihat foto</a>
              <a
                v-if="j.latitude != null && j.longitude != null"
                :href="`https://www.google.com/maps?q=${j.latitude},${j.longitude}`"
                target="_blank"
                class="text-[11px] text-green-700 hover:underline"
              >Lihat lokasi</a>
            </div>
          </div>
          <span :class="['px-2 py-0.5 rounded-full text-xs font-medium',
            j.status === 'COMPLETED' ? 'bg-green-100 text-green-700' : j.status === 'CANCELLED' ? 'bg-red-100 text-red-600' : 'bg-gray-100 text-gray-600']">
            {{ j.status }}
          </span>
          <span v-if="auth.hasRole('MAHASISWA')" class="hidden"></span>
          <span v-else-if="j.review_status === 'PENDING'" class="px-2 py-0.5 rounded-full bg-amber-100 text-amber-800 text-xs font-medium shrink-0">Menunggu pemeriksaan</span>
          <span v-else-if="j.review_status === 'APPROVED'" class="px-2 py-0.5 rounded-full bg-green-100 text-green-700 text-xs font-medium shrink-0">Disetujui untuk honor</span>
          <span v-else-if="j.review_status === 'REJECTED'" class="px-2 py-0.5 rounded-full bg-red-100 text-red-700 text-xs font-medium shrink-0">Perlu diperbaiki</span>
          <button v-if="!auth.hasRole('MAHASISWA') && j.review_status !== 'APPROVED'" class="p-1.5 rounded-lg text-blue-500 hover:bg-blue-50 shrink-0" title="Edit" @click.stop="openEdit('journal', j)">
            <PencilIcon class="w-4 h-4" />
          </button>
          <button v-if="!auth.hasRole('MAHASISWA') && j.review_status !== 'APPROVED'" class="p-1.5 rounded-lg text-red-400 hover:bg-red-50 hover:text-red-600 shrink-0" title="Hapus" @click.stop="deleteItem('journal', j.id)">
            <TrashIcon class="w-4 h-4" />
          </button>        </div>
      </div>
    </div>

    <!-- TAB: Materi -->
    <div v-if="activeTab === 'materi'" class="space-y-4">
      <div class="flex justify-end">
        <button v-if="!auth.hasRole('MAHASISWA')" class="flex items-center gap-1.5 px-3 py-1.5 bg-blue-600 hover:bg-blue-700 text-white text-xs font-medium rounded-lg" @click="materialModal = true">
          <PlusIcon class="w-3.5 h-3.5" /> Upload Materi
        </button>
      </div>
      <div v-if="!materials.length" class="text-center py-8 text-gray-400 text-sm">Belum ada materi.</div>
      <div v-else class="space-y-2">
        <div v-for="m in materials" :key="m.id" class="flex items-center gap-3 p-3 bg-white rounded-lg border border-gray-200">
          <BookOpenIcon class="w-5 h-5 text-blue-500 shrink-0" />
          <div class="flex-1 min-w-0">
            <p class="font-medium text-gray-900 text-sm">{{ m.title }}</p>
            <p class="mt-1 text-[11px] uppercase tracking-wide text-blue-600">{{ m.material_type ?? 'LINK' }}<span v-if="m.available_at"> · {{ new Date(m.available_at).toLocaleDateString('id-ID') }}</span></p>
            <p v-if="m.description" class="text-xs text-gray-500 line-clamp-2">{{ m.description }}</p>
          </div>
          <a v-if="m.file_url" :href="m.file_url" target="_blank" class="text-xs text-blue-600 underline shrink-0">Buka</a>
          <button v-if="!auth.hasRole('MAHASISWA')" class="p-1.5 rounded-lg text-blue-500 hover:bg-blue-50 shrink-0" title="Edit" @click="openEdit('material', m)">
            <PencilIcon class="w-4 h-4" />
          </button>
          <button v-if="!auth.hasRole('MAHASISWA')" class="p-1.5 rounded-lg text-red-400 hover:bg-red-50 hover:text-red-600 shrink-0" title="Hapus" @click="deleteItem('material', m.id)">
            <TrashIcon class="w-4 h-4" />
          </button>
        </div>
      </div>
    </div>

    <!-- TAB: Tugas -->
    <div v-if="activeTab === 'tugas'" class="space-y-4">
      <div class="flex justify-end">
        <button v-if="!auth.hasRole('MAHASISWA')" class="flex items-center gap-1.5 px-3 py-1.5 bg-blue-600 hover:bg-blue-700 text-white text-xs font-medium rounded-lg" @click="openCreateAssignment">
          <PlusIcon class="w-3.5 h-3.5" /> Buat Tugas
        </button>
      </div>
      <div v-if="!assignments.length" class="text-center py-8 text-gray-400 text-sm">Belum ada tugas.</div>
      <div v-else class="space-y-2">
        <div v-for="a in assignments" :key="a.id" class="p-4 bg-white rounded-xl border border-gray-200">
          <div class="flex items-center justify-between">
            <p class="font-medium text-gray-900 text-sm">{{ a.title }}</p>
            <div class="flex items-center gap-2">
              <span class="text-xs text-gray-400">{{ a.submissions_count ?? 0 }} dikumpulkan</span>
              <button v-if="!auth.hasRole('MAHASISWA')" class="px-2.5 py-1 rounded-lg border border-emerald-200 text-emerald-700 hover:bg-emerald-50 text-xs font-medium" :disabled="submissionReviewLoading" @click="openSubmissionReview(a)">
                {{ submissionReviewLoading && reviewAssignment?.id === a.id ? 'Memuat...' : 'Periksa Pengumpulan' }}
              </button>
              <button v-if="!auth.hasRole('MAHASISWA')" class="p-1.5 rounded-lg text-blue-500 hover:bg-blue-50" title="Edit" @click="openEdit('assignment', a)">
                <PencilIcon class="w-4 h-4" />
              </button>
              <button v-if="!auth.hasRole('MAHASISWA')" class="p-1.5 rounded-lg text-red-400 hover:bg-red-50 hover:text-red-600" title="Hapus" @click="deleteItem('assignment', a.id)">
                <TrashIcon class="w-4 h-4" />
              </button>
            </div>
          </div>
          <div v-if="a.description" class="mt-2">
            <p class="text-xs font-semibold text-gray-500">Deskripsi</p>
            <p class="text-sm text-gray-700 whitespace-pre-wrap break-words">{{ a.description }}</p>
          </div>
          <div v-if="a.instructions" class="mt-2">
            <p class="text-xs font-semibold text-gray-500">Instruksi</p>
            <p class="text-sm text-gray-700 whitespace-pre-wrap break-words">{{ a.instructions }}</p>
          </div>
          <p v-if="a.due_date" class="text-xs text-red-500 mt-1">Deadline: {{ new Date(a.due_date).toLocaleString('id-ID') }}</p>
          <a v-if="a.attachment_url" :href="a.attachment_url" target="_blank" rel="noopener" class="mt-2 inline-flex items-center text-xs text-blue-600 underline">Buka/download lampiran tugas</a>
          <div v-if="auth.hasRole('MAHASISWA')" class="mt-3 flex items-center justify-between gap-3 border-t pt-3">
            <span v-if="a.submissions?.[0]" class="text-xs text-emerald-600">
              Terkumpul<span v-if="a.submissions[0].score !== null"> · Nilai {{ a.submissions[0].score }}/{{ a.max_score }}</span>
            </span>
            <span v-else class="text-xs text-gray-500">Belum dikumpulkan</span>
            <button class="px-3 py-1.5 rounded-lg bg-blue-600 text-white text-xs hover:bg-blue-700" @click="openSubmission(a)">
              {{ a.submissions?.[0] ? 'Perbarui Pengumpulan' : 'Kumpulkan Tugas' }}
            </button>
          </div>
          <p v-if="auth.hasRole('MAHASISWA') && a.submissions?.[0]?.feedback" class="mt-2 rounded-lg bg-gray-50 p-2 text-xs text-gray-600">Feedback: {{ a.submissions[0].feedback }}</p>
        </div>
      </div>
    </div>

    <!-- TAB: Diskusi -->
    <div v-if="activeTab === 'diskusi'" class="space-y-4">
      <div class="flex items-center justify-between gap-3">
        <div>
          <h2 class="text-sm font-semibold text-gray-900">Forum Diskusi Kelas</h2>
          <p class="mt-0.5 text-xs text-gray-500">Ajukan pertanyaan atau tanggapi topik pembelajaran bersama.</p>
        </div>
        <button class="flex shrink-0 items-center gap-1.5 rounded-lg bg-blue-600 px-3 py-1.5 text-xs font-medium text-white hover:bg-blue-700" @click="discussionModal = true">
          <PlusIcon class="h-3.5 w-3.5" /> Buat Topik
        </button>
      </div>

      <div v-if="discussionsLoading" class="rounded-xl border border-gray-200 bg-white py-10 text-center text-sm text-gray-400">
        Memuat diskusi...
      </div>
      <div v-else-if="!discussions.length" class="rounded-xl border border-dashed border-gray-300 bg-white py-10 text-center">
        <ChatBubbleLeftRightIcon class="mx-auto h-8 w-8 text-gray-300" />
        <p class="mt-2 text-sm font-medium text-gray-600">Belum ada diskusi</p>
        <p class="mt-1 text-xs text-gray-400">Mulai percakapan dengan membuat topik pertama.</p>
      </div>
      <div v-else class="grid gap-4 lg:grid-cols-[minmax(16rem,0.8fr)_minmax(0,1.5fr)]">
        <section class="space-y-2">
          <button
            v-for="discussion in discussions"
            :key="discussion.id"
            class="w-full rounded-xl border p-3 text-left transition"
            :class="selectedDiscussion?.id === discussion.id ? 'border-blue-300 bg-blue-50' : 'border-gray-200 bg-white hover:border-blue-200'"
            @click="selectDiscussion(discussion)"
          >
            <div class="flex items-start justify-between gap-2">
              <p class="line-clamp-2 text-sm font-semibold text-gray-900">{{ discussion.title }}</p>
              <span v-if="discussion.is_pinned" class="shrink-0 rounded-full bg-amber-100 px-2 py-0.5 text-[10px] font-medium text-amber-800">Disematkan</span>
            </div>
            <p class="mt-1 line-clamp-2 text-xs text-gray-500">{{ discussion.content }}</p>
            <div class="mt-2 flex items-center justify-between gap-2 text-[11px] text-gray-400">
              <span>{{ discussion.user?.name }} · {{ new Date(discussion.created_at).toLocaleDateString('id-ID') }}</span>
              <span>{{ discussion.replies_count ?? 0 }} balasan</span>
            </div>
          </button>
        </section>

        <section v-if="selectedDiscussion" class="min-w-0 rounded-xl border border-gray-200 bg-white">
          <div class="border-b border-gray-100 p-4">
            <div class="flex items-start justify-between gap-3">
              <div class="min-w-0">
                <div class="mb-1 flex flex-wrap items-center gap-1.5">
                  <span v-if="selectedDiscussion.is_pinned" class="rounded-full bg-amber-100 px-2 py-0.5 text-[10px] font-medium text-amber-800">Disematkan</span>
                  <span v-if="selectedDiscussion.is_locked" class="rounded-full bg-gray-100 px-2 py-0.5 text-[10px] font-medium text-gray-600">Dikunci</span>
                </div>
                <h3 class="break-words text-base font-semibold text-gray-900">{{ selectedDiscussion.title }}</h3>
                <p class="mt-1 text-xs text-gray-400">{{ selectedDiscussion.user?.name }} · {{ new Date(selectedDiscussion.created_at).toLocaleString('id-ID') }}</p>
              </div>
              <div class="flex shrink-0 items-center gap-1">
                <template v-if="!auth.hasRole('MAHASISWA')">
                  <button class="rounded-lg px-2 py-1 text-[11px] text-amber-700 hover:bg-amber-50" @click="moderateDiscussion(selectedDiscussion, 'is_pinned')">
                    {{ selectedDiscussion.is_pinned ? 'Lepas sematan' : 'Sematkan' }}
                  </button>
                  <button class="rounded-lg px-2 py-1 text-[11px] text-gray-600 hover:bg-gray-100" @click="moderateDiscussion(selectedDiscussion, 'is_locked')">
                    {{ selectedDiscussion.is_locked ? 'Buka kunci' : 'Kunci' }}
                  </button>
                </template>
                <button
                  v-if="selectedDiscussion.user_id === auth.user?.id || !auth.hasRole('MAHASISWA')"
                  class="rounded-lg p-1.5 text-red-400 hover:bg-red-50 hover:text-red-600"
                  title="Hapus topik"
                  @click="deleteDiscussion(selectedDiscussion)"
                >
                  <TrashIcon class="h-4 w-4" />
                </button>
              </div>
            </div>
            <p class="mt-3 whitespace-pre-wrap break-words text-sm leading-relaxed text-gray-700">
              <template v-for="(part, index) in discussionTextParts(selectedDiscussion.content)" :key="index">
                <a v-if="part.href" :href="part.href" target="_blank" rel="noopener noreferrer" class="text-blue-700 underline break-all">{{ part.text }}</a>
                <template v-else>{{ part.text }}</template>
              </template>
            </p>
          </div>

          <div class="max-h-[45vh] space-y-3 overflow-y-auto p-4">
            <p v-if="!selectedDiscussion.replies?.length" class="py-4 text-center text-xs text-gray-400">Belum ada balasan.</p>
            <article v-for="reply in selectedDiscussion.replies" :key="reply.id" class="rounded-lg bg-gray-50 p-3">
              <div class="flex items-start justify-between gap-2">
                <p class="text-xs font-semibold text-gray-800">{{ reply.user?.name }}</p>
                <button
                  v-if="reply.user_id === auth.user?.id || !auth.hasRole('MAHASISWA')"
                  class="text-[11px] text-red-500 hover:underline"
                  @click="deleteDiscussionReply(reply)"
                >Hapus</button>
              </div>
              <p class="mt-1 whitespace-pre-wrap break-words text-sm text-gray-700">
                <template v-for="(part, index) in discussionTextParts(reply.content)" :key="index">
                  <a v-if="part.href" :href="part.href" target="_blank" rel="noopener noreferrer" class="text-blue-700 underline break-all">{{ part.text }}</a>
                  <template v-else>{{ part.text }}</template>
                </template>
              </p>
              <p class="mt-2 text-[10px] text-gray-400">{{ new Date(reply.created_at).toLocaleString('id-ID') }}</p>
            </article>
          </div>

          <form v-if="!selectedDiscussion.is_locked" class="border-t border-gray-100 p-4" @submit.prevent="sendDiscussionReply">
            <label class="sr-only" for="discussion-reply">Tulis balasan</label>
            <textarea id="discussion-reply" ref="replyTextarea" v-model="discussionReply" rows="2" maxlength="5000" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:outline-none" placeholder="Tulis balasan..." />
            <div class="mt-2 flex flex-wrap items-center justify-between gap-2">
              <button type="button" class="rounded-lg border border-gray-200 px-2.5 py-1.5 text-xs text-gray-600 hover:bg-gray-50" @click="showReplyEmojis = !showReplyEmojis">
                😊 Emoji
              </button>
              <button type="submit" :disabled="savingDiscussionReply || !discussionReply.trim()" class="rounded-lg bg-blue-600 px-3 py-2 text-xs font-medium text-white hover:bg-blue-700 disabled:opacity-50">
                {{ savingDiscussionReply ? 'Mengirim...' : 'Kirim Balasan' }}
              </button>
            </div>
            <div v-if="showReplyEmojis" class="mt-2 flex flex-wrap gap-1 rounded-lg border border-gray-200 bg-gray-50 p-2">
              <button v-for="emoji in discussionEmojis" :key="emoji" type="button" class="rounded p-1 text-lg hover:bg-white" :aria-label="`Sisipkan ${emoji}`" @click="insertDiscussionEmoji(emoji, 'reply')">{{ emoji }}</button>
            </div>
          </form>
        </section>
      </div>
    </div>

    <!-- TAB: Pengumuman -->
    <div v-if="activeTab === 'pengumuman'" class="space-y-4">
      <div class="flex justify-end">
        <button v-if="!auth.hasRole('MAHASISWA')" class="flex items-center gap-1.5 px-3 py-1.5 bg-blue-600 hover:bg-blue-700 text-white text-xs font-medium rounded-lg" @click="annModal = true">
          <PlusIcon class="w-3.5 h-3.5" /> Buat Pengumuman
        </button>
      </div>
      <div v-if="!announcements.length" class="text-center py-8 text-gray-400 text-sm">Belum ada pengumuman.</div>
      <div v-else class="space-y-2">
        <div v-for="a in announcements" :key="a.id" class="p-4 bg-white rounded-xl border border-gray-200">
          <div class="flex items-start justify-between">
            <div class="flex-1">
              <p class="font-semibold text-gray-900 text-sm">{{ a.title }}</p>
              <p class="text-sm text-gray-700 mt-1 whitespace-pre-wrap break-words">
                <template v-for="(part, index) in discussionTextParts(a.content)" :key="index">
                  <a v-if="part.href" :href="part.href" target="_blank" rel="noopener noreferrer" class="text-blue-700 underline break-all">{{ part.text }}</a>
                  <template v-else>{{ part.text }}</template>
                </template>
              </p>
              <p class="text-xs text-gray-400 mt-2">{{ a.user?.name }} · {{ new Date(a.created_at).toLocaleDateString('id-ID') }}</p>
            </div>
            <button v-if="!auth.hasRole('MAHASISWA')" class="p-1.5 rounded-lg text-blue-500 hover:bg-blue-50 shrink-0 ml-1" title="Edit" @click="openEdit('announcement', a)">
              <PencilIcon class="w-4 h-4" />
            </button>
            <button v-if="!auth.hasRole('MAHASISWA')" class="p-1.5 rounded-lg text-red-400 hover:bg-red-50 hover:text-red-600 shrink-0 ml-2" title="Hapus" @click="deleteItem('announcement', a.id)">
              <TrashIcon class="w-4 h-4" />
            </button>
          </div>
        </div>
      </div>
    </div>

    <!-- TAB: Ujian -->
    <div v-if="activeTab === 'ujian'" class="space-y-4">
      <div class="flex justify-end">
        <button v-if="!auth.hasRole('MAHASISWA')" class="flex items-center gap-1.5 px-3 py-1.5 bg-blue-600 hover:bg-blue-700 text-white text-xs font-medium rounded-lg" @click="openCreateExam">
          <PlusIcon class="w-3.5 h-3.5" /> Buat Ujian
        </button>
      </div>
      <div v-if="!exams.length" class="text-center py-8 text-gray-400 text-sm">Belum ada ujian.</div>
      <div v-else class="space-y-3">
        <div v-for="e in exams" :key="e.id" class="p-4 bg-white rounded-xl border border-gray-200 hover:border-blue-200 transition-colors">
          <div class="flex items-start justify-between">
            <!-- Info ujian — klik beda tergantung role -->
            <div class="flex-1 cursor-pointer"
              @click="auth.hasRole('MAHASISWA') ? router.push(`/ujian/${e.id}/take`) : router.push(`/ujian/${e.id}`)">
              <div class="flex items-center gap-2">
                <span class="text-xs font-semibold px-2 py-0.5 rounded bg-indigo-100 text-indigo-700">{{ e.type }}</span>
                <span :class="['px-2 py-0.5 rounded-full text-xs font-medium', examStatusColor[e.status] ?? 'bg-gray-100 text-gray-600']">{{ e.status }}</span>
                <span v-if="e.is_online" class="text-xs text-green-600 font-medium">● Online</span>
              </div>
              <h3 class="mt-1.5 font-semibold text-gray-900 text-sm">{{ e.title }}</h3>
              <div class="flex items-center gap-4 mt-1 text-xs text-gray-500">
                <span v-if="e.duration_minutes">{{ e.duration_minutes }} menit</span>
                <span v-if="e.start_time">{{ fmtDatetime(e.start_time) }}</span>
                <span v-if="e.questions_count !== undefined">{{ e.questions_count }} soal</span>
                <span v-if="e.total_score" class="text-blue-600 font-medium">Total: {{ e.total_score }} poin</span>
              </div>
            </div>

            <!-- Tombol aksi -->
            <div class="flex items-center gap-1 shrink-0">
              <!-- Mahasiswa: tombol Kerjakan -->
              <button
                v-if="auth.hasRole('MAHASISWA') && (e.status === 'PUBLISHED' || e.status === 'ONGOING')"
                class="px-3 py-1.5 text-xs bg-blue-600 hover:bg-blue-700 text-white font-medium rounded-lg"
                @click="router.push(`/ujian/${e.id}/take`)">
                Kerjakan
              </button>
              <span
                v-else-if="auth.hasRole('MAHASISWA') && e.status === 'FINISHED'"
                class="px-3 py-1.5 text-xs bg-gray-100 text-gray-500 rounded-lg">
                Selesai
              </span>

              <!-- Dosen: Publish, Edit, Hapus -->
              <button v-if="!auth.hasRole('MAHASISWA') && e.status === 'DRAFT'" class="px-2 py-1 text-xs bg-green-600 hover:bg-green-700 text-white rounded" @click="publishExam(e)">Publish</button>
              <button v-if="!auth.hasRole('MAHASISWA')" class="p-1.5 rounded-lg text-blue-500 hover:bg-blue-50" title="Edit" @click="openEditExam(e)"><PencilIcon class="w-4 h-4" /></button>
              <button v-if="auth.hasRole('SUPER_ADMIN') || auth.hasRole('ADMIN_AKADEMIK') || auth.hasRole('DOSEN')" class="p-1.5 rounded-lg text-red-500 hover:bg-red-50" title="Hapus ujian" aria-label="Hapus ujian" @click="deleteExam(e)"><TrashIcon class="w-4 h-4" /></button>
            </div>
          </div>

          <!-- Token: hanya tampil untuk dosen -->
          <div v-if="!auth.hasRole('MAHASISWA') && e.token" class="mt-2 pt-2 border-t border-gray-100 flex items-center gap-2">
            <span class="text-xs text-gray-400">Token:</span>
            <span class="text-xs font-mono font-bold text-gray-700 bg-gray-100 px-1.5 py-0.5 rounded">{{ e.token }}</span>
          </div>

          <!-- Info tambahan untuk mahasiswa jika ujian sudah selesai -->
          <div v-if="auth.hasRole('MAHASISWA') && e.status === 'FINISHED'" class="mt-2 pt-2 border-t border-gray-100">
            <span class="text-xs text-gray-400">Ujian telah berakhir.</span>
          </div>
        </div>
      </div>
    </div>

    <!-- TAB: Gradebook -->
    <div v-if="activeTab === 'gradebook'" class="space-y-4">
          <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
              <h2 class="text-base font-semibold text-gray-900">Gradebook Kelas</h2>
              <p class="mt-1 text-xs text-gray-500">Nilai aktivitas pembelajaran mengikuti komponen Input Nilai Akhir. Quiz masuk ke komponen Tugas.</p>
            </div>
            <button class="rounded-lg bg-blue-600 px-3 py-2 text-xs font-medium text-white hover:bg-blue-700" @click="goToFinalGradeInput">Input Nilai Akhir</button>
          </div>

          <div v-if="gradebookLoading" class="rounded-xl border bg-white py-8 text-center text-sm text-gray-400">Memuat gradebook...</div>
          <template v-else>
            <form v-if="!auth.hasRole('MAHASISWA')" class="grid gap-3 rounded-xl border bg-white p-4 sm:grid-cols-2 lg:grid-cols-4" @submit.prevent="saveGradebookWeights">
              <label v-for="component in gradebookData.components" :key="component.key" class="text-xs font-medium text-gray-700">
                {{ component.key === 'tasks' ? 'Tugas (termasuk Quiz)' : component.name }} (%)
                <input v-model.number="component.weight" type="number" min="0" max="100" class="mt-1 w-full rounded border px-2 py-1.5" />
              </label>
              <div class="flex items-end lg:col-span-4">
                <p class="flex-1 text-[11px] text-gray-500">Bobot total: {{ gradebookData.components.reduce((total: number, component: any) => total + Number(component.weight), 0) }}%. Komponen, bobot, dan hasilnya akan dipakai kembali di Input Nilai Akhir.</p>
                <button type="submit" :disabled="savingGradebookWeights || gradebookData.components.reduce((total: number, component: any) => total + Number(component.weight), 0) !== 100" class="rounded bg-blue-600 px-3 py-2 text-xs font-medium text-white hover:bg-blue-700 disabled:opacity-50">
                  {{ savingGradebookWeights ? 'Menyimpan...' : 'Simpan Bobot' }}
                </button>
              </div>
            </form>

            <p v-if="!gradebookData.students.length" class="rounded-xl border bg-white py-8 text-center text-sm text-gray-500">Belum ada peserta kelas.</p>
            <div v-else class="overflow-x-auto rounded-xl border bg-white">
              <table class="min-w-full text-xs">
                <thead>
                  <tr class="border-b bg-gray-50 text-left text-gray-500">
                    <th class="px-2 py-2">Mahasiswa</th>
                    <th v-for="assignment in gradebookData.assignments" :key="assignment.id" class="min-w-28 px-2 py-2">
                      {{ assignment.title }}<span class="block text-gray-400">Tugas /{{ assignment.max_score }}</span>
                    </th>
                    <th v-for="exam in gradebookData.exams" :key="exam.id" class="min-w-28 px-2 py-2">
                      {{ exam.title }}<span class="block text-gray-400">{{ exam.type === 'QUIZ' || exam.type === 'TUGAS_BESAR' ? 'Tugas' : exam.type }}</span>
                    </th>
                    <th v-for="component in gradebookData.components" :key="component.key" class="min-w-24 px-2 py-2">
                      {{ component.name }}<span class="block text-gray-400">{{ component.weight }}%</span>
                    </th>
                    <th class="min-w-32 px-2 py-2">Kelengkapan</th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-for="student in gradebookData.students" :key="student.id" class="border-b last:border-0">
                    <td class="px-2 py-2"><span class="font-medium">{{ student.name }}</span><span class="block text-gray-400">{{ student.nim }}</span></td>
                    <td v-for="assignment in gradebookData.assignments" :key="assignment.id" class="px-2 py-2">
                      <div v-if="gradeFor(student, assignment.id)?.submission_id" class="flex items-center gap-1">
                        <input v-model.number="gradeFor(student, assignment.id).score" type="number" min="0" :max="assignment.max_score" class="w-16 rounded border px-1.5 py-1" />
                        <button class="rounded bg-blue-600 px-1.5 py-1 text-white hover:bg-blue-700" @click="saveGrade(gradeFor(student, assignment.id))">Simpan</button>
                      </div>
                      <span v-else-if="gradeFor(student, assignment.id)?.is_missing" class="text-red-600">0 / {{ assignment.max_score }}</span>
                      <span v-else class="text-gray-400">—</span>
                    </td>
                    <td v-for="exam in gradebookData.exams" :key="exam.id" class="px-2 py-2">
                      <span v-if="examScoreFor(student, exam.id)?.percentage !== null && examScoreFor(student, exam.id)?.percentage !== undefined">
                        {{ examScoreFor(student, exam.id).score }} / {{ examScoreFor(student, exam.id).max_score }}
                      </span>
                      <span v-else class="text-gray-400">—</span>
                    </td>
                    <td v-for="component in gradebookData.components" :key="component.key" class="px-2 py-2">
                      {{ student.component_scores[component.key] === null ? '—' : `${student.component_scores[component.key]}%` }}
                    </td>
                    <td class="px-2 py-2">
                      <span v-if="student.is_provisional" class="text-amber-600">Komponen belum lengkap</span>
                      <button v-else class="text-left font-medium text-blue-700 underline" @click="goToFinalGradeInput">Siap disahkan</button>
                    </td>
                  </tr>
                </tbody>
              </table>
            </div>
            <section v-if="gradebookData.cpmks?.length" class="space-y-3">
              <div>
                <h3 class="text-sm font-semibold text-gray-900">Capaian CPMK / Sub-CPMK</h3>
                <p class="mt-1 text-xs text-gray-500">Nilai adalah rata-rata persentase dari tugas dan ujian yang ditautkan. Nilai yang masih tertunda belum dihitung; bagian ini bukan nilai akhir resmi.</p>
              </div>
              <article v-for="cpmk in gradebookData.cpmks" :key="cpmk.id" class="overflow-hidden rounded-xl border border-gray-200 bg-white">
                <div class="border-b bg-gray-50 px-4 py-3">
                  <p class="text-sm font-semibold text-gray-900">{{ cpmk.code }} — {{ cpmk.description }}</p>
                </div>
                <div class="overflow-x-auto">
                  <table class="min-w-full text-xs">
                    <thead class="border-b text-left text-gray-500">
                      <tr>
                        <th class="min-w-48 px-3 py-2">Mahasiswa</th>
                        <th v-for="subCpmk in cpmk.sub_cpmks" :key="subCpmk.id" class="min-w-40 px-3 py-2">
                          {{ subCpmk.code }}<span class="block font-normal text-gray-400">{{ subCpmk.description }}</span>
                        </th>
                      </tr>
                    </thead>
                    <tbody>
                      <tr v-for="student in gradebookData.students" :key="student.id" class="border-b last:border-0">
                        <td class="px-3 py-2 font-medium text-gray-800">{{ student.name }}<span class="block font-normal text-gray-400">{{ student.nim }}</span></td>
                        <td v-for="subCpmk in cpmk.sub_cpmks" :key="subCpmk.id" class="px-3 py-2">
                          <template v-if="student.outcome_scores?.[subCpmk.id]?.score !== null && student.outcome_scores?.[subCpmk.id]?.score !== undefined">
                            <span class="font-semibold text-gray-900">{{ student.outcome_scores[subCpmk.id].score }}%</span>
                            <span v-if="student.outcome_scores[subCpmk.id].pending_activities" class="ml-1 text-amber-700">sementara</span>
                            <span class="block text-gray-400">{{ student.outcome_scores[subCpmk.id].evidence_count }} aktivitas dinilai</span>
                          </template>
                          <span v-else class="text-gray-400">Belum ada nilai</span>
                          <span v-if="student.outcome_scores?.[subCpmk.id]?.pending_activities" class="block text-amber-700">{{ student.outcome_scores[subCpmk.id].pending_activities }} tertunda</span>
                        </td>
                      </tr>
                    </tbody>
                  </table>
                </div>
              </article>
            </section>
            <p v-else class="rounded-xl border border-dashed border-gray-300 bg-white p-4 text-sm text-gray-500">Belum ada RPKPS berstatus disetujui/terkunci untuk mata kuliah dan semester ini. Capaian CPMK akan tersedia setelah RPKPS aktif.</p>
          </template>
    </div>
  </div>

  <!-- Modal Jurnal -->
  <BaseModal :open="journalModal" title="Tambah Pertemuan" size="xl" @close="closeJournalModal">
    <form class="space-y-3" @submit.prevent="saveJournal">
      <div class="grid grid-cols-2 gap-3">
        <div>
          <label class="text-xs font-medium text-gray-700">Pertemuan ke</label>
          <input v-model.number="journalForm.meeting_number" type="number" min="1" max="16" class="w-full mt-1 px-3 py-2 border rounded-lg text-sm" @change="fillFromRps" />
        </div>
        <div><label class="text-xs font-medium text-gray-700">Tanggal</label><input v-model="journalForm.meeting_date" type="date" required class="w-full mt-1 px-3 py-2 border rounded-lg text-sm" /></div>
      </div>
      <div>
        <label class="text-xs font-medium text-gray-700">Cara dosen mengajar</label>
        <select v-model="journalForm.delivery_mode" required class="w-full mt-1 px-3 py-2 border rounded-lg text-sm">
          <option disabled value="">Pilih cara dosen mengajar</option>
          <option value="ONSITE">Luring — dosen mengajar di kampus</option>
          <option value="ONLINE">Daring — dosen mengajar secara online</option>
        </select>
        <p class="mt-1 text-xs text-gray-500">Untuk kelas hybrid, pilih cara dosen mengajar pada pertemuan ini. Pilihan ini digunakan sebagai dasar tarif honor.</p>
      </div>

      <div v-if="rpsSources.length">
        <label class="text-xs font-medium text-gray-700">Sumber RPKPS/RPS</label>
        <select v-model="selectedRpsId" class="w-full mt-1 px-3 py-2 border rounded-lg text-sm" @change="changeRpsSource">
          <option v-for="source in rpsSources" :key="source.id" :value="source.id">
            {{ source.course_code }} — {{ source.course_name }} ({{ source.rpkps_code }})
          </option>
        </select>
      </div>

      <!-- Auto-fill info -->
      <div v-if="rpsPlans.length" class="bg-blue-50 border border-blue-200 rounded-lg p-2">
        <p class="text-xs text-blue-700">📋 Topik, bahan kajian, dan metode terhubung dengan RPKPS/RPS terpilih. Ubah nomor pertemuan untuk mengambil rencana minggu yang sesuai.</p>
      </div>

      <div><label class="text-xs font-medium text-gray-700">Topik / Sub-CPMK dari RPKPS/RPS</label><input v-model="journalForm.topic" required class="w-full mt-1 px-3 py-2 border rounded-lg text-sm" /></div>
      <div><label class="text-xs font-medium text-gray-700">Bahan Kajian / Deskripsi</label><textarea v-model="journalForm.description" rows="2" class="w-full mt-1 px-3 py-2 border rounded-lg text-sm" /></div>
      <div><label class="text-xs font-medium text-gray-700">Aktivitas Pembelajaran / Metode</label><textarea v-model="journalForm.learning_activity" rows="2" class="w-full mt-1 px-3 py-2 border rounded-lg text-sm" /></div>

      <!-- Geotagging -->
      <div class="border border-gray-200 rounded-lg p-3 space-y-2">
        <div class="flex items-center justify-between">
          <label class="text-xs font-medium text-gray-700">📍 Geotagging Lokasi</label>
          <button type="button" :disabled="gettingLocation" class="px-3 py-1.5 bg-green-600 hover:bg-green-700 disabled:bg-green-400 text-white text-xs font-medium rounded-lg" @click="getLocation">
            {{ gettingLocation ? 'Mengambil...' : 'Ambil Lokasi' }}
          </button>
        </div>
        <div v-if="journalForm.latitude" class="text-xs text-gray-600 bg-gray-50 rounded p-2 font-mono">
          Lat: {{ journalForm.latitude?.toFixed(7) }} | Lng: {{ journalForm.longitude?.toFixed(7) }}
        </div>
      </div>

      <!-- Upload Foto -->
      <div class="border border-gray-200 rounded-lg p-3 space-y-2">
        <label class="text-xs font-medium text-gray-700">📷 Foto Dokumentasi</label>

        <!-- Camera Preview -->
        <div v-if="showCamera" class="space-y-2">
          <div class="relative bg-black rounded-lg overflow-hidden" style="max-height: 240px;">
            <video ref="videoRef" autoplay playsinline class="w-full" style="max-height: 240px; object-fit: cover;"></video>
          </div>
          <div class="flex flex-wrap gap-2">
            <button type="button" :disabled="cameraSwitching" class="flex-1 px-3 py-2 bg-green-600 hover:bg-green-700 disabled:opacity-50 text-white text-xs font-medium rounded-lg" @click="capturePhoto">✓ Ambil</button>
            <button type="button" :disabled="cameraSwitching" class="px-3 py-2 bg-blue-100 hover:bg-blue-200 disabled:opacity-50 text-blue-700 text-xs font-medium rounded-lg" @click="switchCamera">
              Ganti ke kamera {{ cameraFacingMode === 'environment' ? 'depan' : 'belakang' }}
            </button>
            <button type="button" class="px-3 py-2 bg-gray-200 hover:bg-gray-300 text-gray-700 text-xs rounded-lg" @click="closeCamera">Batal</button>
          </div>
        </div>

        <!-- Photo result / buttons -->
        <div v-else class="flex items-center gap-3">
          <div v-if="journalPhotoPreview" class="w-20 h-20 rounded-lg border overflow-hidden shrink-0">
            <img :src="journalPhotoPreview" class="w-full h-full object-cover" />
          </div>
          <div class="flex flex-col gap-2">
            <div class="flex gap-2">
              <button type="button" :disabled="cameraSwitching" class="px-3 py-1.5 bg-blue-600 hover:bg-blue-700 disabled:opacity-50 text-white text-xs font-medium rounded-lg inline-flex items-center gap-1" @click="openCamera">
                📷 {{ cameraSwitching ? 'Membuka kamera...' : `Buka kamera ${cameraFacingMode === 'environment' ? 'belakang' : 'depan'}` }}
              </button>
              <label class="px-3 py-1.5 bg-gray-100 hover:bg-gray-200 text-gray-700 text-xs font-medium rounded-lg cursor-pointer border border-gray-300 inline-flex items-center gap-1">
                📁 Pilih File
                <input type="file" accept="image/*" class="hidden" @change="onJournalPhoto" />
              </label>
            </div>
            <p class="text-[10px] text-gray-400">"Buka Kamera" untuk foto langsung. "Pilih File" untuk upload dari galeri. Max 5MB.</p>
          </div>
        </div>
      </div>
    </form>
    <template #footer>
      <button class="px-4 py-2 text-sm text-gray-600 hover:bg-gray-100 rounded-lg" @click="closeJournalModal">Batal</button>
      <button class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium rounded-lg" @click="saveJournal">Simpan</button>
    </template>
  </BaseModal>

  <!-- Modal Materi -->
  <BaseModal :open="materialModal" title="Upload Materi" @close="materialModal = false">
    <form id="material-form" class="space-y-3" @submit.prevent="saveMaterial">
      <div><label class="text-xs font-medium text-gray-700">Judul</label><input v-model="materialForm.title" required class="w-full mt-1 px-3 py-2 border rounded-lg text-sm" /></div>
      <div><label class="text-xs font-medium text-gray-700">Deskripsi</label><textarea v-model="materialForm.description" rows="2" class="w-full mt-1 px-3 py-2 border rounded-lg text-sm" /></div>
      <div class="grid grid-cols-2 gap-3">
        <div><label class="text-xs font-medium text-gray-700">Tipe Materi</label><select v-model="materialForm.material_type" class="w-full mt-1 px-3 py-2 border rounded-lg text-sm"><option value="LINK">Link</option><option value="VIDEO">Video</option><option value="FILE">File</option><option value="TEXT">Teks</option></select></div>
        <div><label class="text-xs font-medium text-gray-700">Rilis pada</label><input v-model="materialForm.available_at" type="datetime-local" class="w-full mt-1 px-3 py-2 border rounded-lg text-sm" /></div>
      </div>
      <div><label class="text-xs font-medium text-gray-700">Pertemuan</label><select v-model="materialForm.journal_id" class="w-full mt-1 px-3 py-2 border rounded-lg text-sm"><option value="">Umum untuk kelas</option><option v-for="journal in journals" :key="journal.id" :value="journal.id">Pertemuan {{ journal.meeting_number }} — {{ journal.topic }}</option></select></div>
      <div v-if="materialForm.material_type === 'FILE'"><label class="text-xs font-medium text-gray-700">File Materi</label><input type="file" accept=".pdf,.doc,.docx,.ppt,.pptx,.xls,.xlsx,.mp4,.webm,.jpg,.jpeg,.png" class="w-full mt-1 text-sm" @change="onMaterialFile" /></div>
      <div v-else-if="materialForm.material_type !== 'TEXT'"><label class="text-xs font-medium text-gray-700">Link Materi</label><input v-model="materialForm.file_url" placeholder="https://..." class="w-full mt-1 px-3 py-2 border rounded-lg text-sm" /></div>
      <div><label class="flex items-center gap-2 text-xs font-medium text-gray-700"><input v-model="materialForm.is_published" type="checkbox" class="rounded" /> Publikasikan untuk mahasiswa</label></div>
    </form>
    <template #footer>
      <button class="px-4 py-2 text-sm text-gray-600 hover:bg-gray-100 rounded-lg" @click="materialModal = false">Batal</button>
      <button type="submit" form="material-form" :disabled="savingMaterial" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 disabled:opacity-60 text-white text-sm font-medium rounded-lg">{{ savingMaterial ? 'Menyimpan...' : 'Simpan' }}</button>
    </template>
  </BaseModal>

  <!-- Modal Tugas -->
  <BaseModal :open="assignmentModal" title="Buat Tugas" @close="assignmentModal = false">
    <form id="assignment-form" class="space-y-3" @submit.prevent="saveAssignment">
      <div class="rounded-lg border border-blue-100 bg-blue-50 p-3">
        <label class="text-xs font-medium text-gray-700">Template pribadi</label>
        <div class="mt-1 flex gap-2">
          <select v-model="selectedAssignmentTemplateId" class="min-w-0 flex-1 rounded-lg border px-3 py-2 text-sm" :disabled="assignmentTemplatesLoading || !assignmentTemplates.length">
            <option :value="null">{{ assignmentTemplatesLoading ? 'Memuat template...' : 'Pilih template tugas' }}</option>
            <option v-for="template in assignmentTemplates" :key="template.id" :value="template.id">{{ template.name }}</option>
          </select>
          <button type="button" class="rounded-lg border bg-white px-3 py-2 text-xs font-medium disabled:opacity-50" :disabled="!selectedAssignmentTemplateId" @click="applyAssignmentTemplate">Gunakan</button>
          <button type="button" class="rounded-lg border border-red-200 bg-white px-3 py-2 text-xs text-red-700 disabled:opacity-50" :disabled="!selectedAssignmentTemplateId" @click="deleteAssignmentTemplate">Hapus</button>
        </div>
        <div class="mt-2 flex gap-2">
          <input v-model="assignmentTemplateName" maxlength="100" placeholder="Nama template baru" class="min-w-0 flex-1 rounded-lg border px-3 py-2 text-sm" />
          <button type="button" class="rounded-lg bg-blue-700 px-3 py-2 text-xs font-medium text-white disabled:opacity-50" :disabled="savingAssignmentTemplate || !assignmentTemplateName.trim() || !assignmentForm.title.trim()" @click="saveAssignmentTemplate">
            {{ savingAssignmentTemplate ? 'Menyimpan...' : 'Simpan isi sebagai template' }}
          </button>
        </div>
        <p class="mt-1 text-[11px] text-gray-600">Template menyimpan judul, deskripsi, instruksi, tipe, dan nilai maksimal. Deadline, lampiran, publikasi, serta pemetaan Sub-CPMK diatur tersendiri untuk tiap kelas.</p>
      </div>
      <div><label class="text-xs font-medium text-gray-700">Judul</label><input v-model="assignmentForm.title" required class="w-full mt-1 px-3 py-2 border rounded-lg text-sm" /></div>
      <div><label class="text-xs font-medium text-gray-700">Deskripsi</label><textarea v-model="assignmentForm.description" rows="2" class="w-full mt-1 px-3 py-2 border rounded-lg text-sm" /></div>
      <div><label class="text-xs font-medium text-gray-700">Instruksi</label><textarea v-model="assignmentForm.instructions" rows="2" class="w-full mt-1 px-3 py-2 border rounded-lg text-sm" /></div>
      <div><label class="text-xs font-medium text-gray-700">Lampiran tugas (opsional, maksimal 20 MB)</label><input type="file" accept=".pdf,.doc,.docx,.ppt,.pptx,.xls,.xlsx,.zip,.jpg,.jpeg,.png,.mp4,.webm" class="w-full mt-1 text-sm" @change="onAssignmentAttachment" /></div>
      <div class="grid grid-cols-2 gap-3">
        <div><label class="text-xs font-medium text-gray-700">Tipe</label><select v-model="assignmentForm.type" class="w-full mt-1 px-3 py-2 border rounded-lg text-sm"><option value="INDIVIDU">Individu</option><option value="KELOMPOK">Kelompok</option></select></div>
        <div><label class="text-xs font-medium text-gray-700">Deadline</label><input v-model="assignmentForm.due_date" type="datetime-local" class="w-full mt-1 px-3 py-2 border rounded-lg text-sm" /></div>
      </div>
      <div class="grid grid-cols-2 gap-3">
        <div><label class="text-xs font-medium text-gray-700">Nilai Maksimal</label><input v-model.number="assignmentForm.max_score" type="number" min="1" class="w-full mt-1 px-3 py-2 border rounded-lg text-sm" /></div>
        <label class="flex items-center gap-2 self-end pb-2 text-xs font-medium text-gray-700"><input v-model="assignmentForm.allow_late" type="checkbox" class="rounded" /> Izinkan terlambat</label>
      </div>
      <div>
        <label class="text-xs font-medium text-gray-700">Sub-CPMK yang dinilai (opsional)</label>
        <select v-model="assignmentForm.sub_cpmk_ids" multiple size="4" class="mt-1 w-full rounded-lg border px-3 py-2 text-sm" :disabled="subCpmkOptionsLoading || !classSubCpmks.length">
          <option v-for="subCpmk in classSubCpmks" :key="subCpmk.id" :value="subCpmk.id">{{ subCpmk.code }} — {{ subCpmk.cpmk.code }}</option>
        </select>
        <p class="mt-1 text-[11px] text-gray-500">{{ classSubCpmks.length ? 'Pilih satu atau beberapa capaian yang diukur oleh tugas ini.' : 'Tidak ada Sub-CPMK aktif yang tersedia untuk kelas ini.' }}</p>
      </div>
      <label class="flex items-center gap-2 text-xs font-medium text-gray-700"><input v-model="assignmentForm.is_published" type="checkbox" class="rounded" /> Publikasikan untuk mahasiswa</label>
    </form>
    <template #footer>
      <button class="px-4 py-2 text-sm text-gray-600 hover:bg-gray-100 rounded-lg" @click="assignmentModal = false">Batal</button>
      <button type="submit" form="assignment-form" :disabled="savingAssignment" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 disabled:opacity-60 text-white text-sm font-medium rounded-lg">{{ savingAssignment ? 'Menyimpan...' : 'Simpan' }}</button>
    </template>
  </BaseModal>

  <!-- Modal Pengumpulan Tugas -->
  <BaseModal :open="submissionModal" :title="submissionAssignment ? 'Kumpulkan: ' + submissionAssignment.title : 'Kumpulkan Tugas'" @close="submissionModal = false">
    <form id="submission-form" class="space-y-3" @submit.prevent="saveSubmission">
      <div><label class="text-xs font-medium text-gray-700">Jawaban</label><textarea v-model="submissionForm.content" rows="5" placeholder="Tulis jawaban atau catatan..." class="w-full mt-1 px-3 py-2 border rounded-lg text-sm" /></div>
      <div><label class="text-xs font-medium text-gray-700">Link File (opsional)</label><input v-model="submissionForm.file_url" placeholder="https://..." class="w-full mt-1 px-3 py-2 border rounded-lg text-sm" /></div>
      <div><label class="text-xs font-medium text-gray-700">Upload File (opsional, maksimal 20 MB)</label><input type="file" accept=".pdf,.doc,.docx,.ppt,.pptx,.xls,.xlsx,.zip,.jpg,.jpeg,.png" class="w-full mt-1 text-sm" @change="onSubmissionFile" /></div>
    </form>
    <template #footer>
      <button class="px-4 py-2 text-sm text-gray-600 hover:bg-gray-100 rounded-lg" @click="submissionModal = false">Batal</button>
      <button type="submit" form="submission-form" :disabled="savingSubmission" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 disabled:opacity-60 text-white text-sm font-medium rounded-lg">{{ savingSubmission ? 'Mengirim...' : 'Kirim' }}</button>
    </template>
  </BaseModal>

  <BaseModal :open="submissionReviewModal" :title="reviewAssignment ? 'Pengumpulan: ' + reviewAssignment.title : 'Pengumpulan Tugas'" size="2xl" @close="submissionReviewModal = false">
    <div class="max-h-[72vh] space-y-3 overflow-y-auto">
      <p v-if="submissionReviewLoading" class="py-8 text-center text-sm text-gray-500">Memuat pengumpulan...</p>
      <p v-else-if="!reviewSubmissions.length" class="py-8 text-center text-sm text-gray-500">Belum ada mahasiswa yang mengumpulkan tugas ini.</p>
      <article v-for="submission in reviewSubmissions" :key="submission.id" class="space-y-3 rounded-xl border border-gray-200 p-3 sm:p-4">
        <div class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
          <div class="min-w-0">
            <p class="font-medium text-sm text-gray-900">{{ submission.student?.name ?? 'Mahasiswa' }}</p>
            <p class="text-xs text-gray-500">{{ submission.student?.nim ?? '' }}</p>
          </div>
          <p class="text-xs text-gray-500 sm:text-right">Dikumpulkan: {{ submission.submitted_at ? new Date(submission.submitted_at).toLocaleString('id-ID') : '-' }}</p>
        </div>
        <div v-if="submission.content" class="max-h-56 overflow-y-auto whitespace-pre-wrap break-words rounded-lg bg-gray-50 p-3 text-sm text-gray-700">{{ submission.content }}</div>
        <a v-if="submission.file_url" :href="submission.file_url" target="_blank" rel="noopener noreferrer" class="inline-flex items-center text-sm text-blue-700 underline">
          Buka / unduh file jawaban
        </a>
        <p v-if="!submission.content && !submission.file_url" class="text-xs italic text-gray-400">Tidak ada teks atau file pada pengumpulan ini.</p>
        <div class="grid grid-cols-1 gap-3 border-t pt-3 sm:grid-cols-[7rem_minmax(0,1fr)] lg:grid-cols-[8rem_minmax(0,1fr)_auto] lg:items-end">
          <label class="text-xs font-medium text-gray-700">
            Nilai (maks. {{ reviewAssignment?.max_score ?? 100 }})
            <input v-model.number="submission.score" type="number" min="0" :max="reviewAssignment?.max_score ?? 100" class="mt-1 w-full rounded-lg border px-3 py-2 text-sm" />
          </label>
          <label class="text-xs font-medium text-gray-700">
            Feedback
            <textarea v-model="submission.feedback" rows="2" class="mt-1 w-full rounded-lg border px-3 py-2 text-sm" placeholder="Catatan untuk mahasiswa..." />
          </label>
          <button :disabled="gradingSubmissionId === submission.id" class="w-full rounded-lg bg-blue-600 px-3 py-2 text-xs font-medium text-white hover:bg-blue-700 disabled:opacity-60 lg:w-auto" @click="saveSubmissionGrade(submission)">
            {{ gradingSubmissionId === submission.id ? 'Menyimpan...' : 'Simpan Nilai' }}
          </button>
        </div>
        <p v-if="submission.graded_at" class="text-[11px] text-emerald-700">Dinilai {{ new Date(submission.graded_at).toLocaleString('id-ID') }}</p>
      </article>
    </div>
    <template #footer>
      <button class="rounded-lg px-4 py-2 text-sm text-gray-600 hover:bg-gray-100" @click="submissionReviewModal = false">Tutup</button>
    </template>
  </BaseModal>

  <BaseModal :open="discussionModal" title="Buat Topik Diskusi" size="lg" @close="discussionModal = false">
    <form id="discussion-form" class="space-y-3" @submit.prevent="createDiscussion">
      <label class="block text-xs font-medium text-gray-700">
        Judul Topik
        <input v-model="discussionForm.title" required maxlength="255" class="mt-1 w-full rounded-lg border px-3 py-2 text-sm" placeholder="Contoh: Diskusi materi pertemuan 3" />
      </label>
      <label class="block text-xs font-medium text-gray-700">
        Isi Diskusi
        <textarea ref="topicTextarea" v-model="discussionForm.content" required maxlength="10000" rows="5" class="mt-1 w-full rounded-lg border px-3 py-2 text-sm" placeholder="Tuliskan pertanyaan atau gagasan untuk didiskusikan..." />
      </label>
      <div>
        <button type="button" class="rounded-lg border border-gray-200 px-2.5 py-1.5 text-xs text-gray-600 hover:bg-gray-50" @click="showTopicEmojis = !showTopicEmojis">
          😊 Emoji
        </button>
        <div v-if="showTopicEmojis" class="mt-2 flex flex-wrap gap-1 rounded-lg border border-gray-200 bg-gray-50 p-2">
          <button v-for="emoji in discussionEmojis" :key="emoji" type="button" class="rounded p-1 text-lg hover:bg-white" :aria-label="`Sisipkan ${emoji}`" @click="insertDiscussionEmoji(emoji, 'topic')">{{ emoji }}</button>
        </div>
      </div>
    </form>
    <template #footer>
      <button class="rounded-lg px-4 py-2 text-sm text-gray-600 hover:bg-gray-100" @click="discussionModal = false">Batal</button>
      <button type="submit" form="discussion-form" :disabled="savingDiscussion" class="rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700 disabled:opacity-60">
        {{ savingDiscussion ? 'Menyimpan...' : 'Buat Topik' }}
      </button>
    </template>
  </BaseModal>

  <!-- Modal Pengumuman -->
  <BaseModal :open="annModal" title="Buat Pengumuman" @close="annModal = false">
    <form class="space-y-3" @submit.prevent="saveAnnouncement">
      <div><label class="text-xs font-medium text-gray-700">Judul</label><input v-model="annForm.title" required class="w-full mt-1 px-3 py-2 border rounded-lg text-sm" /></div>
      <div><label class="text-xs font-medium text-gray-700">Isi</label><textarea v-model="annForm.content" required rows="4" class="w-full mt-1 px-3 py-2 border rounded-lg text-sm" /></div>
    </form>
    <template #footer>
      <button class="px-4 py-2 text-sm text-gray-600 hover:bg-gray-100 rounded-lg" @click="annModal = false">Batal</button>
      <button class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium rounded-lg" @click="saveAnnouncement">Simpan</button>
    </template>
  </BaseModal>

  <!-- Modal Ujian -->
  <BaseModal :open="examModal" :title="examEditId ? 'Edit Ujian' : 'Buat Ujian'" size="xl" @close="examModal = false">
    <form class="space-y-4" @submit.prevent="saveExam">
      <div class="grid grid-cols-2 gap-4">
        <div><label class="text-xs font-medium text-gray-700">Judul <span class="text-red-500">*</span></label><input v-model="examForm.title" required placeholder="UTS Hukum Perbankan Syariah" class="w-full mt-1 px-3 py-2 border rounded-lg text-sm" /></div>
        <div><label class="text-xs font-medium text-gray-700">Jenis <span class="text-red-500">*</span></label><select v-model="examForm.type" class="w-full mt-1 px-3 py-2 border rounded-lg text-sm"><option v-for="t in examTypes" :key="t" :value="t">{{ t }}</option></select></div>
      </div>
      <div><label class="text-xs font-medium text-gray-700">Deskripsi</label><textarea v-model="examForm.description" rows="2" class="w-full mt-1 px-3 py-2 border rounded-lg text-sm" /></div>
      <div>
        <label class="text-xs font-medium text-gray-700">Sub-CPMK yang dinilai (opsional)</label>
        <select v-model="examForm.sub_cpmk_ids" multiple size="4" class="mt-1 w-full rounded-lg border px-3 py-2 text-sm" :disabled="subCpmkOptionsLoading || !classSubCpmks.length">
          <option v-for="subCpmk in classSubCpmks" :key="subCpmk.id" :value="subCpmk.id">{{ subCpmk.code }} — {{ subCpmk.cpmk.code }}</option>
        </select>
        <p class="mt-1 text-[11px] text-gray-500">{{ classSubCpmks.length ? 'Pilih satu atau beberapa capaian yang diukur oleh ujian ini.' : 'Tidak ada Sub-CPMK aktif yang tersedia untuk kelas ini.' }}</p>
      </div>
      <div class="grid grid-cols-3 gap-4">
        <div><label class="text-xs font-medium text-gray-700">Waktu Mulai</label><input v-model="examForm.start_time" type="datetime-local" class="w-full mt-1 px-3 py-2 border rounded-lg text-sm" /></div>
        <div><label class="text-xs font-medium text-gray-700">Waktu Selesai</label><input v-model="examForm.end_time" type="datetime-local" class="w-full mt-1 px-3 py-2 border rounded-lg text-sm" /></div>
        <div><label class="text-xs font-medium text-gray-700">Durasi (menit)</label><input v-model.number="examForm.duration_minutes" type="number" min="5" class="w-full mt-1 px-3 py-2 border rounded-lg text-sm" /></div>
      </div>
      <div class="flex flex-wrap items-center gap-5">
        <label class="flex items-center gap-2 text-sm text-gray-700"><input v-model="examForm.is_online" type="checkbox" class="rounded" /> Online</label>
        <label class="flex items-center gap-2 text-sm text-gray-700"><input v-model="examForm.shuffle_questions" type="checkbox" class="rounded" /> Acak Soal</label>
        <label class="flex items-center gap-2 text-sm text-gray-700"><input v-model="examForm.shuffle_options" type="checkbox" class="rounded" /> Acak Pilihan</label>
        <label class="flex items-center gap-2 text-sm text-gray-700"><input v-model="examForm.show_score" type="checkbox" class="rounded" /> Tampilkan Nilai</label>
      </div>
    </form>
    <template #footer>
      <button class="px-4 py-2 text-sm text-gray-600 hover:bg-gray-100 rounded-lg" @click="examModal = false">Batal</button>
      <button :disabled="savingExam" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 disabled:bg-blue-400 text-white text-sm font-medium rounded-lg" @click="saveExam">
        {{ savingExam ? 'Menyimpan...' : (examEditId ? 'Update' : 'Simpan') }}
      </button>
    </template>
  </BaseModal>

  <!-- Modal Edit Universal -->
  <BaseModal :open="editModal" :title="editType ? 'Edit ' + { journal: 'Jurnal', material: 'Materi', assignment: 'Tugas', announcement: 'Pengumuman' }[editType] : 'Edit'" @close="editModal = false">
    <form class="space-y-3" @submit.prevent="saveEdit">
      <!-- Journal fields -->
      <template v-if="editType === 'journal'">
        <div class="grid grid-cols-2 gap-3">
          <div>
            <label class="text-xs font-medium text-gray-700">Pertemuan ke</label>
            <input v-model.number="editForm.meeting_number" type="number" min="1" max="16" class="w-full mt-1 px-3 py-2 border rounded-lg text-sm" />
          </div>
          <div>
            <label class="text-xs font-medium text-gray-700">Tanggal</label>
            <input v-model="editForm.meeting_date" type="date" class="w-full mt-1 px-3 py-2 border rounded-lg text-sm" />
          </div>
        </div>
        <div>
          <label class="text-xs font-medium text-gray-700">Cara dosen mengajar</label>
          <select v-model="editForm.delivery_mode" required class="w-full mt-1 px-3 py-2 border rounded-lg text-sm">
            <option disabled value="">Pilih cara dosen mengajar</option>
            <option value="ONSITE">Luring — dosen mengajar di kampus</option>
            <option value="ONLINE">Daring — dosen mengajar secara online</option>
          </select>
          <p class="mt-1 text-xs text-gray-500">Untuk kelas hybrid, pilih cara dosen mengajar pada pertemuan ini.</p>
        </div>
        <div>
          <label class="text-xs font-medium text-gray-700">Topik</label>
          <input v-model="editForm.topic" class="w-full mt-1 px-3 py-2 border rounded-lg text-sm" />
        </div>
        <div>
          <label class="text-xs font-medium text-gray-700">Deskripsi</label>
          <textarea v-model="editForm.description" rows="2" class="w-full mt-1 px-3 py-2 border rounded-lg text-sm" />
        </div>
        <div>
          <label class="text-xs font-medium text-gray-700">Aktivitas Pembelajaran</label>
          <textarea v-model="editForm.learning_activity" rows="2" class="w-full mt-1 px-3 py-2 border rounded-lg text-sm" />
        </div>
        <div>
          <label class="text-xs font-medium text-gray-700">Status</label>
          <select v-model="editForm.status" class="w-full mt-1 px-3 py-2 border rounded-lg text-sm">
            <option value="PLANNED">PLANNED</option>
            <option value="COMPLETED">COMPLETED</option>
            <option value="CANCELLED">CANCELLED</option>
          </select>
        </div>
      </template>
      <!-- Material fields -->
      <template v-else-if="editType === 'material'">
        <div>
          <label class="text-xs font-medium text-gray-700">Judul</label>
          <input v-model="editForm.title" required class="w-full mt-1 px-3 py-2 border rounded-lg text-sm" />
        </div>
        <div>
          <label class="text-xs font-medium text-gray-700">Deskripsi</label>
          <textarea v-model="editForm.description" rows="2" class="w-full mt-1 px-3 py-2 border rounded-lg text-sm" />
        </div>
        <div>
          <label class="text-xs font-medium text-gray-700">Link File</label>
          <input v-model="editForm.file_url" placeholder="https://drive.google.com/..." class="w-full mt-1 px-3 py-2 border rounded-lg text-sm" />
        </div>
      </template>
      <!-- Assignment fields -->
      <template v-else-if="editType === 'assignment'">
        <div>
          <label class="text-xs font-medium text-gray-700">Judul</label>
          <input v-model="editForm.title" required class="w-full mt-1 px-3 py-2 border rounded-lg text-sm" />
        </div>
        <div>
          <label class="text-xs font-medium text-gray-700">Deskripsi</label>
          <textarea v-model="editForm.description" rows="2" class="w-full mt-1 px-3 py-2 border rounded-lg text-sm" />
        </div>
        <div>
          <label class="text-xs font-medium text-gray-700">Instruksi</label>
          <textarea v-model="editForm.instructions" rows="2" class="w-full mt-1 px-3 py-2 border rounded-lg text-sm" />
        </div>
        <div class="grid grid-cols-2 gap-3">
          <div>
            <label class="text-xs font-medium text-gray-700">Deadline</label>
            <input v-model="editForm.due_date" type="datetime-local" class="w-full mt-1 px-3 py-2 border rounded-lg text-sm" />
          </div>
          <div>
            <label class="text-xs font-medium text-gray-700">Skor Maksimal</label>
            <input v-model.number="editForm.max_score" type="number" min="0" class="w-full mt-1 px-3 py-2 border rounded-lg text-sm" />
          </div>
        </div>
        <div>
          <label class="text-xs font-medium text-gray-700">Sub-CPMK yang dinilai (opsional)</label>
          <select v-model="editForm.sub_cpmk_ids" multiple size="4" class="mt-1 w-full rounded-lg border px-3 py-2 text-sm" :disabled="subCpmkOptionsLoading || !classSubCpmks.length">
            <option v-for="subCpmk in classSubCpmks" :key="subCpmk.id" :value="subCpmk.id">{{ subCpmk.code }} — {{ subCpmk.cpmk.code }}</option>
          </select>
          <p class="mt-1 text-[11px] text-gray-500">{{ classSubCpmks.length ? 'Ubah pemetaan capaian untuk tugas ini.' : 'Tidak ada Sub-CPMK aktif yang tersedia untuk kelas ini.' }}</p>
        </div>
      </template>
      <!-- Announcement fields -->
      <template v-else-if="editType === 'announcement'">
        <div>
          <label class="text-xs font-medium text-gray-700">Judul</label>
          <input v-model="editForm.title" required class="w-full mt-1 px-3 py-2 border rounded-lg text-sm" />
        </div>
        <div>
          <label class="text-xs font-medium text-gray-700">Isi Pengumuman</label>
          <textarea v-model="editForm.content" required rows="4" class="w-full mt-1 px-3 py-2 border rounded-lg text-sm" />
        </div>
      </template>
    </form>
    <template #footer>
      <button class="px-4 py-2 text-sm text-gray-600 hover:bg-gray-100 rounded-lg" @click="editModal = false">Batal</button>
      <button :disabled="savingEdit" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 disabled:bg-blue-400 text-white text-sm font-medium rounded-lg" @click="saveEdit">
        {{ savingEdit ? 'Menyimpan...' : 'Simpan' }}
      </button>
    </template>
  </BaseModal>
</template>
