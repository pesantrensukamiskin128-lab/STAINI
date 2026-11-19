<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref } from 'vue'

interface Lecturer {
  id: number
  name?: string
  full_name?: string
  status?: boolean | number | string
}

interface Student {
  id: number
  nim: string
  name: string
  advisor_id?: number | null
  advisor?: { id?: number; name?: string; full_name?: string } | null
}

const apiBase = '/api'
const token = localStorage.getItem('auth_token')
const lecturers = ref<Lecturer[]>([])
const selected = ref<Student | null>(null)
const selectedRow = ref<HTMLTableRowElement | null>(null)
const advisorId = ref<number | ''>('')
const saving = ref(false)
const message = ref('')
const messageType = ref<'success' | 'error'>('success')
let observer: MutationObserver | undefined
let messageTimer: ReturnType<typeof setTimeout> | undefined

function setMessage(text: string, type: 'success' | 'error') {
  message.value = text
  messageType.value = type
  clearTimeout(messageTimer)
  messageTimer = setTimeout(() => { message.value = '' }, 5000)
}

async function request(path: string, init: RequestInit = {}) {
  const response = await fetch(`${apiBase}${path}`, {
    ...init,
    headers: {
      Accept: 'application/json',
      Authorization: ['Bearer', token].join(' '),
      ...(init.body ? { 'Content-Type': 'application/json' } : {}),
      ...init.headers,
    },
  })
  const payload = await response.json().catch(() => null)
  if (!response.ok) throw new Error(payload?.message ?? `Permintaan gagal (HTTP ${response.status}).`)
  return payload
}

function attachActionButtons() {
  document.querySelectorAll<HTMLTableRowElement>('#app main table tbody tr').forEach((rowElement) => {
    if (rowElement.querySelector('[data-asc-advisor-action]')) return
    const cells = rowElement.querySelectorAll('td')
    if (cells.length < 3) return
    const nim = cells[1]?.textContent?.trim()
    const actions = cells[cells.length - 1]
    if (!nim || !actions || !actions.querySelector('button')) return
    const nativeAdvisorAction = Array.from(actions.querySelectorAll('button'))
      .some((button) => button.title === 'Edit Pembimbing Akademik')
    if (nativeAdvisorAction) return
    const actionContainer = actions.querySelector('button')?.parentElement
    if (!actionContainer) return

    const button = document.createElement('button')
    button.type = 'button'
    button.dataset.ascAdvisorAction = 'true'
    button.className = 'p-1.5 rounded-lg text-violet-600 hover:bg-violet-50'
    button.title = 'Edit Pembimbing Akademik'
    button.setAttribute('aria-label', `Edit Pembimbing Akademik mahasiswa ${nim}`)
    const label = document.createElement('span')
    label.className = 'text-[11px] font-semibold'
    label.textContent = 'PA'
    button.append(label)
    button.addEventListener('click', () => { void openStudent(nim, button, rowElement) })
    actionContainer.append(button)
  })
}

async function openStudent(nim: string, button: HTMLButtonElement, row: HTMLTableRowElement) {
  button.disabled = true
  try {
    const query = new URLSearchParams({ search: nim, per_page: '50' })
    const payload = await request(`/students/advisor-options?${query}`)
    const student = (payload.data ?? []).find((item: Student) => item.nim === nim)
    if (!student) throw new Error('Mahasiswa tidak ditemukan dalam cakupan akun ini.')
    selected.value = student
    selectedRow.value = row
    advisorId.value = student.advisor_id ?? student.advisor?.id ?? ''
    if (lecturers.value.length === 0) {
      const lecturerPayload = await request('/lecturers/all')
      lecturers.value = (Array.isArray(lecturerPayload) ? lecturerPayload : lecturerPayload?.data ?? [])
        .filter((lecturer: Lecturer) => lecturer.status !== false && lecturer.status !== 0 && lecturer.status !== '0')
    }
  } catch (cause) {
    setMessage(cause instanceof Error ? cause.message : 'Gagal memuat pembimbing mahasiswa.', 'error')
  } finally {
    button.disabled = false
  }
}

async function saveAdvisor() {
  if (!selected.value) return
  saving.value = true
  try {
    const payload = await request(`/students/${selected.value.id}/advisor`, {
      method: 'PATCH',
      body: JSON.stringify({ advisor_id: advisorId.value === '' ? null : advisorId.value }),
    })
    if (selectedRow.value) {
      const actionButton = selectedRow.value.querySelector<HTMLButtonElement>('[data-asc-advisor-action]')
      if (actionButton) actionButton.title = `Pembimbing diperbarui untuk ${selected.value.nim}`
    }
    setMessage(payload.message ?? 'Pembimbing Akademik berhasil diperbarui.', 'success')
    selected.value = null
    selectedRow.value = null
  } catch (cause) {
    setMessage(cause instanceof Error ? cause.message : 'Gagal memperbarui pembimbing.', 'error')
  } finally {
    saving.value = false
  }
}

function handleEscape(event: KeyboardEvent) {
  if (event.key === 'Escape') selected.value = null
}

onMounted(() => {
  attachActionButtons()
  observer = new MutationObserver(attachActionButtons)
  const target = document.querySelector('#app main')
  if (target) observer.observe(target, { childList: true, subtree: true })
  window.addEventListener('keydown', handleEscape)
})

onBeforeUnmount(() => {
  observer?.disconnect()
  document.querySelectorAll('[data-asc-advisor-action]').forEach((button) => button.remove())
  window.removeEventListener('keydown', handleEscape)
  clearTimeout(messageTimer)
})
</script>

<template>
  <div class="asc-academic-tools-overlay">
    <div v-if="message" :class="['asc-tools-toast', `asc-tools-toast-${messageType}`]" role="status">{{ message }}</div>
    <div v-if="selected" class="asc-tools-backdrop" @click.self="selected = null">
      <form class="asc-tools-dialog" @submit.prevent="saveAdvisor">
        <h2>Edit Pembimbing Akademik</h2>
        <p>{{ selected.name }} · NIM {{ selected.nim }}</p>
        <label for="advisor-assignment-select">Dosen pembimbing</label>
        <select id="advisor-assignment-select" v-model="advisorId">
          <option value="">-- Tanpa pembimbing --</option>
          <option v-for="lecturer in lecturers" :key="lecturer.id" :value="lecturer.id">
            {{ lecturer.name ?? lecturer.full_name ?? `Dosen ${lecturer.id}` }}
          </option>
        </select>
        <footer>
          <button class="asc-tools-button" type="button" :disabled="saving" @click="selected = null">Batal</button>
          <button class="asc-tools-button asc-tools-button-primary" type="submit" :disabled="saving">
            {{ saving ? 'Menyimpan...' : 'Simpan' }}
          </button>
        </footer>
      </form>
    </div>
  </div>
</template>
