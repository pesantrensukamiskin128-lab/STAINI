(() => {
  const API_BASE = 'https://api-asc.aljawami.ac.id/api'
  const BUTTON_ATTRIBUTE = 'data-asc-graduation-delete'
  let scheduled = false
  let activeSignature = ''
  let requestedSignature = ''
  let requestSequence = 0
  const deleting = new Set()

  function isAdmin() {
    const role = document.querySelector('aside > div:last-child p:last-child')?.textContent?.trim()
    return role === 'SUPER_ADMIN' || role === 'ADMIN_AKADEMIK'
  }

  function signatureFor(table) {
    const filters = [...document.querySelectorAll('#app select')]
    const period = filters.find((select) => [...select.options].some((option) => option.textContent.trim() === 'Semua Periode'))?.value ?? ''
    const status = filters.find((select) => [...select.options].some((option) => option.textContent.trim() === 'Semua Status'))?.value ?? ''
    const search = document.querySelector('#app input[placeholder="Cari NIM/nama..."]')?.value ?? ''
    const pageText = document.querySelector('#app')?.textContent ?? ''
    const page = pageText.match(/Halaman\s+(\d+)/)?.[1] ?? '1'
    const rows = [...table.querySelectorAll('tbody tr')].map((row) => row.textContent?.trim() ?? '').join('|')
    return { period, status, search, page, value: `${period}\n${status}\n${search}\n${page}\n${rows}` }
  }

  function addDeleteButton(row, registration) {
    const actionCell = row.cells[row.cells.length - 1]
    if (!actionCell
      || actionCell.querySelector(`[${BUTTON_ATTRIBUTE}]`)
      || actionCell.querySelector('button[aria-label^="Hapus pendaftaran wisuda"]')) return

    const button = document.createElement('button')
    button.type = 'button'
    button.setAttribute(BUTTON_ATTRIBUTE, '')
    button.className = 'p-1.5 rounded-lg text-red-600 hover:bg-red-50 disabled:opacity-50'
    button.title = 'Hapus pendaftaran wisuda'
    button.setAttribute('aria-label', `Hapus pendaftaran wisuda ${registration.student?.name ?? ''}`)
    button.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-4 h-4" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m14.74 9-.346 9m-5.788 0L8.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 0 1-2.247 2.077H8.084a2.25 2.25 0 0 1-2.247-2.077L4.772 5.79m14.456 0a48.108 48.108 0 0 0-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 0 1 3.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 0 0-3.32 0C9.91 2.677 9 3.66 9 4.84v.916m7.5 0a48.667 48.667 0 0 0-7.5 0" /></svg>'
    button.addEventListener('click', () => { void deleteRegistration(button, registration) })
    actionCell.append(button)
  }

  async function deleteRegistration(button, registration) {
    if (deleting.has(registration.id)) return
    const studentName = registration.student?.name ?? 'mahasiswa'
    const status = String(registration.status ?? '').replace(/_/g, ' ')
    if (!window.confirm(`Hapus pendaftaran wisuda ${studentName} dengan status ${status}? Pendaftaran, dokumen, dan verifikasinya akan dihapus. Status mahasiswa tidak diubah.`)) return

    deleting.add(registration.id)
    button.disabled = true
    try {
      const token = localStorage.getItem('auth_token')
      if (!token) throw new Error('Sesi login tidak ditemukan. Silakan login kembali.')
      const response = await fetch(`${API_BASE}/graduation/registrations/${registration.id}`, {
        method: 'DELETE',
        headers: { Accept: 'application/json', Authorization: `Bearer ${token}` },
      })
      const payload = await response.json().catch(() => ({}))
      if (!response.ok) throw new Error(payload.message ?? `Gagal menghapus pendaftaran (HTTP ${response.status}).`)
      window.alert(payload.message ?? 'Pendaftaran wisuda berhasil dihapus.')
      window.location.reload()
    } catch (error) {
      console.error('Gagal menghapus pendaftaran wisuda.', error)
      window.alert(error instanceof Error ? error.message : 'Gagal menghapus pendaftaran wisuda.')
      button.disabled = false
    } finally {
      deleting.delete(registration.id)
    }
  }

  async function patchRegistrationTable() {
    if (location.pathname !== '/wisuda' || !isAdmin()) return
    const table = document.querySelector('#app table')
    if (!table) return
    const signature = signatureFor(table)
    if (!signature.value || signature.value === activeSignature || signature.value === requestedSignature) return
    requestedSignature = signature.value
    const sequence = ++requestSequence

    try {
      const params = new URLSearchParams({ page: signature.page })
      if (signature.period) params.set('period_id', signature.period)
      if (signature.status) params.set('status', signature.status)
      if (signature.search) params.set('search', signature.search)
      const token = localStorage.getItem('auth_token')
      if (!token) throw new Error('Sesi login tidak ditemukan. Silakan login kembali.')
      const response = await fetch(`${API_BASE}/graduation/registrations?${params}`, {
        headers: { Accept: 'application/json', Authorization: `Bearer ${token}` },
      })
      const payload = await response.json().catch(() => ({}))
      if (!response.ok) throw new Error(payload.message ?? `Gagal memuat pendaftaran wisuda (HTTP ${response.status}).`)
      if (sequence !== requestSequence || signature.value !== signatureFor(table).value) return

      for (const row of table.querySelectorAll('tbody tr')) {
        const cells = row.querySelectorAll('td')
        const nim = cells[0]?.querySelector('p:last-child')?.textContent?.split('·')[0]?.trim()
        const periodName = cells[1]?.textContent?.trim()
        if (!nim || !periodName) continue
        const registration = payload.data?.find((item) =>
          String(item.student?.nim ?? '') === nim && item.period?.name === periodName,
        )
        if (registration) addDeleteButton(row, registration)
      }
      activeSignature = signature.value
    } catch (error) {
      requestedSignature = ''
      console.error('Gagal menyiapkan tombol hapus pendaftaran wisuda.', error)
    }
  }

  function schedulePatch() {
    if (scheduled) return
    scheduled = true
    requestAnimationFrame(() => {
      scheduled = false
      void patchRegistrationTable()
    })
  }

  const observer = new MutationObserver(schedulePatch)
  observer.observe(document.documentElement, { childList: true, subtree: true })
  document.addEventListener('change', schedulePatch)
  document.addEventListener('input', schedulePatch)
  window.addEventListener('popstate', () => {
    activeSignature = ''
    requestedSignature = ''
    schedulePatch()
  })
  schedulePatch()
})()
