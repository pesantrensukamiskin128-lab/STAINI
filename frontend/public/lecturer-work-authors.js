const API_BASE = 'https://api-asc.aljawami.ac.id/api'
const PANEL_ID = 'lecturer-work-coauthors'
const STYLE_ID = 'lecturer-work-coauthors-style'
const originalXhrOpen = XMLHttpRequest.prototype.open
const originalXhrSend = XMLHttpRequest.prototype.send
let selectedAuthors = []
let activeWorkId = null
let searchSequence = 0
let searchTimer
let observerQueued = false

function request(path, options = {}) {
  const token = localStorage.getItem('auth_token')
  if (!token) return Promise.reject(new Error('Sesi login tidak ditemukan. Silakan login kembali.'))

  return fetch(`${API_BASE}${path}`, {
    ...options,
    headers: {
      Accept: 'application/json',
      Authorization: `Bearer ${token}`,
      ...options.headers,
    },
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
    #${PANEL_ID}{padding:16px;border:1px solid #e5e7eb;border-radius:12px;background:white}
    #${PANEL_ID} .lwa-title{margin:0 0 4px;color:#374151;font-size:14px;font-weight:600}
    #${PANEL_ID} .lwa-hint,#${PANEL_ID} .lwa-meta{margin:0;color:#6b7280;font-size:12px}
    #${PANEL_ID} .lwa-input{box-sizing:border-box;width:100%;margin-top:10px;padding:9px 12px;border:1px solid #d1d5db;border-radius:8px;font-size:13px}
    #${PANEL_ID} .lwa-list{display:grid;gap:7px;margin-top:10px}
    #${PANEL_ID} .lwa-author{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:9px 11px;border:1px solid #e5e7eb;border-radius:8px}
    #${PANEL_ID} .lwa-name{overflow:hidden;color:#1f2937;font-size:13px;font-weight:500;text-overflow:ellipsis;white-space:nowrap}
    #${PANEL_ID} .lwa-remove{flex:none;border:0;background:none;color:#dc2626;font-size:12px;cursor:pointer}
    #${PANEL_ID} .lwa-options{position:relative;z-index:20;max-height:240px;overflow:auto;margin-top:4px;border:1px solid #e5e7eb;border-radius:8px;background:white;box-shadow:0 8px 20px #0002}
    #${PANEL_ID} .lwa-option{display:flex;width:100%;align-items:center;justify-content:space-between;gap:12px;padding:9px 11px;border:0;background:white;text-align:left;cursor:pointer}
    #${PANEL_ID} .lwa-option:hover{background:#eff6ff}
    #${PANEL_ID} .lwa-option:disabled{opacity:.5;cursor:default}
    #${PANEL_ID} .lwa-status{margin:8px 0 0;color:#6b7280;font-size:12px}
    #${PANEL_ID} .lwa-error{color:#b91c1c}
    #${PANEL_ID} .lwa-detail{margin-top:8px}
    #${PANEL_ID} .lwa-detail ol{margin:4px 0 0;padding-left:20px;color:#4b5563;font-size:12px}
  `
  document.head.append(style)
}

function personLabel(author) {
  const kind = author.type === 'lecturer' ? 'Dosen' : 'Mahasiswa'
  return `${kind}${author.identifier ? ` · ${author.identifier}` : ''}`
}

function renderSelected(panel) {
  const list = panel.querySelector('[data-authors]')
  list.replaceChildren()
  selectedAuthors.forEach((author, index) => {
    const row = document.createElement('div')
    row.className = 'lwa-author'
    const details = document.createElement('div')
    details.style.minWidth = '0'
    const name = document.createElement('div')
    name.className = 'lwa-name'
    name.textContent = `${index + 2}. ${author.name}`
    const meta = document.createElement('p')
    meta.className = 'lwa-meta'
    meta.textContent = personLabel(author)
    details.append(name, meta)
    const remove = document.createElement('button')
    remove.type = 'button'
    remove.className = 'lwa-remove'
    remove.textContent = 'Hapus'
    remove.addEventListener('click', () => {
      selectedAuthors = selectedAuthors.filter((item) => item.type !== author.type || item.id !== author.id)
      renderSelected(panel)
    })
    row.append(details, remove)
    list.append(row)
  })
}

function renderOptions(panel, options) {
  const container = panel.querySelector('[data-options]')
  container.replaceChildren()
  if (!options.length) return

  for (const author of options) {
    const alreadySelected = selectedAuthors.some((selected) => selected.type === author.type && selected.id === author.id)
    const button = document.createElement('button')
    button.type = 'button'
    button.className = 'lwa-option'
    button.disabled = alreadySelected
    const details = document.createElement('span')
    const name = document.createElement('span')
    name.className = 'lwa-name'
    name.textContent = author.name
    const meta = document.createElement('span')
    meta.className = 'lwa-meta'
    meta.textContent = personLabel(author)
    details.append(name, meta)
    const action = document.createElement('span')
    action.style.cssText = 'flex:none;color:#2563eb;font-size:12px'
    action.textContent = alreadySelected ? 'Ditambahkan' : 'Tambah'
    button.append(details, action)
    button.addEventListener('click', () => {
      if (selectedAuthors.length >= 50) {
        setStatus(panel, 'Maksimal 50 penulis bersama.', true)
        return
      }
      if (!selectedAuthors.some((selected) => selected.type === author.type && selected.id === author.id)) {
        selectedAuthors.push(author)
      }
      panel.querySelector('[data-search]').value = ''
      const options = panel.querySelector('[data-options]')
      options.replaceChildren()
      options.hidden = true
      panel.querySelector('[data-status]').textContent = ''
      renderSelected(panel)
    })
    container.append(button)
  }
}

function setStatus(panel, message, isError = false) {
  const status = panel.querySelector('[data-status]')
  status.textContent = message
  status.classList.toggle('lwa-error', isError)
}

function createPanel() {
  const panel = document.createElement('section')
  panel.id = PANEL_ID
  panel.innerHTML = `
    <p class="lwa-title">Penulis Bersama</p>
    <p class="lwa-hint">Dosen pemilik karya adalah penulis utama. Cari dosen atau mahasiswa berdasarkan nama maupun NIDN/NIM.</p>
    <div class="lwa-list" data-authors></div>
    <input class="lwa-input" data-search type="search" autocomplete="off" placeholder="Cari nama atau NIDN/NIM penulis...">
    <p class="lwa-status" data-status aria-live="polite"></p>
    <div class="lwa-options" data-options hidden></div>
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

    setStatus(panel, 'Mencari penulis...')
    searchTimer = setTimeout(async () => {
      try {
        const result = await request(`/lecturer-works/authors/search?search=${encodeURIComponent(term)}`)
        if (sequence !== searchSequence || !panel.isConnected) return
        renderOptions(panel, result.data ?? [])
        options.hidden = !result.data?.length
        setStatus(panel, result.data?.length ? '' : 'Tidak ada penulis yang cocok.')
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

function mountForm(titleInput, path) {
  const requestedWorkId = path.match(/^\/karya-dosen\/(\d+)\/edit\/?$/)?.[1] ?? null
  const existing = document.getElementById(PANEL_ID)
  if (existing?.dataset.workId === (requestedWorkId ?? 'new')) return true
  existing?.remove()

  activeWorkId = requestedWorkId
  selectedAuthors = []
  const panel = createPanel()
  panel.dataset.workId = activeWorkId ?? 'new'
  const titleField = titleInput.closest('div')
  if (!titleField) return false
  titleField.insertAdjacentElement('afterend', panel)
  if (!panel.isConnected) return false

  if (activeWorkId) {
    request(`/lecturer-works/${activeWorkId}`)
      .then((work) => {
        if (activeWorkId !== String(work.id) || !panel.isConnected) return
        selectedAuthors = (work.co_authors ?? []).map((author) => {
          const person = author.lecturer ?? author.student
          return {
            type: author.lecturer_id ? 'lecturer' : 'student',
            id: author.lecturer_id ?? author.student_id,
            name: person?.name ?? 'Penulis tidak ditemukan',
            identifier: person?.nidn ?? person?.nim ?? '',
          }
        })
        renderSelected(panel)
      })
      .catch((error) => setStatus(panel, error.message, true))
  }
  return true
}

function mountDetail() {
  const match = appPath().match(/^\/karya-dosen\/(\d+)\/?$/)
  if (!match) return false
  const workId = match[1]
  if (document.getElementById(PANEL_ID)?.dataset.workId === workId) return true

  const heading = [...document.querySelectorAll('h1')].find((item) => item.textContent.trim().length > 0)
  if (!heading?.parentElement) return false
  document.getElementById(PANEL_ID)?.remove()
  const panel = document.createElement('section')
  panel.id = PANEL_ID
  panel.className = 'lwa-detail'
  panel.dataset.workId = workId
  panel.innerHTML = '<p class="lwa-title">Penulis</p><ol data-author-list><li>Memuat penulis...</li></ol>'
  heading.parentElement.append(panel)

  request(`/lecturer-works/${workId}`)
    .then((work) => {
      if (!panel.isConnected || panel.dataset.workId !== String(work.id)) return
      const list = panel.querySelector('[data-author-list]')
      list.replaceChildren()
      const authors = [
        { name: work.lecturer?.name ?? 'Penulis utama', type: null, order: 1 },
        ...(work.co_authors ?? []).map((author) => ({
          name: author.lecturer?.name ?? author.student?.name ?? 'Penulis tidak ditemukan',
          type: author.lecturer ? 'Dosen' : 'Mahasiswa',
          order: author.author_order,
        })),
      ]
      if (authors.length < 2) {
        panel.remove()
        return
      }
      for (const author of authors) {
        const item = document.createElement('li')
        item.textContent = `${author.order}. ${author.name}${author.type ? ` (${author.type})` : ''}`
        list.append(item)
      }
    })
    .catch((error) => {
      const list = panel.querySelector('[data-author-list]')
      list.replaceChildren()
      const item = document.createElement('li')
      item.textContent = error.message
      list.append(item)
    })
  return true
}

function mount() {
  const path = appPath()
  const titleInput = [...document.querySelectorAll('input[placeholder]')]
    .find((input) => input.placeholder.trim().toLowerCase().startsWith('judul karya'))
  const isFormPage = path === '/karya-dosen/buat'
    || /^\/karya-dosen\/\d+\/edit\/?$/.test(path)
  if ((isFormPage || titleInput) && titleInput && mountForm(titleInput, path)) return
  if (/^\/karya-dosen\/\d+\/?$/.test(path) && mountDetail()) return

  document.getElementById(PANEL_ID)?.remove()
  activeWorkId = null
  selectedAuthors = []
}

XMLHttpRequest.prototype.open = function (method, url, ...args) {
  this.__lecturerWorkMethod = method
  this.__lecturerWorkUrl = String(url)
  return originalXhrOpen.call(this, method, url, ...args)
}

XMLHttpRequest.prototype.send = function (body) {
  const url = this.__lecturerWorkUrl ?? ''
  const method = (this.__lecturerWorkMethod ?? '').toUpperCase()
  if (method === 'POST' && /\/api\/lecturer-works(?:\/\d+)?\/?$/.test(url) && body instanceof FormData) {
    body.set('authors', JSON.stringify(selectedAuthors.map(({ type, id }) => ({ type, id }))))
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
window.addEventListener('pageshow', scheduleMount)
window.addEventListener('hashchange', scheduleMount)
scheduleMount()
