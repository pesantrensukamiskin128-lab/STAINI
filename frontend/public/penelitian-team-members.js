const API_BASE = 'https://api-asc.aljawami.ac.id/api'
const PANEL_ID = 'penelitian-team-members-picker'
const STYLE_ID = 'penelitian-team-members-style'
const originalXhrOpen = XMLHttpRequest.prototype.open
const originalXhrSend = XMLHttpRequest.prototype.send
let selectedMembers = []
let activeProposalId = null
let searchSequence = 0
let searchTimer
let observerQueued = false

function request(path) {
  const token = localStorage.getItem('auth_token')
  if (!token) return Promise.reject(new Error('Sesi login tidak ditemukan. Silakan login kembali.'))

  return fetch(`${API_BASE}${path}`, {
    headers: { Accept: 'application/json', Authorization: `Bearer ${token}` },
  }).then(async (response) => {
    const payload = await response.json().catch(() => ({}))
    if (!response.ok) throw new Error(payload.message ?? `Permintaan gagal (HTTP ${response.status}).`)
    return payload
  })
}

function ensureStyles() {
  if (document.getElementById(STYLE_ID)) return
  const style = document.createElement('style')
  style.id = STYLE_ID
  style.textContent = `
    #${PANEL_ID}{padding:14px;border:1px solid #e5e7eb;border-radius:10px;background:white}
    #${PANEL_ID} .ptm-title{margin:0 0 4px;color:#374151;font-size:14px;font-weight:600}
    #${PANEL_ID} .ptm-hint,#${PANEL_ID} .ptm-meta{margin:0;color:#6b7280;font-size:12px}
    #${PANEL_ID} .ptm-input{box-sizing:border-box;width:100%;margin-top:10px;padding:10px 12px;border:1px solid #d1d5db;border-radius:8px;font-size:13px}
    #${PANEL_ID} .ptm-list{display:grid;gap:7px;margin-top:10px}
    #${PANEL_ID} .ptm-member{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:9px 11px;border:1px solid #e5e7eb;border-radius:8px}
    #${PANEL_ID} .ptm-name{overflow:hidden;color:#1f2937;font-size:13px;font-weight:500;text-overflow:ellipsis;white-space:nowrap}
    #${PANEL_ID} .ptm-remove{flex:none;border:0;background:none;color:#dc2626;font-size:12px;cursor:pointer}
    #${PANEL_ID} .ptm-options{position:relative;z-index:20;max-height:240px;overflow:auto;margin-top:4px;border:1px solid #e5e7eb;border-radius:8px;background:white;box-shadow:0 8px 20px #0002}
    #${PANEL_ID} .ptm-option{display:flex;width:100%;align-items:center;justify-content:space-between;gap:12px;padding:9px 11px;border:0;background:white;text-align:left;cursor:pointer}
    #${PANEL_ID} .ptm-option:hover{background:#eff6ff}
    #${PANEL_ID} .ptm-option:disabled{opacity:.5;cursor:default}
    #${PANEL_ID} .ptm-status{margin:8px 0 0;color:#6b7280;font-size:12px}
    #${PANEL_ID} .ptm-error{color:#b91c1c}
  `
  document.head.append(style)
}

function setStatus(panel, message, isError = false) {
  const status = panel.querySelector('[data-status]')
  status.textContent = message
  status.classList.toggle('ptm-error', isError)
}

function memberLabel(member) {
  return `${member.type === 'lecturer' ? 'Dosen' : 'Mahasiswa'}${member.identifier ? ` · ${member.identifier}` : ''}`
}

function renderSelected(panel) {
  const list = panel.querySelector('[data-members]')
  list.replaceChildren()
  selectedMembers.forEach((member, index) => {
    const row = document.createElement('div')
    row.className = 'ptm-member'
    const details = document.createElement('div')
    details.style.minWidth = '0'
    const name = document.createElement('div')
    name.className = 'ptm-name'
    name.textContent = `${index + 1}. ${member.name}`
    const meta = document.createElement('p')
    meta.className = 'ptm-meta'
    meta.textContent = memberLabel(member)
    details.append(name, meta)
    const remove = document.createElement('button')
    remove.type = 'button'
    remove.className = 'ptm-remove'
    remove.textContent = 'Hapus'
    remove.addEventListener('click', () => {
      selectedMembers = selectedMembers.filter((item) => item.type !== member.type || item.id !== member.id)
      renderSelected(panel)
    })
    row.append(details, remove)
    list.append(row)
  })
}

function renderOptions(panel, options) {
  const container = panel.querySelector('[data-options]')
  container.replaceChildren()
  for (const member of options) {
    const alreadySelected = selectedMembers.some((selected) => selected.type === member.type && selected.id === member.id)
    const button = document.createElement('button')
    button.type = 'button'
    button.className = 'ptm-option'
    button.disabled = alreadySelected
    const details = document.createElement('span')
    const name = document.createElement('span')
    name.className = 'ptm-name'
    name.textContent = member.name
    const meta = document.createElement('span')
    meta.className = 'ptm-meta'
    meta.textContent = memberLabel(member)
    details.append(name, meta)
    const action = document.createElement('span')
    action.style.cssText = 'flex:none;color:#2563eb;font-size:12px'
    action.textContent = alreadySelected ? 'Ditambahkan' : 'Tambah'
    button.append(details, action)
    button.addEventListener('click', () => {
      if (selectedMembers.length >= 50) {
        setStatus(panel, 'Maksimal 50 anggota tim.', true)
        return
      }
      if (!selectedMembers.some((selected) => selected.type === member.type && selected.id === member.id)) {
        selectedMembers.push(member)
      }
      panel.querySelector('[data-search]').value = ''
      const options = panel.querySelector('[data-options]')
      options.replaceChildren()
      options.hidden = true
      setStatus(panel, '')
      renderSelected(panel)
    })
    container.append(button)
  }
}

function createPanel() {
  const panel = document.createElement('section')
  panel.id = PANEL_ID
  panel.innerHTML = `
    <p class="ptm-title">Anggota Tim</p>
    <p class="ptm-hint">Tambahkan dosen atau mahasiswa sebagai anggota proposal. Cari berdasarkan nama, NIDN, atau NIM.</p>
    <div class="ptm-list" data-members></div>
    <input class="ptm-input" data-search type="search" autocomplete="off" placeholder="Cari nama atau NIDN/NIM anggota...">
    <p class="ptm-status" data-status aria-live="polite"></p>
    <div class="ptm-options" data-options hidden></div>
  `
  const input = panel.querySelector('[data-search]')
  const options = panel.querySelector('[data-options]')
  input.addEventListener('input', () => {
    clearTimeout(searchTimer)
    const sequence = ++searchSequence
    const term = input.value.trim()
    options.replaceChildren()
    options.hidden = true
    setStatus(panel, '')
    if (term.length < 2) return

    setStatus(panel, 'Mencari anggota...')
    searchTimer = setTimeout(async () => {
      try {
        const result = await request(`/lecturer-works/authors/search?search=${encodeURIComponent(term)}`)
        if (sequence !== searchSequence || !panel.isConnected) return
        renderOptions(panel, result.data ?? [])
        options.hidden = !result.data?.length
        setStatus(panel, result.data?.length ? '' : 'Tidak ada anggota yang cocok.')
      } catch (error) {
        if (sequence === searchSequence && panel.isConnected) setStatus(panel, error.message, true)
      }
    }, 300)
  })
  renderSelected(panel)
  return panel
}

function appPath() {
  return location.pathname.replace(/^\/asc(?=\/|$)/, '') || '/'
}

function mount() {
  const path = appPath()
  const proposalId = path.match(/^\/penelitian\/(\d+)\/edit\/?$/)?.[1] ?? null
  const isCreate = path === '/penelitian/buat'
  const existing = document.getElementById(PANEL_ID)
  if (!isCreate && !proposalId) {
    existing?.remove()
    activeProposalId = null
    selectedMembers = []
    return
  }

  const form = document.querySelector('form')
  const lecturerLabel = [...(form?.querySelectorAll('label') ?? [])]
    .find((label) => label.textContent.trim().startsWith('Anggota Dosen'))
  if (!form || !lecturerLabel) return
  if (existing?.dataset.proposalId === (proposalId ?? 'new')) return
  existing?.remove()

  activeProposalId = proposalId
  selectedMembers = []
  lecturerLabel.parentElement.style.display = 'none'
  const studentLabel = [...form.querySelectorAll('label')]
    .find((label) => label.textContent.trim().startsWith('Anggota Mahasiswa'))
  if (studentLabel) studentLabel.parentElement.style.display = 'none'

  const panel = createPanel()
  panel.dataset.proposalId = proposalId ?? 'new'
  lecturerLabel.parentElement.insertAdjacentElement('afterend', panel)
  if (!panel.isConnected || !proposalId) return

  request(`/penelitian/${proposalId}`)
    .then((proposal) => {
      if (!panel.isConnected || activeProposalId !== String(proposal.id)) return
      selectedMembers = (proposal.members ?? []).map((entry) => {
        const isLecturer = entry.member_type === 'dosen'
        const person = isLecturer ? entry.lecturer : entry.student
        return {
          type: isLecturer ? 'lecturer' : 'student',
          id: Number(isLecturer ? entry.lecturer_id : entry.student_id),
          name: person?.name ?? 'Anggota tidak ditemukan',
          identifier: person?.nidn ?? person?.nim ?? '',
        }
      })
      renderSelected(panel)
    })
    .catch((error) => setStatus(panel, error.message, true))
}

XMLHttpRequest.prototype.open = function (method, url, ...args) {
  this.__penelitianMethod = method
  this.__penelitianUrl = String(url)
  return originalXhrOpen.call(this, method, url, ...args)
}

XMLHttpRequest.prototype.send = function (body) {
  const url = this.__penelitianUrl ?? ''
  const method = (this.__penelitianMethod ?? '').toUpperCase()
  if (/\/api\/penelitian(?:\/\d+)?\/?$/.test(url) && ['POST', 'PUT'].includes(method) && typeof body === 'string') {
    try {
      const payload = JSON.parse(body)
      payload.dosen_members = selectedMembers.filter((member) => member.type === 'lecturer').map((member) => member.id)
      payload.mahasiswa_members = selectedMembers.filter((member) => member.type === 'student').map((member) => member.id)
      body = JSON.stringify(payload)
    } catch (error) {
      console.error('Gagal menambahkan anggota tim pada proposal:', error)
    }
  }
  return originalXhrSend.call(this, body)
}

function scheduleMount() {
  if (observerQueued) return
  observerQueued = true
  queueMicrotask(() => {
    observerQueued = false
    ensureStyles()
    mount()
  })
}

new MutationObserver(scheduleMount).observe(document.body, { childList: true, subtree: true })
window.addEventListener('popstate', scheduleMount)
window.addEventListener('pageshow', scheduleMount)
const originalPushState = history.pushState
const originalReplaceState = history.replaceState
history.pushState = function (...args) {
  const result = originalPushState.apply(this, args)
  scheduleMount()
  return result
}
history.replaceState = function (...args) {
  const result = originalReplaceState.apply(this, args)
  scheduleMount()
  return result
}
scheduleMount()
