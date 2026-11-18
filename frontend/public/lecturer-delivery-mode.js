(() => {
  const selectorId = 'asc-honor-delivery-mode'
  const modesByMeeting = new Map()
  const originalOpen = XMLHttpRequest.prototype.open
  const originalSend = XMLHttpRequest.prototype.send
  let activeMode = ''
  let activeMeetingNumber = null

  function setActiveMode(mode, meetingNumber = null) {
    activeMode = mode
    activeMeetingNumber = meetingNumber
    const select = document.getElementById(selectorId)
    if (select) select.value = mode
  }

  function isJournalListUrl(url) {
    return /\/api\/lectures\/\d+\/journals(?:\?.*)?$/.test(url)
  }

  function selectedMode() {
    return document.getElementById(selectorId)?.value || activeMode
  }

  function attachSelector() {
    if (document.getElementById(selectorId)) return

    const label = [...document.querySelectorAll('label')]
      .find((item) => item.textContent.trim() === 'Aktivitas Pembelajaran / Metode')
    const field = label?.parentElement
    if (!field || !field.querySelector('textarea')) return

    const container = document.createElement('div')
    const modeLabel = document.createElement('label')
    modeLabel.className = 'text-xs font-medium text-gray-700'
    modeLabel.textContent = 'Cara dosen mengajar'

    const select = document.createElement('select')
    select.id = selectorId
    select.required = true
    select.className = 'w-full mt-1 px-3 py-2 border rounded-lg text-sm'
    select.setAttribute('aria-describedby', `${selectorId}-hint`)
    select.add(new Option('Pilih cara dosen mengajar', '', true, true))
    select.add(new Option('Luring — dosen mengajar di kampus', 'ONSITE'))
    select.add(new Option('Daring — dosen mengajar secara online', 'ONLINE'))
    select.value = activeMode
    select.addEventListener('change', () => {
      activeMode = select.value
    })

    const hint = document.createElement('p')
    hint.id = `${selectorId}-hint`
    hint.className = 'mt-1 text-xs text-gray-500'
    hint.textContent = 'Untuk kelas hybrid, pilih cara dosen mengajar pada pertemuan ini.'

    container.append(modeLabel, select, hint)
    field.insertAdjacentElement('afterend', container)
  }

  document.addEventListener('click', (event) => {
    const target = event.target
    if (!(target instanceof Element)) return

    if (target.closest('button')?.textContent.trim().includes('Tambah Pertemuan')) {
      setActiveMode('')
      return
    }

    const editButton = target.closest('button[title="Edit"]')
    if (!editButton) return

    const row = editButton.closest('div.flex.items-center.gap-4')
    const meetingNumber = Number.parseInt(row?.querySelector('span')?.textContent ?? '', 10)
    setActiveMode(modesByMeeting.get(meetingNumber) ?? '', meetingNumber)
  }, true)

  XMLHttpRequest.prototype.open = function (method, url, ...args) {
    this.__ascHonorMethod = String(method).toUpperCase()
    this.__ascHonorUrl = String(url)

    const result = originalOpen.call(this, method, url, ...args)
    if (this.__ascHonorMethod === 'GET' && isJournalListUrl(this.__ascHonorUrl)) {
      this.addEventListener('load', () => {
        if (this.status < 200 || this.status >= 300) return
        try {
          const journals = JSON.parse(this.responseText)
          if (!Array.isArray(journals)) return
          journals.forEach((journal) => {
            if (journal.delivery_mode) modesByMeeting.set(Number(journal.meeting_number), journal.delivery_mode)
          })
        } catch {
          // Ignore non-JSON responses; the normal application request handles them.
        }
      }, { once: true })
    }
    return result
  }

  XMLHttpRequest.prototype.send = function (body) {
    const isCreate = this.__ascHonorMethod === 'POST'
      && /\/api\/lectures\/\d+\/journals(?:\?.*)?$/.test(this.__ascHonorUrl ?? '')
    const updateMatch = this.__ascHonorMethod === 'PUT'
      ? (this.__ascHonorUrl ?? '').match(/\/api\/lectures\/journals\/(\d+)(?:\?.*)?$/)
      : null

    if (!isCreate && !updateMatch) return originalSend.call(this, body)

    let mode = selectedMode()
    if (!mode) {
      window.alert('Pilih cara dosen mengajar sebelum menyimpan jurnal.')
      mode = '__REQUIRED__'
    }

    try {
      if (body instanceof FormData) {
        body.set('delivery_mode', mode)
      } else if (typeof body === 'string') {
        const payload = JSON.parse(body)
        payload.delivery_mode = mode
        body = JSON.stringify(payload)
      } else {
        window.alert('Jurnal tidak dapat disimpan: format data tidak dikenali. Muat ulang halaman dan coba lagi.')
        this.abort()
        return
      }
    } catch {
      window.alert('Jurnal tidak dapat disimpan: data formulir tidak valid. Muat ulang halaman dan coba lagi.')
      this.abort()
      return
    }

    if (updateMatch && activeMeetingNumber !== null) {
      this.addEventListener('load', () => {
        if (this.status >= 200 && this.status < 300) {
          modesByMeeting.set(activeMeetingNumber, mode)
        }
      }, { once: true })
    }
    activeMode = mode
    return originalSend.call(this, body)
  }

  new MutationObserver(() => {
    attachSelector()
  }).observe(document.body, { childList: true, subtree: true })
  attachSelector()
})()
