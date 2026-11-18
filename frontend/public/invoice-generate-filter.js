(() => {
  const scriptId = 'asc-invoice-generate-filters'
  const originalOpen = XMLHttpRequest.prototype.open
  const originalSend = XMLHttpRequest.prototype.send

  function createField() {
    const wrapper = document.createElement('div')
    wrapper.style.cssText = 'margin-top:12px'

    const label = document.createElement('label')
    label.textContent = 'Filter Tahun Angkatan (opsional)'
    label.style.cssText = 'display:block;margin-bottom:4px;font-size:14px;font-weight:500;color:#374151'

    const input = document.createElement('input')
    input.type = 'number'
    input.min = '1900'
    input.max = '2100'
    input.inputMode = 'numeric'
    input.placeholder = 'Contoh: 2024'
    input.dataset.entryYearFilter = ''
    input.className = 'w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-blue-500'

    wrapper.append(label, input)
    return wrapper
  }

  function createButton(label, onClick) {
    const button = document.createElement('button')
    button.type = 'button'
    button.textContent = label
    button.style.cssText = 'padding:4px 8px;border:1px solid #bfdbfe;border-radius:6px;background:white;color:#1d4ed8;font-size:12px;font-weight:600;cursor:pointer'
    button.addEventListener('click', onClick)
    return button
  }

  function selectFeeTypes(container, recurringOnly) {
    const checkboxes = [...container.querySelectorAll('input[type="checkbox"]')]
    checkboxes.forEach((checkbox) => {
      const isRecurring = checkbox.closest('label')?.textContent.includes('Berulang') ?? false
      const shouldSelect = !recurringOnly || isRecurring
      if (checkbox.checked !== shouldSelect) {
        checkbox.click()
      }
    })
  }

  function mountFilters() {
    if (!/^\/keuangan\/tagihan\/generate\/?$/.test(location.pathname)) return

    const heading = [...document.querySelectorAll('h1')].find((item) =>
      item.textContent.trim() === 'Generate Tagihan Batch'
    )
    const card = heading?.closest('div.max-w-2xl')?.querySelector('.bg-white')
    if (!(card instanceof HTMLElement) || card.dataset.invoiceFiltersMounted) return

    const programLabel = [...card.querySelectorAll('label')].find((item) =>
      item.textContent.includes('Filter Program Studi')
    )
    const feeLabel = [...card.querySelectorAll('label')].find((item) =>
      item.textContent.includes('Jenis Tagihan')
    )
    if (!programLabel || !feeLabel) return

    const feeSection = feeLabel.parentElement
    const programSection = programLabel.parentElement
    if (!feeSection || !programSection) return

    const feeCheckbox = feeSection.querySelector('input[type="checkbox"]')
    const feeContainer = feeCheckbox?.closest('div')?.parentElement
    if (!feeContainer) return

    const actions = document.createElement('div')
    actions.style.cssText = 'display:flex;justify-content:flex-end;gap:8px;margin:0 0 8px'
    actions.append(
      createButton('Pilih semua', () => selectFeeTypes(feeSection, false)),
      createButton('Hanya berulang', () => selectFeeTypes(feeSection, true)),
    )
    feeContainer.insertAdjacentElement('beforebegin', actions)
    programSection.insertAdjacentElement('afterend', createField())
    card.dataset.invoiceFiltersMounted = 'true'
  }

  XMLHttpRequest.prototype.open = function (method, url, ...rest) {
    this.__ascInvoiceMethod = method
    this.__ascInvoiceUrl = url
    return originalOpen.call(this, method, url, ...rest)
  }

  XMLHttpRequest.prototype.send = function (body) {
    if (
      this.__ascInvoiceMethod?.toUpperCase() === 'POST'
      && typeof this.__ascInvoiceUrl === 'string'
      && this.__ascInvoiceUrl.includes('/finance/invoices/generate-batch')
    ) {
      const input = document.querySelector('[data-entry-year-filter]')
      if (!input?.value) return originalSend.call(this, body)
      if (typeof body !== 'string') {
        throw new Error('Format permintaan generate tagihan tidak dikenali; filter tahun tidak dapat diterapkan.')
      }

      try {
        const payload = JSON.parse(body)
        payload.entry_year = input.value
        body = JSON.stringify(payload)
      } catch (error) {
        console.error('Tidak dapat menerapkan filter tahun angkatan:', error)
        throw error
      }
    }

    return originalSend.call(this, body)
  }

  const observer = new MutationObserver(mountFilters)
  observer.observe(document.documentElement, { childList: true, subtree: true })
  mountFilters()
})()
