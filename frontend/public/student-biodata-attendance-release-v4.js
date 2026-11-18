(() => {
  const API_BASE = 'https://api-asc.aljawami.ac.id/api'
  const STYLE_ID = 'asc-student-biodata-attendance-v4'
  const BUTTON_ATTRIBUTE = 'data-asc-student-biodata-v4'
  let scheduled = false
  let access = false
  let accessToken = null
  let accessPromise = null
  let modal = null
  let cameraCheckScheduled = false
  const switchedStreams = new Map()

  const fieldGroups = [
    {
      title: 'Data Pribadi',
      fields: [
        ['student.name', 'Nama Lengkap', 'text', true],
        ['student.gender', 'Jenis Kelamin', 'select', false, [['', 'Pilih'], ['L', 'Laki-laki'], ['P', 'Perempuan']]],
        ['student.birth_place', 'Tempat Lahir', 'text'],
        ['student.birth_date', 'Tanggal Lahir', 'date'],
        ['student.email', 'Email', 'email', true],
        ['student.phone', 'Nomor HP', 'tel'],
        ['student.home_phone', 'Telepon Rumah', 'tel'],
        ['profile.religion', 'Agama', 'text'],
        ['profile.nik', 'NIK', 'text'],
        ['profile.nisn', 'NISN', 'text'],
        ['profile.blood_type', 'Golongan Darah', 'select', false, [['', 'Pilih'], ['A', 'A'], ['B', 'B'], ['AB', 'AB'], ['O', 'O']]],
        ['profile.nationality', 'Kewarganegaraan', 'text'],
      ],
    },
    {
      title: 'Alamat Domisili',
      fields: [
        ['address.address', 'Alamat', 'textarea', false, null, 2],
        ['address.hamlet', 'Dusun', 'text'],
        ['address.rt', 'RT', 'text'],
        ['address.rw', 'RW', 'text'],
        ['address.village', 'Desa/Kelurahan', 'text'],
        ['address.district', 'Kecamatan', 'text'],
        ['address.city', 'Kota/Kabupaten', 'text'],
        ['address.province', 'Provinsi', 'text'],
        ['address.postal_code', 'Kode Pos', 'text'],
      ],
    },
    ...[
      ['father', 'Ayah'],
      ['mother', 'Ibu'],
      ['guardian', 'Wali'],
    ].map(([key, title]) => ({
      title,
      fields: [
        [`parents.${key}.name`, 'Nama', 'text'],
        [`parents.${key}.nik`, 'NIK', 'text'],
        [`parents.${key}.birth_date`, 'Tanggal Lahir', 'date'],
        [`parents.${key}.occupation`, 'Pekerjaan', 'text'],
        [`parents.${key}.phone`, 'Nomor HP', 'tel'],
        [`parents.${key}.email`, 'Email', 'email'],
        [`parents.${key}.education`, 'Pendidikan Terakhir', 'text'],
        [`parents.${key}.income`, 'Penghasilan', 'text'],
        [`parents.${key}.is_alive`, 'Masih hidup', 'checkbox'],
      ],
    })),
    {
      title: 'Pendidikan Sebelumnya',
      fields: [
        ['education.institution_name', 'Nama Sekolah (SMA/MA/SMK)', 'text'],
        ['education.institution_address', 'Alamat Sekolah', 'text'],
        ['education.major', 'Jurusan', 'text'],
        ['education.graduation_year', 'Tahun Lulus', 'number'],
        ['education.diploma_number', 'Nomor Ijazah', 'text'],
      ],
    },
    {
      title: 'Tautan Dokumen',
      fields: [
        ['documents.diploma_link', 'Ijazah', 'url'],
        ['documents.family_card_link', 'Kartu Keluarga', 'url'],
        ['documents.identity_link', 'KTP / Identitas', 'url'],
      ],
    },
  ]

  function installStyles() {
    if (document.getElementById(STYLE_ID)) return
    const style = document.createElement('style')
    style.id = STYLE_ID
    style.textContent = `
      .asc-bo-button{border:0;background:transparent;color:#a16207;cursor:pointer;font:inherit;font-size:11px;font-weight:600;line-height:1.2;padding:6px 7px;border-radius:7px}
      .asc-bo-button:hover{background:#fffbeb}.asc-bo-button:disabled{opacity:.55;cursor:wait}
      .asc-bo-overlay{position:fixed;inset:0;z-index:10000;background:#0f172a99;display:flex;align-items:center;justify-content:center;padding:16px}
      .asc-bo-dialog{background:white;width:min(880px,100%);max-height:92vh;border-radius:14px;box-shadow:0 24px 80px #0004;display:flex;flex-direction:column;color:#111827;font:14px/1.5 system-ui,sans-serif}
      .asc-bo-head,.asc-bo-foot{padding:14px 20px;display:flex;align-items:center;justify-content:space-between;border-bottom:1px solid #e5e7eb;gap:12px}
      .asc-bo-foot{border-top:1px solid #e5e7eb;border-bottom:0;justify-content:flex-end}
      .asc-bo-title{font-weight:700;font-size:17px}.asc-bo-subtitle{font-size:12px;color:#6b7280}
      .asc-bo-close{border:0;background:transparent;color:#6b7280;font-size:24px;cursor:pointer}
      .asc-bo-body{padding:18px 20px;overflow:auto}.asc-bo-section{margin:0 0 22px;padding:0;border:0}
      .asc-bo-section legend{font-size:14px;font-weight:700;border-bottom:1px solid #e5e7eb;padding:0 0 7px;margin-bottom:12px;width:100%}
      .asc-bo-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}
      .asc-bo-field{display:flex;flex-direction:column;gap:4px;font-size:12px;font-weight:600;color:#374151}
      .asc-bo-field input,.asc-bo-field textarea,.asc-bo-field select{box-sizing:border-box;width:100%;border:1px solid #d1d5db;border-radius:8px;padding:8px 10px;background:white;color:#111827;font:14px system-ui,sans-serif}
      .asc-bo-field input:focus,.asc-bo-field textarea:focus,.asc-bo-field select:focus{outline:2px solid #93c5fd;border-color:#3b82f6}
      .asc-bo-field input[type=checkbox]{width:17px;height:17px}.asc-bo-check{display:flex;align-items:center;gap:8px;padding-top:21px}
      .asc-bo-doc-link{font-weight:500;color:#2563eb;text-decoration:underline}.asc-bo-note{display:none;margin:0 0 14px;padding:9px 12px;border-radius:8px;font-size:13px}
      .asc-bo-note[data-kind=error]{display:block;background:#fef2f2;color:#b91c1c}.asc-bo-note[data-kind=success]{display:block;background:#f0fdf4;color:#15803d}
      .asc-bo-loading{text-align:center;color:#6b7280;padding:40px 8px}
      .asc-bo-cancel,.asc-bo-save{border:0;border-radius:8px;padding:9px 14px;font:600 13px system-ui,sans-serif;cursor:pointer}
      .asc-bo-cancel{background:#f3f4f6;color:#374151}.asc-bo-save{background:#2563eb;color:white}
      .asc-bo-save:disabled{opacity:.55;cursor:wait}
      .asc-attendance-scroll{max-width:100%;overflow-x:auto!important;-webkit-overflow-scrolling:touch;overscroll-behavior-x:contain}
      .asc-attendance-scroll table{min-width:760px!important}
      .asc-attendance-hint{display:none}
      .asc-camera-switch{position:absolute;right:8px;top:8px;z-index:2;border:0;border-radius:8px;background:#ffffffed;color:#1d4ed8;padding:8px 10px;font:600 12px system-ui,sans-serif;cursor:pointer;box-shadow:0 1px 5px #0003}
      .asc-camera-switch:disabled{opacity:.6;cursor:wait}
      @media(max-width:640px){.asc-bo-overlay{padding:8px}.asc-bo-dialog{max-height:96vh;border-radius:10px}.asc-bo-head,.asc-bo-foot{padding:12px}.asc-bo-body{padding:14px}.asc-bo-grid{grid-template-columns:1fr;gap:10px}.asc-bo-check{padding-top:0}.asc-bo-hint{font-size:11px}
        .asc-attendance-hint{display:block;margin:0 0 8px;color:#6b7280;font:12px system-ui,sans-serif}}
    `
    document.head.append(style)
  }

  function api(path, options = {}) {
    const token = localStorage.getItem('auth_token')
    if (!token) throw new Error('Sesi login tidak ditemukan. Silakan login kembali.')
    return fetch(`${API_BASE}${path}`, {
      ...options,
      headers: {
        Accept: 'application/json',
        Authorization: `Bearer ${token}`,
        ...(options.body ? { 'Content-Type': 'application/json' } : {}),
        ...options.headers,
      },
    }).then(async (response) => {
      const payload = await response.json().catch(() => ({}))
      if (!response.ok) {
        const firstError = payload.errors ? Object.values(payload.errors).flat()[0] : null
        throw new Error(firstError || payload.message || `Permintaan gagal (HTTP ${response.status}).`)
      }
      return payload
    })
  }

  async function checkAccess() {
    const token = localStorage.getItem('auth_token')
    if (!token) {
      access = false
      accessToken = null
      accessPromise = null
      return
    }
    if (token === accessToken && accessPromise) return accessPromise
    accessToken = token
    accessPromise = api('/auth/me').then((payload) => {
      const user = payload.user ?? payload
      const roles = (user.roles ?? []).map((role) => typeof role === 'string' ? role : role.name)
      const positions = (user.position_codes ?? user.positions ?? []).map((position) =>
        typeof position === 'string' ? position : position.position_code ?? position.code,
      )
      access = roles.some((role) => ['SUPER_ADMIN', 'ADMIN_AKADEMIK', 'ADMIN_UMUM'].includes(role))
        || positions.some((position) => ['KAPRODI', 'SEKPRODI'].includes(position))
    }).catch((error) => {
      access = false
      accessPromise = null
      console.error('Gagal memeriksa akses biodata mahasiswa.', error)
    })
    return accessPromise
  }

  function htmlEscape(value) {
    return String(value ?? '').replace(/[&<>"']/g, (character) => ({
      '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
    })[character])
  }

  function fieldMarkup([name, label, type, required, options, rows]) {
    const id = `asc-bo-${name.replaceAll('.', '-')}`
    const requiredMark = required ? ' *' : ''
    if (type === 'checkbox') {
      return `<label class="asc-bo-field asc-bo-check" for="${id}"><input id="${id}" name="${name}" type="checkbox"><span>${label}</span></label>`
    }
    let control
    if (type === 'select') {
      control = `<select id="${id}" name="${name}">${options.map(([value, text]) => `<option value="${htmlEscape(value)}">${htmlEscape(text)}</option>`).join('')}</select>`
    } else if (type === 'textarea') {
      control = `<textarea id="${id}" name="${name}" rows="${rows ?? 2}"></textarea>`
    } else {
      control = `<input id="${id}" name="${name}" type="${type}"${required ? ' required' : ''}>`
    }
    const link = name.startsWith('documents.')
      ? `<a class="asc-bo-doc-link" data-document-link="${name}" target="_blank" rel="noopener noreferrer" hidden>Buka tautan saat ini</a>`
      : ''
    return `<label class="asc-bo-field" for="${id}"><span>${label}${requiredMark}</span>${control}${link}</label>`
  }

  function createModal(student) {
    closeModal()
    const overlay = document.createElement('div')
    overlay.className = 'asc-bo-overlay'
    const sections = fieldGroups.map((group) => `
      <fieldset class="asc-bo-section"><legend>${htmlEscape(group.title)}</legend>
        <div class="asc-bo-grid">${group.fields.map(fieldMarkup).join('')}</div>
      </fieldset>`).join('')
    overlay.innerHTML = `
      <section class="asc-bo-dialog" role="dialog" aria-modal="true" aria-labelledby="asc-bo-title">
        <header class="asc-bo-head"><div><div class="asc-bo-title" id="asc-bo-title">Biodata Mahasiswa Lengkap</div>
          <div class="asc-bo-subtitle">NIM ${htmlEscape(student.nim)} · ${htmlEscape(student.name)}${student.study_program?.code ? ` · ${htmlEscape(student.study_program.code)}` : ''}</div></div>
          <button type="button" class="asc-bo-close" aria-label="Tutup">&times;</button></header>
        <div class="asc-bo-body"><div class="asc-bo-note" role="status"></div><div class="asc-bo-loading">Memuat biodata...</div>
          <form class="asc-bo-form" hidden>${sections}</form></div>
        <footer class="asc-bo-foot"><button type="button" class="asc-bo-cancel">Batal</button><button type="submit" form="asc-bo-submit" class="asc-bo-save">Simpan Biodata</button></footer>
      </section>`

    const form = overlay.querySelector('.asc-bo-form')
    form.id = 'asc-bo-submit'
    const remove = () => closeModal()
    overlay.querySelector('.asc-bo-close').addEventListener('click', remove)
    overlay.querySelector('.asc-bo-cancel').addEventListener('click', remove)
    overlay.addEventListener('click', (event) => {
      if (event.target === overlay) remove()
    })
    form.addEventListener('submit', (event) => {
      event.preventDefault()
      void saveBiodata(student, form, overlay)
    })
    document.body.append(overlay)
    document.body.style.overflow = 'hidden'
    modal = overlay
    overlay.querySelector('.asc-bo-close').focus()
    return overlay
  }

  function closeModal() {
    if (!modal) return
    modal.remove()
    modal = null
    document.body.style.overflow = ''
  }

  function setPath(target, path, value) {
    const parts = path.split('.')
    const key = parts.pop()
    let current = target
    for (const part of parts) current = current[part] ??= {}
    current[key] = value
  }

  function fillModal(overlay, data) {
    overlay.querySelector('.asc-bo-loading').hidden = true
    const form = overlay.querySelector('form')
    form.hidden = false
    for (const control of form.elements) {
      if (!control.name) continue
      const value = control.name.split('.').reduce((object, key) => object?.[key], data)
      if (control.type === 'checkbox') control.checked = Boolean(value)
      else control.value = value ?? ''
      if (control.name.startsWith('documents.')) {
        const link = form.querySelector(`[data-document-link="${control.name}"]`)
        if (value) {
          try {
            const url = new URL(value, location.origin)
            if (url.protocol === 'http:' || url.protocol === 'https:') {
              link.href = url.href
              link.hidden = false
            }
          } catch {
            link.hidden = true
          }
        }
      }
    }
  }

  function collectForm(form) {
    const payload = {}
    for (const control of form.elements) {
      if (!control.name) continue
      let value
      if (control.type === 'checkbox') value = control.checked
      else if (control.type === 'number') value = control.value === '' ? null : Number(control.value)
      else value = control.value === '' ? null : control.value
      setPath(payload, control.name, value)
    }
    for (const relation of ['father', 'mother', 'guardian']) {
      payload.parents[relation].is_alive = Boolean(payload.parents[relation].is_alive)
    }
    return payload
  }

  function showNote(overlay, message, kind) {
    const note = overlay.querySelector('.asc-bo-note')
    note.textContent = message
    note.dataset.kind = kind
  }

  async function openBiodata(button, nim) {
    button.disabled = true
    const modalStudent = { nim, name: 'Memuat...' }
    const overlay = createModal(modalStudent)
    try {
      const list = await api(`/students?search=${encodeURIComponent(nim)}&per_page=50`)
      const students = list.data ?? list.data?.data ?? []
      const student = students.find((item) => String(item.nim) === nim)
      if (!student) throw new Error(`Data mahasiswa dengan NIM ${nim} tidak ditemukan.`)
      Object.assign(modalStudent, student)
      overlay.querySelector('.asc-bo-subtitle').textContent = `NIM ${student.nim} · ${student.name}${student.study_program?.code ? ` · ${student.study_program.code}` : ''}`
      const biodata = await api(`/students/${student.id}/biodata`)
      if (modal === overlay) fillModal(overlay, biodata)
    } catch (error) {
      closeModal()
      window.alert(error instanceof Error ? error.message : 'Gagal memuat biodata mahasiswa.')
    } finally {
      button.disabled = false
    }
  }

  async function saveBiodata(student, form, overlay) {
    if (!form.reportValidity()) return
    const save = overlay.querySelector('.asc-bo-save')
    save.disabled = true
    save.textContent = 'Menyimpan...'
    try {
      const studentId = Number(student.id)
      if (!Number.isSafeInteger(studentId) || studentId < 1) {
        throw new Error('ID mahasiswa tidak valid. Tutup formulir dan buka kembali biodata mahasiswa.')
      }
      const result = await api(`/students/${studentId}/biodata`, {
        method: 'PUT',
        body: JSON.stringify(collectForm(form)),
      })
      showNote(overlay, result.message ?? 'Biodata berhasil diperbarui.', 'success')
      window.setTimeout(() => {
        if (modal === overlay) closeModal()
      }, 700)
    } catch (error) {
      showNote(overlay, error instanceof Error ? error.message : 'Gagal menyimpan biodata mahasiswa.', 'error')
      save.disabled = false
      save.textContent = 'Simpan Biodata'
    }
  }

  function studentListPage() {
    if (!access || location.pathname.replace(/\/+$/, '') !== '/sdm/students') return false
    return Array.from(document.querySelectorAll('#app h1')).some((heading) => heading.textContent.trim() === 'Data Mahasiswa')
  }

  function addBiodataButtons() {
    if (!studentListPage()) return
    for (const table of document.querySelectorAll('#app table')) {
      const headings = Array.from(table.querySelectorAll('thead th'))
      const nimIndex = headings.findIndex((cell) => cell.textContent.trim().toUpperCase() === 'NIM')
      if (nimIndex < 0) continue
      for (const row of table.querySelectorAll('tbody tr')) {
        const nimCell = row.cells[nimIndex]
        const actionCell = row.cells[row.cells.length - 1]
        const nim = nimCell?.textContent.trim()
        if (!nim || !actionCell || actionCell.querySelector(`[${BUTTON_ATTRIBUTE}]`)) continue
        const controls = actionCell.querySelector('.flex') ?? actionCell
        const button = document.createElement('button')
        button.type = 'button'
        button.className = 'asc-bo-button'
        button.title = `Biodata lengkap ${nim}`
        button.setAttribute('aria-label', `Biodata lengkap ${nim}`)
        button.setAttribute(BUTTON_ATTRIBUTE, '')
        button.textContent = 'BO'
        button.addEventListener('click', () => void openBiodata(button, nim))
        controls.append(button)
      }
    }
  }

  function fixAttendanceTable() {
    if (!/\/perkuliahan\/\d+\/presensi\/\d+\/?$/.test(location.pathname)) return
    for (const table of document.querySelectorAll('#app table')) {
      const headings = Array.from(table.querySelectorAll('thead th'))
      if (!headings.some((cell) => cell.textContent.trim().toLowerCase() === 'status presensi')) continue
      const wrapper = table.parentElement
      wrapper.classList.add('asc-attendance-scroll')
      const hint = document.createElement('p')
      hint.className = 'asc-attendance-hint'
      hint.textContent = 'Geser tabel ke kiri atau kanan untuk memilih status presensi.'
      if (!wrapper.previousElementSibling?.classList.contains('asc-attendance-hint')) wrapper.before(hint)
    }
  }

  function stopSwitchedStream(video) {
    const stream = switchedStreams.get(video)
    if (stream) {
      stream.getTracks().forEach((track) => track.stop())
      switchedStreams.delete(video)
    }
  }

  function scheduleCameraScan() {
    if (cameraCheckScheduled) return
    cameraCheckScheduled = true
    requestAnimationFrame(() => {
      cameraCheckScheduled = false
      for (const video of switchedStreams.keys()) {
        if (!video.isConnected || !video.getClientRects().length) stopSwitchedStream(video)
      }
      if (!/\/perkuliahan\/\d+\/?$/.test(location.pathname)) return
      let pendingCameraPreview = false
      for (const video of document.querySelectorAll('video')) {
        if (video.closest('[data-asc-camera-switch]')) continue
        if (!video.srcObject) {
          if (video.getClientRects().length) pendingCameraPreview = true
          continue
        }
        if (!video.srcObject || video.dataset.ascCameraSwitch) continue
        video.dataset.ascCameraSwitch = 'true'
        const container = video.parentElement
        if (!container) continue
        container.style.position = 'relative'
        const button = document.createElement('button')
        button.type = 'button'
        button.className = 'asc-camera-switch'
        button.textContent = 'Ganti kamera'
        button.addEventListener('click', async () => {
          if (button.disabled) return
          button.disabled = true
          const currentStream = video.srcObject instanceof MediaStream ? video.srcObject : null
          const currentTrack = currentStream?.getVideoTracks()[0]
          const currentMode = button.dataset.facingMode
            ?? currentTrack?.getSettings().facingMode
            ?? 'environment'
          const nextMode = currentMode === 'user' ? 'environment' : 'user'
          button.textContent = 'Mengganti...'
          currentStream?.getTracks().forEach((track) => track.stop())
          switchedStreams.delete(video)
          video.srcObject = null
          try {
            await new Promise((resolve) => window.setTimeout(resolve, 300))
            let stream
            try {
              stream = await navigator.mediaDevices.getUserMedia({
                video: { facingMode: { exact: nextMode } }, audio: false,
              })
            } catch {
              stream = await navigator.mediaDevices.getUserMedia({
                video: { facingMode: { ideal: nextMode } }, audio: false,
              })
            }
            if (!video.isConnected) {
              stream.getTracks().forEach((track) => track.stop())
              return
            }
            video.srcObject = stream
            switchedStreams.set(video, stream)
            await video.play()
            const actualMode = stream.getVideoTracks()[0]?.getSettings().facingMode ?? nextMode
            button.dataset.facingMode = actualMode
            button.textContent = `Ganti ke kamera ${actualMode === 'user' ? 'belakang' : 'depan'}`
          } catch (error) {
            button.dataset.facingMode = currentMode
            button.textContent = `Ganti ke kamera ${currentMode === 'user' ? 'belakang' : 'depan'}`
            try {
              const restoredStream = await navigator.mediaDevices.getUserMedia({
                video: { facingMode: { ideal: currentMode } }, audio: false,
              })
              if (video.isConnected) {
                video.srcObject = restoredStream
                switchedStreams.set(video, restoredStream)
                await video.play()
              } else {
                restoredStream.getTracks().forEach((track) => track.stop())
              }
            } catch (restoreError) {
              console.error('Gagal memulihkan kamera sebelumnya.', restoreError)
            }
            window.alert(`Tidak bisa mengganti kamera: ${error instanceof Error ? error.message : 'akses kamera gagal.'}`)
          } finally {
            button.disabled = false
          }
        })
        container.append(button)
        const initialMode = video.srcObject instanceof MediaStream
          ? video.srcObject.getVideoTracks()[0]?.getSettings().facingMode
          : null
        button.dataset.facingMode = initialMode ?? 'environment'
        button.textContent = `Ganti ke kamera ${initialMode === 'user' ? 'belakang' : 'depan'}`
      }
      if (pendingCameraPreview) window.setTimeout(scheduleCameraScan, 250)
    })
  }

  function render() {
    installStyles()
    addBiodataButtons()
    fixAttendanceTable()
    scheduleCameraScan()
  }

  function scheduleRender() {
    if (scheduled) return
    scheduled = true
    requestAnimationFrame(async () => {
      scheduled = false
      await checkAccess()
      render()
    })
  }

  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape') closeModal()
  })
  const observer = new MutationObserver(scheduleRender)
  observer.observe(document.documentElement, { childList: true, subtree: true })
  window.addEventListener('popstate', scheduleRender)
  window.addEventListener('focus', scheduleRender)
  const pushState = history.pushState.bind(history)
  history.pushState = function (...args) {
    pushState(...args)
    scheduleRender()
  }
  const replaceState = history.replaceState.bind(history)
  history.replaceState = function (...args) {
    replaceState(...args)
    scheduleRender()
  }
  scheduleRender()
})()
