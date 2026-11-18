const API_BASE = 'https://api-asc.aljawami.ac.id/api'
const TOOL_ID = 'student-advisor-tools'

function formatErrors(errors) {
  if (Array.isArray(errors)) return errors.map(String)
  if (errors && typeof errors === 'object') {
    return Object.entries(errors).flatMap(([field, messages]) =>
      (Array.isArray(messages) ? messages : [messages]).map((message) => `${field}: ${message}`)
    )
  }
  return errors ? [String(errors)] : []
}

function showMessage(container, message, errors = [], type = 'info') {
  const status = container.querySelector('[data-status]')
  status.textContent = message
  status.setAttribute('role', type === 'error' ? 'alert' : 'status')
  status.style.cssText = `margin:8px 0 0;padding:8px 10px;border-radius:8px;font-size:12px;font-weight:600;white-space:pre-wrap;${
    type === 'success'
      ? 'background:#dcfce7;color:#166534'
      : type === 'warning'
        ? 'background:#fef3c7;color:#92400e'
        : type === 'error'
          ? 'background:#fee2e2;color:#991b1b'
          : 'background:#dbeafe;color:#1e40af'
  }`

  const details = container.querySelector('[data-errors]')
  const formattedErrors = formatErrors(errors)
  details.textContent = formattedErrors.join('\n')
  details.hidden = formattedErrors.length === 0
}

async function request(path, options = {}) {
  const token = localStorage.getItem('auth_token')
  if (!token) throw new Error('Sesi login tidak ditemukan. Silakan login kembali.')

  const response = await fetch(`${API_BASE}${path}`, {
    ...options,
    headers: {
      Accept: 'application/json',
      Authorization: `Bearer ${token}`,
      ...options.headers,
    },
  })

  if (!response.ok) {
    const payload = await response.json().catch(() => ({}))
    const error = new Error(payload.message ?? `Permintaan gagal (HTTP ${response.status}).`)
    error.details = payload.errors
    throw error
  }

  return response
}

function createTools() {
  const tools = document.createElement('section')
  tools.id = TOOL_ID
  tools.style.cssText = 'padding:12px 16px;border:1px solid #bfdbfe;border-radius:12px;background:#eff6ff'

  const title = document.createElement('strong')
  title.textContent = 'Dosen Pembimbing Akademik'
  title.style.cssText = 'display:block;margin-bottom:8px;color:#1e3a8a;font-size:14px'

  const actions = document.createElement('div')
  actions.style.cssText = 'display:flex;flex-wrap:wrap;gap:8px'
  for (const [action, label, color] of [
    ['template', 'Template PA', '#475569'],
    ['export', 'Export PA', '#15803d'],
    ['import', 'Import PA', '#1d4ed8'],
  ]) {
    const button = document.createElement('button')
    button.type = 'button'
    button.dataset.action = action
    button.textContent = label
    button.style.cssText = `padding:8px 12px;border:1px solid ${color};border-radius:8px;background:white;color:${color};font-size:13px;font-weight:600;cursor:pointer`
    actions.append(button)
  }

  const file = document.createElement('input')
  file.type = 'file'
  file.accept = '.xlsx,.xls,.csv'
  file.hidden = true

  const hint = document.createElement('p')
  hint.textContent = 'Impor berdasarkan NIM dan NIDN dosen; NIDN kosong akan menghapus penugasan PA.'
  hint.style.cssText = 'margin:8px 0 0;color:#475569;font-size:12px'

  const status = document.createElement('p')
  status.dataset.status = ''
  status.setAttribute('aria-live', 'polite')
  status.style.cssText = 'margin:6px 0 0;color:#334155;font-size:12px'

  const errors = document.createElement('pre')
  errors.dataset.errors = ''
  errors.hidden = true
  errors.style.cssText = 'max-height:120px;overflow:auto;white-space:pre-wrap;color:#92400e;font-size:11px'

  tools.append(title, actions, file, hint, status, errors)

  actions.addEventListener('click', async (event) => {
    const button = event.target.closest('button[data-action]')
    if (!button) return

    const action = button.dataset.action
    if (action === 'import') {
      file.click()
      return
    }

    button.disabled = true
    try {
      const path = action === 'template'
        ? '/templates/student-advisors'
        : '/students/advisors/export'
      const response = await request(path)
      const blob = await response.blob()
      const disposition = response.headers.get('content-disposition') ?? ''
      const filename = disposition.match(/filename="?([^";\n]+)"?/)?.[1]
        ?? (action === 'template' ? 'template-dosen-pa-mahasiswa.xlsx' : 'dosen-pa-mahasiswa.xlsx')
      const link = document.createElement('a')
      link.href = URL.createObjectURL(blob)
      link.download = filename
      link.click()
      URL.revokeObjectURL(link.href)
      showMessage(tools, 'File berhasil diunduh.', [], 'success')
    } catch (error) {
      showMessage(tools, error.message ?? 'Permintaan gagal.', error.details, 'error')
    } finally {
      button.disabled = false
    }
  })

  file.addEventListener('change', async () => {
    const selected = file.files?.[0]
    file.value = ''
    if (!selected) return

    const button = actions.querySelector('[data-action="import"]')
    button.disabled = true
    const form = new FormData()
    form.append('file', selected)
    try {
      const response = await request('/students/advisors/import', { method: 'POST', body: form })
      const result = await response.json()
      const errors = formatErrors(result.errors)
      const updated = Number(result.updated ?? 0)
      const message = result.message
        ?? (errors.length
          ? `Import mengalami kendala. ${updated} penugasan diperbarui; ${errors.length} baris perlu diperiksa.`
          : `Import berhasil. ${updated} penugasan diperbarui.`)
      const type = errors.length ? (updated > 0 ? 'warning' : 'error') : 'success'
      showMessage(tools, message, errors, type)
    } catch (error) {
      showMessage(tools, error.message ?? 'Import dosen PA gagal.', error.details, 'error')
    } finally {
      button.disabled = false
    }
  })

  return tools
}

async function canManageAdvisors() {
  const token = localStorage.getItem('auth_token')
  if (!token) return false

  const response = await fetch(`${API_BASE}/auth/me`, {
    headers: { Accept: 'application/json', Authorization: `Bearer ${token}` },
  })
  if (!response.ok) return false

  const user = await response.json()
  const roles = user.roles ?? user.user?.roles ?? []
  const positions = user.position_codes ?? user.user?.position_codes ?? []
  return roles.some((role) => ['SUPER_ADMIN', 'ADMIN_AKADEMIK', 'ADMIN_UMUM'].includes(role))
    || positions.includes('KAPRODI')
}

let checkingAccess = false

async function mountTools() {
  if (location.pathname !== '/sdm/students') {
    document.getElementById(TOOL_ID)?.remove()
    return
  }
  if (document.getElementById(TOOL_ID) || checkingAccess) return
  const heading = [...document.querySelectorAll('h1')].find((item) => item.textContent.trim() === 'Data Mahasiswa')
  if (!heading) return

  checkingAccess = true
  try {
    if (!await canManageAdvisors()) return
    const header = heading.closest('.flex.items-center.justify-between')
    if (!header) return
    header.insertAdjacentElement('afterend', createTools())
  } catch (error) {
    console.error('Gagal memuat fitur Dosen PA:', error)
  } finally {
    checkingAccess = false
  }
}

new MutationObserver(() => { void mountTools() }).observe(document.body, { childList: true, subtree: true })
void mountTools()
