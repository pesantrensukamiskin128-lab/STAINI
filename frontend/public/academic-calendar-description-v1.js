(() => {
  const apiBase = '/api'
  const rowSelectors = [
    '.flex.items-center.gap-4.p-4.bg-white.rounded-xl',
    '.flex.items-center.gap-4.px-5.py-3',
    '.flex.items-center.gap-3.p-2\\.5.rounded-lg.bg-gray-50',
  ]
  let events = []
  let token = null
  let loading = false

  function currentPath() {
    return window.location.pathname.replace(/\/+$/, '')
  }

  function formatDate(value) {
    if (!value) return ''
    return new Date(value).toLocaleDateString('id-ID', { day: 'numeric', month: 'short', year: 'numeric' })
  }

  function findEvent(row, title) {
    const rowText = row.innerText ?? ''
    return events.find(event => {
      if (event.title !== title) return false
      const startDate = formatDate(event.start_date)
      const rawStartDate = String(event.start_date ?? '').slice(0, 10)
      return startDate !== '' && (rowText.includes(startDate) || rowText.includes(rawStartDate))
    })
  }

  function renderDashboardDates(row, event) {
    if (currentPath() !== '/dashboard') return

    const startDate = String(event.start_date ?? '').slice(0, 10)
    const endDate = String(event.end_date ?? '').slice(0, 10)
    const dateElement = [...row.querySelectorAll('p')]
      .find(element => element.classList.contains('text-gray-500') && element.textContent?.includes(startDate))

    if (!dateElement) return

    dateElement.textContent = `${formatDate(event.start_date)}${endDate && endDate !== startDate ? ` – ${formatDate(event.end_date)}` : ''}`
  }

  function renderDescriptions() {
    if (!['/akademik/kalender', '/master-data/academic-calendar', '/dashboard'].includes(currentPath())) return

    for (const selector of rowSelectors) {
      document.querySelectorAll(`#app ${selector}`).forEach(row => {
        const titleElement = [...row.querySelectorAll('p')]
          .find(element => element.classList.contains('font-medium')
            && (element.classList.contains('text-gray-900') || element.classList.contains('text-gray-800')))
        if (!titleElement || row.querySelector('[data-academic-calendar-description]')) return

        const event = findEvent(row, titleElement.textContent?.trim() ?? '')
        if (!event) return
        renderDashboardDates(row, event)

        const description = document.createElement('p')
        description.dataset.academicCalendarDescription = 'true'
        description.className = 'mt-1 text-xs text-gray-500 whitespace-pre-line'
        const label = document.createElement('span')
        label.className = 'font-medium text-gray-600'
        label.textContent = 'Keterangan: '
        description.append(label, document.createTextNode(event.description || '-'))

        const content = titleElement.parentElement
        content?.appendChild(description)
      })
    }
  }

  async function loadEvents() {
    const currentToken = localStorage.getItem('auth_token')
    if (!currentToken || loading || currentToken === token && events.length) return
    loading = true
    token = currentToken

    try {
      const response = await fetch(`${apiBase}/academic-calendars`, {
        headers: { Accept: 'application/json', Authorization: `Bearer ${currentToken}` },
      })
      if (!response.ok) throw new Error(`Permintaan kalender gagal (${response.status}).`)
      events = await response.json()
      renderDescriptions()
    } catch (error) {
      console.error('Keterangan kalender akademik tidak dapat dimuat.', error)
      token = null
    } finally {
      loading = false
    }
  }

  new MutationObserver(() => {
    const currentToken = localStorage.getItem('auth_token')
    if (currentToken !== token) void loadEvents()
    else renderDescriptions()
  }).observe(document.body, { childList: true, subtree: true })

  window.addEventListener('popstate', renderDescriptions)
  void loadEvents()
})()
