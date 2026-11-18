(() => {
  const API_BASE = 'https://api-asc.aljawami.ac.id/api'
  const BUTTON_ATTRIBUTE = 'data-class-offering-pdf-export'
  let scheduled = false

  function currentPage() {
    if (location.pathname.replace(/\/+$/, '') !== '/akademik/kelas') return null
    const heading = Array.from(document.querySelectorAll('#app h1'))
      .find((element) => element.textContent.trim() === 'Pembagian Kelas & Jadwal')
    if (!heading) return null

    const header = heading.closest('.flex.items-center.justify-between')
    const excelButton = header && Array.from(header.querySelectorAll('button'))
      .find((button) => button.textContent.trim() === 'Export')
    if (!header || !excelButton?.parentElement) return null

    return {
      header,
      controls: excelButton.parentElement,
      root: heading.closest('.space-y-5') ?? document.querySelector('#app'),
    }
  }

  function filters(root) {
    const selects = root.querySelectorAll('select')
    const search = root.querySelector('input[placeholder="Cari kelas / MK..."]')
    return {
      semester_id: selects[0]?.value ?? '',
      study_program_id: selects[1]?.value ?? '',
      search: search?.value ?? '',
    }
  }

  async function download(button, root) {
    const token = localStorage.getItem('auth_token')
    if (!token) {
      window.alert('Sesi login tidak ditemukan. Silakan login kembali.')
      return
    }

    const originalText = button.textContent
    button.disabled = true
    button.textContent = 'Menyiapkan...'
    try {
      const query = new URLSearchParams(
        Object.entries(filters(root)).filter(([, value]) => value !== ''),
      )
      const response = await fetch(`${API_BASE}/classes/export-pdf?${query}`, {
        headers: { Accept: 'application/pdf', Authorization: `Bearer ${token}` },
      })
      if (!response.ok) {
        let message = `Gagal mengekspor PDF (HTTP ${response.status}).`
        try {
          const payload = await response.json()
          message = payload.message ?? message
        } catch {
          // Keep the HTTP error message when the server does not return JSON.
        }
        throw new Error(message)
      }

      const url = URL.createObjectURL(await response.blob())
      const link = document.createElement('a')
      link.href = url
      link.download = 'pembagian-kelas-jadwal.pdf'
      document.body.append(link)
      link.click()
      link.remove()
      window.setTimeout(() => URL.revokeObjectURL(url), 1000)
    } catch (error) {
      console.error('Gagal mengekspor PDF pembagian kelas dan jadwal.', error)
      window.alert(error instanceof Error ? error.message : 'Gagal mengekspor PDF pembagian kelas.')
    } finally {
      button.disabled = false
      button.textContent = originalText
    }
  }

  function render() {
    const page = currentPage()
    if (!page || page.controls.querySelector(`[${BUTTON_ATTRIBUTE}]`)) return

    const button = document.createElement('button')
    button.type = 'button'
    button.setAttribute(BUTTON_ATTRIBUTE, '')
    button.className = 'flex items-center gap-1.5 rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-sm font-medium text-red-700 hover:bg-red-100 disabled:opacity-50'
    button.textContent = 'Export PDF'
    button.addEventListener('click', () => void download(button, page.root))
    page.controls.append(button)
  }

  function scheduleRender() {
    if (scheduled) return
    scheduled = true
    requestAnimationFrame(() => {
      scheduled = false
      render()
    })
  }

  const observer = new MutationObserver(scheduleRender)
  observer.observe(document.documentElement, { childList: true, subtree: true })
  window.addEventListener('popstate', scheduleRender)
  const originalPushState = history.pushState.bind(history)
  history.pushState = function (...args) {
    originalPushState(...args)
    scheduleRender()
  }
  const originalReplaceState = history.replaceState.bind(history)
  history.replaceState = function (...args) {
    originalReplaceState(...args)
    scheduleRender()
  }
  scheduleRender()
})()
