(() => {
  const apiBase = 'https://api-asc.aljawami.ac.id/api'
  const statusLabels = {
    DRAFT: 'Draf',
    SUBMITTED: 'Diajukan',
    APPROVED: 'Disetujui Dosen Wali',
    DISETUJUI_PRODI: 'Disetujui Prodi',
    REJECTED: 'Ditolak',
    DITOLAK_PRODI: 'Ditolak Prodi',
    CANCELLED: 'Dibatalkan',
    DITANDATANGANI: 'Ditandatangani',
    BELUM_ISI: 'Belum Diisi',
  }
  const statusColors = {
    DRAFT: ['bg-gray-100', 'text-gray-600'],
    SUBMITTED: ['bg-yellow-100', 'text-yellow-700'],
    APPROVED: ['bg-amber-100', 'text-amber-800'],
    DISETUJUI_PRODI: ['bg-indigo-100', 'text-indigo-700'],
    DITANDATANGANI: ['bg-green-100', 'text-green-700'],
    REJECTED: ['bg-red-100', 'text-red-600'],
    DITOLAK_PRODI: ['bg-rose-100', 'text-rose-700'],
    CANCELLED: ['bg-gray-100', 'text-gray-400'],
  }
  const statusColorClasses = Object.values(statusColors).flat()
  let currentRoute = ''
  let records = []
  let refreshTimer
  let fetchKey = ''
  let loadingKey = ''
  let failedFetchKey = ''
  let dashboardStatus = ''
  let viewer = null
  let viewerLoading = false
  let viewerLoaded = false
  let viewerFailed = false
  let rejectModal = null
  const actingKrsIds = new Set()

  function token() {
    return localStorage.getItem('auth_token')
  }

  async function request(path) {
    const authToken = token()
    if (!authToken) return null
    const response = await fetch(`${apiBase}${path}`, {
      headers: { Accept: 'application/json', Authorization: `Bearer ${authToken}` },
    })
    if (!response.ok) throw new Error(`Permintaan data KRS gagal (HTTP ${response.status}).`)
    return response.json()
  }

  async function post(path, body) {
    const authToken = token()
    if (!authToken) throw new Error('Sesi login tidak ditemukan. Silakan masuk kembali.')
    const response = await fetch(`${apiBase}${path}`, {
      method: 'POST',
      headers: {
        Accept: 'application/json',
        'Content-Type': 'application/json',
        Authorization: `Bearer ${authToken}`,
      },
      body: JSON.stringify(body),
    })
    const payload = await response.json()
    if (!response.ok) throw new Error(payload.message ?? `Permintaan gagal (HTTP ${response.status}).`)
    return payload
  }

  async function fetchViewer() {
    if (viewerLoaded || viewerLoading || viewerFailed) return
    viewerLoading = true
    try {
      viewer = await request('/auth/me')
      viewerLoaded = Boolean(viewer)
    } catch (error) {
      viewerFailed = true
      throw error
    } finally {
      viewerLoading = false
    }
  }

  function canReviewKaprodi() {
    const roles = viewer?.roles ?? []
    const positions = viewer?.position_codes ?? []
    return roles.some((role) => ['SUPER_ADMIN', 'ADMIN_AKADEMIK'].includes(role))
      || positions.some((code) => ['KAPRODI', 'SEKPRODI'].includes(code))
  }

  function statusForRecord(record) {
    if (record.signed_by_kaprodi_at) return 'DITANDATANGANI'
    if (record.kaprodi_approved_at) return 'DISETUJUI_PRODI'
    if (record.status === 'REJECTED' && record.rejected_by_stage === 'KAPRODI') return 'DITOLAK_PRODI'
    return record.display_status ?? record.status
  }

  function labelForRecord(record) {
    const status = statusForRecord(record)
    return statusLabels[status] ?? record.display_status_label ?? labelFor(status)
  }

  function awaitingKaprodiReview(record) {
    return (record.status === 'APPROVED' || (record.status === 'SUBMITTED' && !record.advisor_id))
      && !record.kaprodi_approved_at
      && !record.signed_by_kaprodi_at
  }

  function labelFor(status) {
    return statusLabels[status] ?? String(status ?? '').replace(/_/g, ' ')
  }

  function applyStatus(element, record) {
    if (!element || !record) return
    const status = statusForRecord(record)
    const label = labelForRecord(record)
    if (element.textContent !== label) element.textContent = label
    element.classList.remove(...statusColorClasses)
    element.classList.add(...(statusColors[status] ?? statusColors.DRAFT))
  }

  function selectedValue(select) {
    return select?.value && select.value !== '0' ? select.value : ''
  }

  function currentListPage() {
    return Number(document.querySelector('#app main nav[aria-label="Navigasi halaman tabel"] [aria-current="page"]')?.textContent) || 1
  }

  function listFilters() {
    const selects = Array.from(document.querySelectorAll('#app main select'))
    const semester = selects.find((select) => Array.from(select.options).some((option) => /^(Semua Semester|Ganjil|Genap)/.test(option.textContent.trim())))
    const program = selects.find((select) => Array.from(select.options).some((option) => option.textContent.includes('Semua Prodi')))
    const status = selects.find((select) => Array.from(select.options).some((option) => ['DRAFT', 'SUBMITTED', 'APPROVED', 'DISETUJUI_PRODI', 'REJECTED', 'DITOLAK_PRODI', 'DITANDATANGANI'].includes(option.value)))
    const search = document.querySelector('#app main input[placeholder*="NIM"]')
    return {
      semester: selectedValue(semester),
      program: selectedValue(program),
      search: search?.value?.trim() ?? '',
      status,
    }
  }

  function translateListControls(filters) {
    const options = {
      DRAFT: 'Draf',
      SUBMITTED: 'Diajukan',
      APPROVED: 'Disetujui Dosen Wali',
      DISETUJUI_PRODI: 'Disetujui Prodi',
      REJECTED: 'Ditolak',
      DITOLAK_PRODI: 'Ditolak Prodi',
      CANCELLED: 'Dibatalkan',
    }
    for (const select of document.querySelectorAll('#app main select')) {
      for (const option of select.options) {
        if (options[option.value] && option.textContent !== options[option.value]) option.textContent = options[option.value]
      }
      if (Array.from(select.options).some((option) => option.value === 'APPROVED')) {
        if (!Array.from(select.options).some((option) => option.value === 'DITANDATANGANI')) {
          const signedOption = document.createElement('option')
          signedOption.value = 'DITANDATANGANI'
          signedOption.textContent = 'Ditandatangani'
          select.add(signedOption)
        }
        for (const [value, label] of [['DISETUJUI_PRODI', 'Disetujui Prodi'], ['DITOLAK_PRODI', 'Ditolak Prodi']]) {
          if (!Array.from(select.options).some((option) => option.value === value)) {
            const stageOption = document.createElement('option')
            stageOption.value = value
            stageOption.textContent = label
            select.add(stageOption)
          }
        }
      }
    }
  }

  async function fetchListRecords() {
    const filters = listFilters()
    const page = currentListPage()
    const key = JSON.stringify([location.pathname, filters.semester, filters.program, filters.search, filters.status?.value, page])
    if (key === fetchKey || key === loadingKey || key === failedFetchKey) return
    loadingKey = key
    records = []
    const params = new URLSearchParams({ per_page: '20', page: String(page) })
    if (filters.semester) params.set('semester_id', filters.semester)
    if (filters.program) params.set('study_program_id', filters.program)
    if (filters.search) params.set('search', filters.search)
    if (filters.status?.value) params.set('status', filters.status.value)

    try {
      const payload = await request(`/krs?${params}`)
      if (!payload) return
      records = payload.data ?? []
      fetchKey = key
      failedFetchKey = ''
    } catch (error) {
      failedFetchKey = key
      throw error
    } finally {
      if (loadingKey === key) loadingKey = ''
    }
  }

  async function fetchDetailRecord() {
    const match = location.pathname.match(/^\/akademik\/krs\/(\d+)\/?$/)
    if (!match) return
    const key = `detail:${match[1]}`
    if (key === fetchKey) return
    fetchKey = key
    const record = await request(`/krs/${match[1]}`)
    records = record ? [record] : []
  }

  async function fetchStudentRecord() {
    const key = `student:${location.pathname}`
    if (fetchKey === key) return
    fetchKey = key
    const payload = await request('/krs?per_page=100')
    if (!payload) return
    const semesterText = Array.from(document.querySelectorAll('#app main p'))
      .map((element) => element.textContent.trim())
      .find((text) => text.includes('Kartu Rencana Studi'))
    const semesterName = semesterText?.split('—').pop()?.trim()
    records = payload.data ?? []
    if (semesterName) records = records.filter((record) => record.semester?.name === semesterName)
  }

  async function fetchDashboardStatus() {
    const key = 'dashboard'
    if (fetchKey === key) return
    fetchKey = key
    const payload = await request('/dashboard')
    dashboardStatus = payload?.academic?.krs_status ?? ''
  }

  function patchList() {
    const filters = listFilters()
    translateListControls(filters)
    const rows = document.querySelectorAll('#app main table tbody tr')
    for (const row of rows) {
      const cells = row.querySelectorAll('td')
      if (cells.length < 5) continue
      const nimCell = cells[0].textContent
      const nim = nimCell.match(/[0-9]{6,}/)?.[0]
      const semester = cells[1].textContent.trim()
      if (!nim) continue
      const record = records.find((item) => item.student?.nim === nim && item.semester?.name === semester)
      applyStatus(cells[4].querySelector('span'), record)
      let stageNote = cells[4].querySelector('[data-krs-stage-note]')
      const stageNoteText = record?.kaprodi_approved_at && !record?.signed_by_kaprodi_at ? 'Menunggu tanda tangan Kaprodi' : ''
      if (stageNoteText) {
        if (!stageNote) {
          stageNote = document.createElement('span')
          stageNote.dataset.krsStageNote = 'true'
          stageNote.className = 'text-xs text-blue-700'
          ;(cells[4].querySelector('div') ?? cells[4]).append(stageNote)
        }
        if (stageNote && stageNote.textContent !== stageNoteText) stageNote.textContent = stageNoteText
      } else {
        stageNote?.remove()
      }
      patchKaprodiActions(cells[cells.length - 1], record)
    }
  }

  function patchKaprodiActions(cell, record) {
    cell?.querySelector('[data-krs-kaprodi-actions]')?.remove()
  }

  function createKaprodiButtons(record, detail) {
    const actions = document.createElement('span')
    actions.dataset.krsKaprodiActions = 'true'
    actions.className = detail ? 'inline-flex items-center gap-2' : 'inline-flex items-center gap-1 ml-1'
    const approve = document.createElement('button')
    approve.type = 'button'
    approve.dataset.krsAction = 'approve'
    approve.dataset.krsId = String(record.id)
    approve.textContent = detail ? 'Setujui Prodi' : 'Setujui'
    approve.className = `rounded-lg bg-green-600 text-xs font-medium text-white hover:bg-green-700 disabled:opacity-50 ${detail ? 'px-3 py-2' : 'px-2 py-1'}`
    const reject = document.createElement('button')
    reject.type = 'button'
    reject.dataset.krsAction = 'reject'
    reject.dataset.krsId = String(record.id)
    reject.textContent = detail ? 'Tolak Prodi' : 'Tolak'
    reject.className = `rounded-lg bg-red-50 text-xs font-medium text-red-700 hover:bg-red-100 disabled:opacity-50 ${detail ? 'px-3 py-2' : 'px-2 py-1'}`
    const busy = actingKrsIds.has(record.id)
    approve.disabled = busy
    reject.disabled = busy
    actions.append(approve, reject)
    return actions
  }

  function showNotice(message, isError = false) {
    document.querySelector('[data-krs-notice]')?.remove()
    const notice = document.createElement('div')
    notice.dataset.krsNotice = 'true'
    notice.setAttribute('role', 'status')
    notice.setAttribute('aria-live', 'polite')
    notice.className = `fixed top-4 right-4 z-[10000] max-w-sm rounded-lg px-4 py-3 text-sm shadow-lg ${isError ? 'bg-red-700 text-white' : 'bg-green-700 text-white'}`
    notice.textContent = message
    document.body.append(notice)
    setTimeout(() => notice.remove(), 5000)
  }

  function closeRejectModal() {
    rejectModal?.remove()
    rejectModal = null
  }

  function openRejectModal(record) {
    closeRejectModal()
    const overlay = document.createElement('div')
    overlay.className = 'fixed inset-0 z-[9999] flex items-center justify-center bg-black/50 p-4'
    const panel = document.createElement('section')
    panel.setAttribute('role', 'dialog')
    panel.setAttribute('aria-modal', 'true')
    panel.setAttribute('aria-labelledby', 'krs-rejection-title')
    panel.className = 'w-full max-w-lg rounded-xl bg-white p-5 shadow-xl'

    const title = document.createElement('h2')
    title.id = 'krs-rejection-title'
    title.className = 'text-lg font-semibold text-gray-900'
    title.textContent = 'Tolak KRS pada Tahap Prodi'
    const student = document.createElement('p')
    student.className = 'mt-2 text-sm text-gray-600'
    student.textContent = `${record.student?.name ?? ''} · ${record.student?.nim ?? ''}`
    const label = document.createElement('label')
    label.className = 'mt-4 block text-sm font-medium text-gray-700'
    label.textContent = 'Catatan penolakan (wajib)'
    const note = document.createElement('textarea')
    note.maxLength = 2000
    note.rows = 4
    note.required = true
    note.placeholder = 'Jelaskan alasan penolakan dan hal yang perlu diperbaiki.'
    note.className = 'mt-1 w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500'
    label.append(note)
    const footer = document.createElement('div')
    footer.className = 'mt-5 flex justify-end gap-2'
    const cancel = document.createElement('button')
    cancel.type = 'button'
    cancel.textContent = 'Batal'
    cancel.className = 'rounded-lg px-4 py-2 text-sm text-gray-600 hover:bg-gray-100'
    cancel.addEventListener('click', closeRejectModal)
    const submit = document.createElement('button')
    submit.type = 'button'
    submit.textContent = 'Tolak KRS'
    submit.disabled = true
    submit.className = 'rounded-lg bg-red-600 px-4 py-2 text-sm font-medium text-white hover:bg-red-700 disabled:bg-red-300'
    note.addEventListener('input', () => { submit.disabled = !note.value.trim() })
    submit.addEventListener('click', async () => {
      const rejectionNote = note.value.trim()
      if (!rejectionNote) return
      submit.disabled = true
      cancel.disabled = true
      await performKaprodiAction(record, 'reject', { note: rejectionNote })
    })
    footer.append(cancel, submit)
    panel.append(title, student, label, footer)
    overlay.addEventListener('click', (event) => {
      if (event.target === overlay) closeRejectModal()
    })
    overlay.append(panel)
    document.body.append(overlay)
    rejectModal = overlay
    note.focus()
  }

  async function performKaprodiAction(record, action, body = {}) {
    actingKrsIds.add(record.id)
    scheduleRefresh()
    try {
      const response = await post(`/krs/${record.id}/${action}-kaprodi`, body)
      closeRejectModal()
      showNotice(response.message ?? (action === 'approve' ? 'KRS disetujui pada tahap prodi.' : 'KRS ditolak pada tahap prodi.'))
      fetchKey = ''
    } catch (error) {
      const message = error instanceof Error ? error.message : 'Tindakan KRS gagal.'
      showNotice(message, true)
      if (rejectModal) {
        const buttons = rejectModal.querySelectorAll('button')
        buttons.forEach((button) => { button.disabled = false })
        const note = rejectModal.querySelector('textarea')
        const submit = rejectModal.querySelector('button:last-child')
        if (submit && note) submit.disabled = !note.value.trim()
      }
    } finally {
      actingKrsIds.delete(record.id)
      fetchKey = ''
      failedFetchKey = ''
      await refresh()
    }
  }

  function patchDetail() {
    const record = records[0]
    if (!record) return
    for (const element of document.querySelectorAll('#app main span')) {
      const text = element.textContent.trim()
      if ([record.status, record.display_status, record.display_status_label, labelForRecord(record)].filter(Boolean).includes(text)) {
        applyStatus(element, record)
      }
    }
    document.querySelectorAll('[data-krs-kaprodi-actions]').forEach((element) => element.remove())
  }

  function patchDashboard() {
    if (!dashboardStatus) return
    const walker = document.createTreeWalker(document.querySelector('#app main') ?? document.body, NodeFilter.SHOW_TEXT)
    while (walker.nextNode()) {
      const node = walker.currentNode
      if (node.textContent.trim() === dashboardStatus) {
        node.textContent = node.textContent.replace(dashboardStatus, labelFor(dashboardStatus))
      }
    }
  }

  function translateStaticText() {
    const translations = new Map([
      ['Review', 'Tinjau'],
      ['Submit KRS', 'Ajukan KRS'],
      ['Submitting...', 'Mengajukan...'],
      ['Submit KRS ke dosen wali?', 'Ajukan KRS ke dosen wali?'],
      ['Submit KRS ke Dosen Wali', 'Ajukan KRS ke Dosen Wali'],
      ['Auto (belum ditentukan)', 'Otomatis (belum ditentukan)'],
      ['Gagal download PDF.', 'Gagal mengunduh PDF.'],
    ])
    const walker = document.createTreeWalker(document.querySelector('#app main') ?? document.body, NodeFilter.SHOW_TEXT)
    while (walker.nextNode()) {
      const node = walker.currentNode
      const text = node.textContent.trim()
      if (translations.has(text)) {
        node.textContent = node.textContent.replace(text, translations.get(text))
      } else if (text === 'PDF' && node.parentElement?.tagName === 'BUTTON') {
        node.textContent = node.textContent.replace(text, 'Unduh PDF')
      }
    }
  }

  async function refresh() {
    const route = location.pathname
    if (route !== currentRoute) {
      currentRoute = route
      fetchKey = ''
      failedFetchKey = ''
      viewerFailed = false
      records = []
    }
    try {
      if (route === '/akademik/krs' || route === '/akademik/krs/') {
        await fetchListRecords()
        patchList()
      } else if (/^\/akademik\/krs\/\d+\/?$/.test(route)) {
        await fetchDetailRecord()
        patchDetail()
      } else if (route.startsWith('/akademik/krs-saya')) {
        await fetchStudentRecord()
        for (const element of document.querySelectorAll('#app main span')) {
          const record = records[0]
          if (record && [record.status, record.display_status, record.display_status_label, labelForRecord(record)].includes(element.textContent.trim())) applyStatus(element, record)
        }
      } else if (route === '/dashboard' || route === '/dashboard/') {
        await fetchDashboardStatus()
        patchDashboard()
      }
    } catch (error) {
      console.error('[ASC] Gagal memperbarui tampilan status KRS.', error)
    }
    translateStaticText()
  }

  function scheduleRefresh() {
    clearTimeout(refreshTimer)
    refreshTimer = setTimeout(() => { void refresh() }, 250)
  }

  const observer = new MutationObserver(scheduleRefresh)
  observer.observe(document.documentElement, { childList: true, subtree: true })
  document.addEventListener('change', (event) => {
    if (event.target instanceof HTMLSelectElement || event.target instanceof HTMLInputElement) {
      fetchKey = ''
      failedFetchKey = ''
      viewerFailed = false
      scheduleRefresh()
    }
  })
  document.addEventListener('click', (event) => {
    const pageButton = event.target instanceof Element
      ? event.target.closest('#app main nav[aria-label="Navigasi halaman tabel"] button:not(:disabled)')
      : null
    if (pageButton) {
      failedFetchKey = ''
      viewerFailed = false
    }
  })
  window.addEventListener('popstate', scheduleRefresh)
  document.addEventListener('click', (event) => {
    const button = event.target instanceof Element ? event.target.closest('button[data-krs-action]') : null
    if (!button || button.disabled) return
    const record = records.find((item) => String(item.id) === button.dataset.krsId)
    if (!record) return
    if (button.dataset.krsAction === 'approve') {
      if (window.confirm(`Setujui KRS ${record.student?.name ?? ''} pada tahap program studi?`)) {
        void performKaprodiAction(record, 'approve')
      }
    } else if (button.dataset.krsAction === 'reject') {
      openRejectModal(record)
    }
  })
  scheduleRefresh()
})()
