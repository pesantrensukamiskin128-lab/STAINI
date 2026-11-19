(() => {
  const apiBase = '/api'
  const toolbarId = 'asc-curriculum-obe-tools'
  const allowedAccess = [
    'SUPER_ADMIN',
    'ADMIN_AKADEMIK',
    'WK1',
    'WR1',
    'KETUA',
    'REKTOR',
    'DEKAN',
    'WADEK1',
    'KAPRODI',
    'SEKPRODI',
  ]
  let checkedToken = null
  let canManageCurriculum = false
  let activeCurriculumId = null

  function currentCurriculumId() {
    return window.location.pathname.match(/\/kurikulum\/(\d+)(?:\/|$)/)?.[1] ?? null
  }

  function removeToolbar() {
    document.getElementById(toolbarId)?.remove()
  }

  function setStatus(message, isError = false) {
    const status = document.getElementById(`${toolbarId}-status`)
    if (!status) return
    status.textContent = message
    status.style.color = isError ? '#b91c1c' : '#475569'
  }

  async function download(url, filename) {
    setStatus('Menyiapkan file...')
    try {
      const response = await fetch(`${apiBase}${url}`, {
        headers: { Authorization: `Bearer ${localStorage.getItem('auth_token')}` },
      })
      if (!response.ok) throw await responseError(response)
      const blob = await response.blob()
      const objectUrl = URL.createObjectURL(blob)
      const link = document.createElement('a')
      link.href = objectUrl
      link.download = filename
      document.body.appendChild(link)
      link.click()
      link.remove()
      window.setTimeout(() => URL.revokeObjectURL(objectUrl), 1000)
      setStatus('File berhasil diunduh.')
    } catch (error) {
      setStatus(error.message || 'File gagal diunduh.', true)
    }
  }

  async function responseError(response) {
    if (response.status === 401) {
      window.location.assign('/login')
      return new Error('Sesi berakhir. Silakan masuk kembali.')
    }

    try {
      const payload = await response.json()
      const messages = Object.values(payload.errors ?? {}).flat().join(', ')
      return new Error(messages || payload.message || `Permintaan gagal (${response.status}).`)
    } catch {
      return new Error(`Permintaan gagal (${response.status}).`)
    }
  }

  async function importFile(file, curriculumId) {
    if (!window.confirm('Import akan menambah atau memperbarui data yang tercantum; data lain tidak dihapus. Lanjutkan?')) return

    const form = new FormData()
    form.append('file', file)
    setStatus('Memvalidasi dan mengimpor workbook...')

    try {
      const response = await fetch(`${apiBase}/curriculums/${curriculumId}/import-excel`, {
        method: 'POST',
        headers: { Authorization: `Bearer ${localStorage.getItem('auth_token')}` },
        body: form,
      })
      if (!response.ok) throw await responseError(response)
      const payload = await response.json()
      setStatus(`${payload.message || 'Import berhasil.'} Muat ulang halaman untuk melihat perubahan.`)
    } catch (error) {
      setStatus(error.message || 'Workbook gagal diimpor.', true)
    }
  }

  function button(label, color, onClick) {
    const element = document.createElement('button')
    element.type = 'button'
    element.textContent = label
    element.style.cssText = `border:0;border-radius:6px;background:${color};color:#fff;padding:7px 10px;font:500 12px system-ui;cursor:pointer`
    element.addEventListener('click', onClick)
    return element
  }

  function mountToolbar(curriculumId) {
    if (!canManageCurriculum || activeCurriculumId === curriculumId && document.getElementById(toolbarId)) return
    removeToolbar()
    activeCurriculumId = curriculumId

    const panel = document.createElement('section')
    panel.id = toolbarId
    panel.setAttribute('aria-label', 'Template, import, dan export kurikulum')
    panel.style.cssText = 'width:100%;box-sizing:border-box;margin:0 0 16px;padding:16px;background:#fff;border:1px solid #e5e7eb;border-radius:12px;font:13px system-ui;color:#111827'

    const heading = document.createElement('strong')
    heading.textContent = 'Data Kurikulum (Excel)'
    const description = document.createElement('p')
    description.textContent = 'Unduh template, ekspor data kurikulum, atau impor perubahan dari file Excel.'
    description.style.cssText = 'margin:4px 0 0;color:#6b7280;font-size:12px'
    const controls = document.createElement('div')
    controls.style.cssText = 'display:flex;flex-wrap:wrap;gap:8px;margin-top:12px'
    controls.append(
      button('Template', '#475569', () => void download('/curriculums/template-excel', 'template-detail-kurikulum.xlsx')),
      button('Export', '#2563eb', () => void download(`/curriculums/${curriculumId}/export-excel`, `kurikulum-${curriculumId}.xlsx`)),
    )

    const input = document.createElement('input')
    input.type = 'file'
    input.accept = '.xlsx,.xls'
    input.hidden = true
    input.addEventListener('change', () => {
      const file = input.files?.[0]
      if (file) void importFile(file, curriculumId)
      input.value = ''
    })
    controls.append(button('Import Excel', '#059669', () => input.click()))

    const status = document.createElement('p')
    status.id = `${toolbarId}-status`
    status.setAttribute('role', 'status')
    status.style.cssText = 'margin:8px 0 0;font-size:11px;line-height:1.45;color:#64748b'
    status.textContent = 'Workbook berisi sheet Profil Lulusan, CPL, Mata Kuliah, dan Pemetaan CPL–MK.'
    panel.append(heading, description, controls, input, status)
    document.querySelector('#app main')?.prepend(panel)
  }

  async function checkAccess(token) {
    if (!token) {
      checkedToken = null
      canManageCurriculum = false
      removeToolbar()
      return
    }
    if (checkedToken === token) return

    checkedToken = token
    canManageCurriculum = false
    removeToolbar()
    try {
      const response = await fetch(`${apiBase}/auth/me`, {
        headers: { Accept: 'application/json', Authorization: `Bearer ${token}` },
      })
      if (!response.ok) {
        checkedToken = null
        return
      }
      const user = await response.json()
      const identifiers = [...(user.roles ?? []), ...(user.position_codes ?? [])]
      canManageCurriculum = identifiers.some(identifier => allowedAccess.includes(identifier))
    } catch (error) {
      checkedToken = null
      console.error('Gagal memeriksa akses alat kurikulum.', error)
    }
    syncToolbar()
  }

  function syncToolbar() {
    const curriculumId = currentCurriculumId()
    if (!curriculumId || !canManageCurriculum) {
      removeToolbar()
      activeCurriculumId = null
      return
    }
    mountToolbar(curriculumId)
  }

  new MutationObserver(() => {
    const token = localStorage.getItem('auth_token')
    if (token !== checkedToken) void checkAccess(token)
    else syncToolbar()
  }).observe(document.body, { childList: true, subtree: true })

  window.addEventListener('focus', () => void checkAccess(localStorage.getItem('auth_token')))
  window.addEventListener('popstate', syncToolbar)
  void checkAccess(localStorage.getItem('auth_token'))
})()
