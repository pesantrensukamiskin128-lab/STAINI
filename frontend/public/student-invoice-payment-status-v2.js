(() => {
  const API_BASE = 'https://api-asc.aljawami.ac.id/api'
  const invoiceStatuses = new Map()
  const paymentStatuses = new Map()
  let loading = false
  let loaded = false
  let scheduled = false
  let successToastVisible = false

  function isStudentFinancePage() {
    return location.pathname.replace(/\/+$/, '') === '/keuangan/saya'
  }

  function formatCurrency(amount) {
    return new Intl.NumberFormat('id-ID', {
      style: 'currency',
      currency: 'IDR',
      minimumFractionDigits: 0,
    }).format(Number(amount) || 0)
  }

  function statusLabel(status) {
    return ({
      UNPAID: 'Belum Bayar',
      PARTIAL: 'Pembayaran Sebagian',
      OVERDUE: 'Terlambat',
      PAID: 'Lunas',
      CANCELLED: 'Dibatalkan',
      WAIVED: 'Dibebaskan',
    })[status] ?? status
  }

  async function loadData() {
    if (loading || loaded || !isStudentFinancePage()) return
    const token = localStorage.getItem('auth_token')
    if (!token) return

    loading = true
    try {
      const headers = { Accept: 'application/json', Authorization: `Bearer ${token}` }
      const [invoiceResponse, paymentResponse] = await Promise.all([
        fetch(`${API_BASE}/finance/my-invoices?per_page=100`, { headers }),
        fetch(`${API_BASE}/finance/my-payments?per_page=100`, { headers }),
      ])
      if (!invoiceResponse.ok || !paymentResponse.ok) {
        const failedResponse = !invoiceResponse.ok ? invoiceResponse : paymentResponse
        throw new Error(`Gagal memuat rincian invoice (HTTP ${failedResponse.status}).`)
      }

      const invoicePayload = await invoiceResponse.json()
      const paymentPayload = await paymentResponse.json()
      invoiceStatuses.clear()
      paymentStatuses.clear()
      for (const invoice of invoicePayload.data ?? invoicePayload) {
        invoiceStatuses.set(String(invoice.invoice_number), invoice)
      }
      for (const payment of paymentPayload.data ?? paymentPayload) {
        if (['PENDING', 'REJECTED'].includes(payment.status)) {
          const entries = paymentStatuses.get(Number(payment.invoice_id)) ?? []
          entries.push(payment)
          paymentStatuses.set(Number(payment.invoice_id), entries)
        }
      }
      for (const entries of paymentStatuses.values()) entries.sort((a, b) => b.id - a.id)
      loaded = true
      renderInvoices()
    } catch (error) {
      console.error('Gagal memuat status pembayaran pada invoice.', error)
    } finally {
      loading = false
    }
  }

  function createFeeSection(invoice) {
    if (!invoice.items?.length) return null
    const section = document.createElement('section')
    section.dataset.invoiceFeeTypes = 'true'
    section.style.cssText = 'margin-top:12px;padding:10px 12px;border:1px solid #e5e7eb;border-radius:10px;background:#f9fafb'
    const title = document.createElement('strong')
    title.textContent = 'Jenis tagihan'
    title.style.cssText = 'display:block;margin-bottom:5px;color:#374151;font-size:12px'
    section.append(title)

    for (const item of invoice.items) {
      const row = document.createElement('div')
      row.style.cssText = 'display:flex;justify-content:space-between;gap:12px;padding:3px 0;color:#4b5563;font-size:12px'
      const name = document.createElement('span')
      name.textContent = item.fee_type?.name ?? item.description ?? 'Tagihan'
      const amount = document.createElement('span')
      amount.textContent = formatCurrency(item.amount)
      amount.style.cssText = 'flex-shrink:0;font-weight:600;color:#1f2937'
      row.append(name, amount)
      section.append(row)
    }
    return section
  }

  function createPaymentSection(invoice) {
    const payments = paymentStatuses.get(Number(invoice.id)) ?? []
    if (!payments.length) return null
    const section = document.createElement('section')
    section.dataset.invoicePaymentStatuses = 'true'
    section.style.cssText = 'display:grid;gap:8px;margin-top:12px'

    for (const payment of payments) {
      const rejected = payment.status === 'REJECTED'
      const panel = document.createElement('div')
      panel.style.cssText = `padding:10px 12px;border:1px solid ${rejected ? '#fecaca' : '#fde68a'};border-radius:10px;background:${rejected ? '#fef2f2' : '#fffbeb'}`
      const heading = document.createElement('strong')
      heading.textContent = rejected ? 'Pembayaran Ditolak' : 'Menunggu Verifikasi'
      heading.style.cssText = `display:block;color:${rejected ? '#991b1b' : '#92400e'};font-size:12px`
      const detail = document.createElement('span')
      detail.textContent = `${payment.payment_number} · ${formatCurrency(payment.amount)}`
      detail.style.cssText = 'display:block;margin-top:3px;color:#4b5563;font-size:11px'
      panel.append(heading, detail)
      if (rejected && payment.rejection_reason) {
        const reason = document.createElement('p')
        reason.textContent = `Catatan penolakan: ${payment.rejection_reason}`
        reason.style.cssText = 'margin:5px 0 0;color:#b91c1c;font-size:12px;white-space:pre-wrap'
        panel.append(reason)
      }
      section.append(panel)
    }
    return section
  }

  function createPartialPaymentSummary(invoice) {
    if (invoice.status !== 'PARTIAL') return null
    const section = document.createElement('section')
    section.dataset.invoicePartialSummary = 'true'
    section.style.cssText = 'display:flex;flex-wrap:wrap;gap:16px;margin-top:10px;font-size:12px'
    const paid = Number(invoice.paid_amount) || 0
    const remaining = Math.max(0,
      (Number(invoice.total_amount) || 0) -
      (Number(invoice.discount_amount) || 0) -
      (Number(invoice.scholarship_amount) || 0) -
      paid,
    )
    for (const [label, amount, color] of [
      ['Sudah Dibayar', paid, '#15803d'],
      ['Sisa Pembayaran', remaining, '#b91c1c'],
    ]) {
      const item = document.createElement('div')
      const title = document.createElement('span')
      title.textContent = label
      title.style.cssText = 'display:block;color:#6b7280'
      const value = document.createElement('strong')
      value.textContent = formatCurrency(amount)
      value.style.cssText = `display:block;color:${color}`
      item.append(title, value)
      section.append(item)
    }
    return section
  }

  function renderInvoices() {
    if (!isStudentFinancePage() || !loaded) return
    for (const [invoiceNumber, invoice] of invoiceStatuses) {
      const heading = Array.from(document.querySelectorAll('#app main p'))
        .find((element) => element.textContent.trim() === invoiceNumber)
      const card = heading?.closest('.bg-white.rounded-xl.border')
      if (!card) continue

      const status = Array.from(card.querySelectorAll('span'))
        .find((element) => ['UNPAID', 'PARTIAL'].includes(element.textContent.trim()))
      if (status) status.textContent = statusLabel(invoice.status)

      const payments = paymentStatuses.get(Number(invoice.id)) ?? []
      const signature = JSON.stringify({
        status: invoice.status,
        total: invoice.total_amount,
        discount: invoice.discount_amount,
        scholarship: invoice.scholarship_amount,
        paid: invoice.paid_amount,
        items: invoice.items ?? [],
        payments,
      })
      const existingDetails = card.querySelector('[data-invoice-details]')
      if (existingDetails?.dataset.signature === signature) continue
      existingDetails?.remove()

      const details = document.createElement('div')
      details.dataset.invoiceDetails = 'true'
      details.dataset.signature = signature
      const feeSection = createFeeSection(invoice)
      const paymentSection = createPaymentSection(invoice)
      const partialSummary = createPartialPaymentSummary(invoice)
      if (feeSection) details.append(feeSection)
      if (paymentSection) details.append(paymentSection)
      if (partialSummary) details.append(partialSummary)
      const totals = card.querySelector('.grid.grid-cols-3')
      if (details.childElementCount && totals) card.insertBefore(details, totals)
    }
  }

  function scheduleRender() {
    if (scheduled) return
    scheduled = true
    requestAnimationFrame(() => {
      scheduled = false
      const paymentSucceeded = document.body.textContent.includes('Pembayaran berhasil dikirim! Menunggu verifikasi admin.')
      if (paymentSucceeded && !successToastVisible) {
        successToastVisible = true
        loaded = false
      } else if (!paymentSucceeded) {
        successToastVisible = false
      }
      void loadData()
      renderInvoices()
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
