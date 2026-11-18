<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref } from 'vue'

interface ReviewKrs {
  id: number
  status: string
  student?: { nim?: string; name?: string }
  semester?: { name?: string }
}

const apiBase = 'https://api-asc.aljawami.ac.id/api'
const token = localStorage.getItem('auth_token')
const rejectTarget = ref<ReviewKrs | null>(null)
const rejectRow = ref<HTMLTableRowElement | null>(null)
const rejectNim = ref('')
const rejectSemester = ref('')
const rejectionNote = ref('')
const rejecting = ref(false)
const message = ref('')
const messageType = ref<'success' | 'error'>('success')
let observer: MutationObserver | undefined
let messageTimer: ReturnType<typeof setTimeout> | undefined
const busyRows = new WeakSet<HTMLTableRowElement>()
const checkedRows = new WeakSet<HTMLTableRowElement>()

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

function addActionButtons(row: HTMLTableRowElement, nim: string, semester: string) {
  if (!row.isConnected || row.dataset.ascKrsProcessed === 'true' || row.querySelector('[data-asc-krs-actions]')) return
  const cells = row.querySelectorAll('td')
  const actions = cells[cells.length - 1]
  if (!actions) return
  if (hasNativeReviewActions(row)) return
  const container = document.createElement('span')
  container.dataset.ascKrsActions = 'true'
  container.className = 'asc-inline-krs-actions'

  const approveButton = document.createElement('button')
  approveButton.type = 'button'
  approveButton.className = 'asc-inline-action asc-inline-action-approve'
  approveButton.textContent = 'Setujui'
  approveButton.addEventListener('click', () => { void decide(row, nim, semester, true) })

  const rejectButton = document.createElement('button')
  rejectButton.type = 'button'
  rejectButton.className = 'asc-inline-action asc-inline-action-reject'
  rejectButton.textContent = 'Tolak'
  rejectButton.addEventListener('click', () => { selectReject(row, nim, semester) })

  container.append(approveButton, rejectButton)
  actions.append(container)
}

function hasNativeReviewActions(row: HTMLTableRowElement) {
  const actions = row.querySelector('td:last-child')
  return Array.from(actions?.querySelectorAll('button') ?? [])
    .some((button) => ['Setujui', 'Tolak', 'Setujui Prodi', 'Tolak Prodi'].includes(button.textContent?.trim() ?? ''))
}

async function checkAndAttachActions(row: HTMLTableRowElement, nim: string, semester: string) {
  const query = new URLSearchParams({ queue: 'PENDING', search: nim, per_page: '50' })
  try {
    const payload = await request(`/krs/prodi-review?${query}`)
    const match = (payload.data ?? []).find((item: ReviewKrs) =>
      item.student?.nim === nim && item.semester?.name === semester,
    )
    if (match) addActionButtons(row, nim, semester)
  } catch (cause) {
    setMessage(cause instanceof Error ? cause.message : 'Gagal memeriksa antrean peninjauan KRS.', 'error')
  }
}

function attachActionButtons() {
  document.querySelectorAll<HTMLTableRowElement>('#app main table tbody tr').forEach((row) => {
    if (checkedRows.has(row) || row.dataset.ascKrsProcessed === 'true' || row.querySelector('[data-asc-krs-actions]')) return
    const cells = row.querySelectorAll('td')
    if (cells.length < 6) return
    if (hasNativeReviewActions(row)) {
      checkedRows.add(row)
      return
    }
    const status = cells[4]?.querySelector('span')?.textContent?.trim() ?? ''
    const advisor = cells[3]?.textContent?.trim() ?? ''
    const candidate = ['APPROVED', 'Disetujui Dosen Wali'].includes(status)
      || (['SUBMITTED', 'Diajukan'].includes(status) && (advisor === '-' || advisor === 'Belum ditentukan'))
    if (!candidate) return
    const nimText = cells[0]?.querySelector('p:nth-child(2)')?.textContent ?? ''
    const nim = nimText.split('·')[0]?.trim() ?? ''
    const semester = cells[1]?.textContent?.trim() ?? ''
    if (!nim || !semester) return
    checkedRows.add(row)
    void checkAndAttachActions(row, nim, semester)
  })
}

async function resolveKrs(nim: string, semesterName: string) {
  for (let page = 1; page <= 5; page++) {
    const query = new URLSearchParams({ queue: 'PENDING', search: nim, per_page: '100', page: String(page) })
    const payload = await request(`/krs/prodi-review?${query}`)
    const result = (payload.data ?? []).find((item: ReviewKrs) =>
      item.student?.nim === nim && item.semester?.name === semesterName,
    )
    if (result) return result as ReviewKrs
    if (page >= Number(payload.last_page ?? 1)) break
  }
  throw new Error('KRS tidak lagi berada dalam antrean peninjauan prodi. Muat ulang daftar untuk memperbarui status.')
}

async function decide(row: HTMLTableRowElement, nim: string, semester: string, approve: boolean) {
  if (busyRows.has(row)) return
  if (approve && !confirm(`Setujui KRS ${nim} pada tahap program studi?`)) return
  busyRows.add(row)
  row.querySelectorAll<HTMLButtonElement>('[data-asc-krs-actions] button').forEach((button) => { button.disabled = true })
  try {
    const krs = await resolveKrs(nim, semester)
    const payload = await request(`/krs/${krs.id}/${approve ? 'approve-kaprodi' : 'reject-kaprodi'}`, approve
      ? { method: 'POST' }
      : { method: 'POST', body: JSON.stringify({ note: rejectionNote.value.trim() }) })
    setMessage(payload.message ?? (approve ? 'KRS disetujui pada tahap prodi.' : 'KRS ditolak pada tahap prodi.'), 'success')
    rejectTarget.value = null
    rejectRow.value = null
    rejectionNote.value = ''
    row.dataset.ascKrsProcessed = 'true'
    row.querySelector('[data-asc-krs-actions]')?.remove()
  } catch (cause) {
    setMessage(cause instanceof Error ? cause.message : 'Gagal memproses KRS.', 'error')
    busyRows.delete(row)
    row.querySelectorAll<HTMLButtonElement>('[data-asc-krs-actions] button').forEach((button) => { button.disabled = false })
  }
}

function selectReject(row: HTMLTableRowElement, nim: string, semester: string) {
  if (busyRows.has(row)) return
  rejectTarget.value = { id: 0, status: 'PENDING', student: { nim, name: row.cells[0]?.querySelector('p')?.textContent ?? '' }, semester: { name: semester } }
  rejectRow.value = row
  rejectNim.value = nim
  rejectSemester.value = semester
  rejectionNote.value = ''
}

async function reject() {
  if (!rejectTarget.value || !rejectRow.value || !rejectionNote.value.trim()) return
  rejecting.value = true
  try {
    await decide(rejectRow.value, rejectNim.value, rejectSemester.value, false)
  } finally {
    rejecting.value = false
  }
}

function handleEscape(event: KeyboardEvent) {
  if (event.key === 'Escape') rejectTarget.value = null
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
  document.querySelectorAll('[data-asc-krs-actions]').forEach((actions) => actions.remove())
  window.removeEventListener('keydown', handleEscape)
  clearTimeout(messageTimer)
})
</script>

<template>
  <div class="asc-academic-tools-overlay">
    <div v-if="message" :class="['asc-tools-toast', `asc-tools-toast-${messageType}`]" role="status">{{ message }}</div>
    <div v-if="rejectTarget" class="asc-tools-backdrop" @click.self="rejectTarget = null">
      <form class="asc-tools-dialog" @submit.prevent="reject">
        <h2>Tolak KRS pada Tahap Prodi</h2>
        <p>{{ rejectTarget.student?.name }} · NIM {{ rejectTarget.student?.nim }}</p>
        <label for="prodi-rejection-note">Catatan penolakan <span aria-hidden="true">*</span></label>
        <textarea id="prodi-rejection-note" v-model="rejectionNote" required maxlength="2000" rows="4" placeholder="Jelaskan alasan penolakan dan hal yang perlu diperbaiki." />
        <footer>
          <button class="asc-tools-button" type="button" :disabled="rejecting" @click="rejectTarget = null">Batal</button>
          <button class="asc-tools-button asc-tools-button-danger" type="submit" :disabled="rejecting || !rejectionNote.trim()">
            {{ rejecting ? 'Memproses...' : 'Tolak KRS' }}
          </button>
        </footer>
      </form>
    </div>
  </div>
</template>
