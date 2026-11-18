(() => {
  const API_BASE = 'https://api-asc.aljawami.ac.id/api'
  let payments = []
  let loading = false
  let loaded = false
  let scheduled = false
  let observer

  function isStudentFinancePage() {
    return location.pathname.replace(/\/+$/, '') === '/keuangan/saya'
  }

  async function loadPayments() {
    if (loading || loaded || !isStudentFinancePage()) return
    const token = localStorage.getItem('auth_token')
    if (!token) return

    loading = true
    try {
      const response = await fetch(`${API_BASE}/finance/my-payments`, {
        headers: { Accept: 'application/json', Authorization: `Bearer ${token}` },
      })
      if (!response.ok) throw new Error(`Gagal memuat catatan pembayaran (HTTP ${response.status}).`)
      const payload = await response.json()
      payments = payload.data ?? payload
      loaded = true
      renderNotes()
    } catch (error) {
      console.error('Gagal memuat catatan penolakan pembayaran.', error)
    } finally {
      loading = false
    }
  }

  function renderNotes() {
    if (!isStudentFinancePage()) return

    for (const payment of payments) {
      if (payment.status !== 'REJECTED' || !payment.rejection_reason) continue
      const number = Array.from(document.querySelectorAll('#app main p'))
        .find((element) => element.textContent.trim() === payment.payment_number)
      const details = number?.parentElement
      const card = number?.closest('.bg-white.rounded-xl.border')
      if (!details || !card || details.querySelector('[data-payment-rejection-note]')) continue

      const note = document.createElement('div')
      note.dataset.paymentRejectionNote = 'true'
      note.style.cssText = 'margin-top:8px;padding:8px 10px;border:1px solid #fecaca;border-radius:8px;background:#fef2f2;color:#991b1b;white-space:pre-wrap'
      const heading = document.createElement('strong')
      heading.textContent = 'Catatan penolakan'
      heading.style.cssText = 'display:block;font-size:12px'
      const text = document.createElement('p')
      text.textContent = payment.rejection_reason
      text.style.cssText = 'margin:3px 0 0;font-size:12px'
      note.append(heading, text)
      details.append(note)
    }
  }

  function scheduleRender() {
    if (scheduled) return
    scheduled = true
    requestAnimationFrame(() => {
      scheduled = false
      void loadPayments()
      renderNotes()
    })
  }

  function syncRoute() {
    if (!isStudentFinancePage()) {
      payments = []
      loaded = false
      return
    }
    void loadPayments()
    renderNotes()
  }

  observer = new MutationObserver(scheduleRender)
  observer.observe(document.documentElement, { childList: true, subtree: true })
  window.addEventListener('popstate', syncRoute)
  const originalPushState = history.pushState.bind(history)
  history.pushState = function (...args) {
    originalPushState(...args)
    syncRoute()
  }
  const originalReplaceState = history.replaceState.bind(history)
  history.replaceState = function (...args) {
    originalReplaceState(...args)
    syncRoute()
  }
  syncRoute()
})()
