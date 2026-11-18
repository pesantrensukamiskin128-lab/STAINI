<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref } from 'vue'
import api from '@/services/api'

interface Schedule {
  id: number
  class_id: number
  day: string
  start_time: string
  end_time: string
  lecturer_id?: number | null
  room_id?: number | null
}

interface ClassItem {
  id: number
  name: string
  course?: { code?: string; name?: string }
  schedules?: Schedule[]
}

interface Option {
  id: number
  name?: string
  full_name?: string
  code?: string
}

const days = ['Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu', 'Minggu']
const selected = ref<Schedule | null>(null)
const selectedClass = ref<ClassItem | null>(null)
const lecturers = ref<Option[]>([])
const rooms = ref<Option[]>([])
const loading = ref(false)
const saving = ref(false)
const message = ref('')
const messageType = ref<'success' | 'error'>('success')
const conflicts = ref<string[]>([])
const form = ref({ day: '', start_time: '', end_time: '', lecturer_id: '', room_id: '' })
let observer: MutationObserver | undefined
let messageTimer: ReturnType<typeof setTimeout> | undefined

function setMessage(text: string, type: 'success' | 'error') {
  message.value = text
  messageType.value = type
  clearTimeout(messageTimer)
  messageTimer = setTimeout(() => { message.value = '' }, 6000)
}

function normalizedTime(value: string) {
  return value.slice(0, 5)
}

function scheduleForRow(row: HTMLTableRowElement, item: ClassItem, targetLine?: HTMLElement): Schedule | null {
  const line = targetLine ?? row.querySelector<HTMLElement>('td:nth-child(4) [data-asc-class-schedule-line]')
  const text = line?.dataset.ascClassScheduleLine ?? line?.textContent ?? ''
  const match = text.match(/(Senin|Selasa|Rabu|Kamis|Jumat|Sabtu|Minggu)\s*,?\s*(\d{2}:\d{2})(?::\d{2})?\s*[–-]\s*(\d{2}:\d{2})(?::\d{2})?/)
  if (!match) return null

  return item.schedules?.find((schedule) =>
    schedule.day === match[1]
      && normalizedTime(schedule.start_time) === match[2]
      && normalizedTime(schedule.end_time) === match[3],
  ) ?? null
}

function addActionButtons() {
  document.querySelectorAll<HTMLTableRowElement>('#app main table tbody tr').forEach((row) => {
    const cells = row.querySelectorAll('td')
    if (cells.length < 7 || row.querySelector('[data-asc-class-schedule-edit]')) return

    const scheduleCell = cells[3]
    const lines = Array.from(scheduleCell?.querySelectorAll<HTMLElement>('div') ?? []).filter((line) =>
      /(?:Senin|Selasa|Rabu|Kamis|Jumat|Sabtu|Minggu)\s*,?\s*\d{2}:\d{2}/.test(line.textContent ?? ''),
    )
    lines.forEach((line) => {
      if (line.querySelector('[data-asc-class-schedule-edit], button[title="Edit jadwal"]')) return
      const button = document.createElement('button')
      button.type = 'button'
      button.dataset.ascClassScheduleEdit = 'true'
      button.className = 'asc-inline-action'
      button.title = 'Edit jadwal'
      button.setAttribute('aria-label', 'Edit jadwal mata kuliah')
      button.textContent = 'Edit'
      button.addEventListener('click', () => { void openEditor(row, button, line) })
      line.dataset.ascClassScheduleLine = line.textContent ?? ''
      line.append(button)
    })
  })
}

function filterValue(label: string) {
  const selects = Array.from(document.querySelectorAll<HTMLSelectElement>('#app main select'))
  return selects.find((select) => select.options[0]?.textContent?.includes(label))?.value ?? ''
}

async function openEditor(row: HTMLTableRowElement, button: HTMLButtonElement, line: HTMLElement) {
  button.disabled = true
  loading.value = true
  conflicts.value = []
  try {
    const cells = row.querySelectorAll('td')
    const className = cells[0]?.textContent?.trim() ?? ''
    const courseMetadata = cells[1]?.querySelectorAll('p')[1]?.textContent?.trim() ?? ''
    const courseCode = courseMetadata.split(/[·\s]/, 1)[0] ?? ''
    const params: Record<string, string | number> = { search: className, per_page: 100 }
    const semesterId = filterValue('Semua Semester')
    const programId = filterValue('Semua Prodi')
    if (semesterId) params.semester_id = semesterId
    if (programId) params.study_program_id = programId

    const [classResponse, lecturerResponse, roomResponse] = await Promise.all([
      api.get('/classes', { params }),
      lecturers.value.length ? Promise.resolve(null) : api.get('/lecturers/all'),
      rooms.value.length ? Promise.resolve(null) : api.get('/rooms/all'),
    ])
    const classes = classResponse.data.data ?? classResponse.data
    const candidates = (Array.isArray(classes) ? classes : []).filter((item: ClassItem) =>
      item.name === className && item.course?.code === courseCode,
    )
    const matchedClass = candidates.find((item: ClassItem) => scheduleForRow(row, item, line))
    if (!matchedClass) throw new Error('Jadwal kelas tidak dapat dicocokkan dengan data terbaru. Muat ulang halaman lalu coba lagi.')

    const schedule = scheduleForRow(row, matchedClass, line)
    if (!schedule) throw new Error('Jadwal kelas tidak ditemukan.')
    selectedClass.value = matchedClass
    selected.value = schedule
    form.value = {
      day: schedule.day,
      start_time: normalizedTime(schedule.start_time),
      end_time: normalizedTime(schedule.end_time),
      lecturer_id: String(schedule.lecturer_id ?? ''),
      room_id: String(schedule.room_id ?? ''),
    }
    if (lecturerResponse) {
      const data = lecturerResponse.data
      lecturers.value = Array.isArray(data) ? data : data.data ?? []
    }
    if (roomResponse) {
      const data = roomResponse.data
      rooms.value = Array.isArray(data) ? data : data.data ?? []
    }
  } catch (cause) {
    setMessage(cause instanceof Error ? cause.message : 'Gagal memuat jadwal.', 'error')
  } finally {
    loading.value = false
    button.disabled = false
  }
}

async function saveSchedule() {
  if (!selected.value || !selectedClass.value || saving.value) return
  saving.value = true
  conflicts.value = []
  try {
    await api.put(`/classes/${selectedClass.value.id}/schedules/${selected.value.id}`, {
      ...form.value,
      lecturer_id: form.value.lecturer_id || null,
      room_id: form.value.room_id || null,
    })
    sessionStorage.setItem('asc-class-schedule-updated', 'Jadwal diperbarui dan akan terlihat pada KRS mahasiswa setelah dimuat ulang.')
    window.location.reload()
  } catch (cause: any) {
    conflicts.value = cause?.response?.data?.conflicts ?? []
    setMessage(cause?.response?.data?.message ?? 'Gagal memperbarui jadwal.', 'error')
  } finally {
    saving.value = false
  }
}

function closeEditor() {
  selected.value = null
  selectedClass.value = null
  conflicts.value = []
}

function handleKeydown(event: KeyboardEvent) {
  if (event.key === 'Escape') closeEditor()
}

onMounted(() => {
  addActionButtons()
  const notice = sessionStorage.getItem('asc-class-schedule-updated')
  if (notice) {
    sessionStorage.removeItem('asc-class-schedule-updated')
    setMessage(notice, 'success')
  }
  observer = new MutationObserver(addActionButtons)
  const target = document.querySelector('#app main')
  if (target) observer.observe(target, { childList: true, subtree: true })
  window.addEventListener('keydown', handleKeydown)
})

onBeforeUnmount(() => {
  observer?.disconnect()
  document.querySelectorAll('[data-asc-class-schedule-edit]').forEach((button) => button.remove())
  window.removeEventListener('keydown', handleKeydown)
  clearTimeout(messageTimer)
})
</script>

<template>
  <div class="asc-academic-tools-overlay">
    <div v-if="message" :class="['asc-tools-toast', `asc-tools-toast-${messageType}`]" role="status">{{ message }}</div>
    <div v-if="selected" class="asc-schedule-backdrop" @click.self="closeEditor">
      <form class="asc-schedule-dialog" @submit.prevent="saveSchedule">
        <h2>Edit Jadwal Mata Kuliah</h2>
        <p>{{ selectedClass?.course?.name }} · Kelas {{ selectedClass?.name }}</p>
        <div v-if="conflicts.length" class="asc-schedule-conflicts" role="alert">
          <strong>Jadwal bentrok.</strong>
          <span v-for="conflict in conflicts" :key="conflict">{{ conflict }}</span>
        </div>
        <label for="asc-schedule-day">Hari</label>
        <select id="asc-schedule-day" v-model="form.day" required>
          <option v-for="day in days" :key="day" :value="day">{{ day }}</option>
        </select>
        <div class="asc-schedule-time-grid">
          <div>
            <label for="asc-schedule-start">Jam mulai</label>
            <input id="asc-schedule-start" v-model="form.start_time" type="time" required>
          </div>
          <div>
            <label for="asc-schedule-end">Jam selesai</label>
            <input id="asc-schedule-end" v-model="form.end_time" type="time" required>
          </div>
        </div>
        <label for="asc-schedule-lecturer">Dosen</label>
        <select id="asc-schedule-lecturer" v-model="form.lecturer_id">
          <option value="">-- Tidak ditentukan --</option>
          <option v-for="lecturer in lecturers" :key="lecturer.id" :value="String(lecturer.id)">
            {{ lecturer.name ?? lecturer.full_name ?? `Dosen ${lecturer.id}` }}
          </option>
        </select>
        <label for="asc-schedule-room">Ruangan</label>
        <select id="asc-schedule-room" v-model="form.room_id">
          <option value="">-- Tidak ditentukan --</option>
          <option v-for="room in rooms" :key="room.id" :value="String(room.id)">
            {{ room.code ? `${room.code} - ${room.name}` : room.name ?? `Ruangan ${room.id}` }}
          </option>
        </select>
        <p class="asc-schedule-hint">
          Jadwal baru akan tampil pada KRS mahasiswa yang mengambil kelas ini. Pastikan perubahan tidak menimbulkan bentrok.
        </p>
        <footer>
          <button type="button" class="asc-tools-button" @click="closeEditor">Batal</button>
          <button type="submit" class="asc-tools-button asc-tools-button-primary" :disabled="saving || loading">
            {{ saving ? 'Menyimpan...' : 'Simpan Perubahan' }}
          </button>
        </footer>
      </form>
    </div>
  </div>
</template>
