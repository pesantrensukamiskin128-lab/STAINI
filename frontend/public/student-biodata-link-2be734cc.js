(() => {
  const apiUrl = '/api/auth/me'
  const linkId = 'asc-student-biodata-link'
  const positionKey = 'asc-student-biodata-button-position'
  let checkedToken = null
  let isStudent = false
  let checking = false
  let moved = false

  function clampPosition(element, x, y) {
    return {
      x: Math.max(8, Math.min(x, window.innerWidth - element.offsetWidth - 8)),
      y: Math.max(8, Math.min(y, window.innerHeight - element.offsetHeight - 8)),
    }
  }

  function applyPosition(element, x, y) {
    const position = clampPosition(element, x, y)
    element.style.left = `${position.x}px`
    element.style.top = `${position.y}px`
    element.style.right = 'auto'
    element.style.bottom = 'auto'
    return position
  }

  function restorePosition(element) {
    try {
      const saved = JSON.parse(localStorage.getItem(positionKey) || 'null')
      if (Number.isFinite(saved?.x) && Number.isFinite(saved?.y)) {
        applyPosition(element, saved.x, saved.y)
        return
      }
    } catch (error) {
      console.warn('Posisi tombol biodata tersimpan tidak dapat dibaca.', error)
    }

    applyPosition(element, 20, window.innerHeight - element.offsetHeight - 20)
  }

  function savePosition(position) {
    try {
      localStorage.setItem(positionKey, JSON.stringify(position))
    } catch (error) {
      console.warn('Posisi tombol biodata tidak dapat disimpan.', error)
    }
  }

  function renderLink() {
    const existing = document.getElementById(linkId)
    if (!isStudent) {
      existing?.remove()
      return
    }
    if (existing) return

    const link = document.createElement('a')
    link.id = linkId
    link.href = '/biodata/'
    link.textContent = '↕ Biodata Mahasiswa'
    link.setAttribute('aria-label', 'Buka Biodata Mahasiswa')
    link.title = 'Seret untuk memindahkan tombol'
    link.style.cssText = 'position:fixed;z-index:9999;padding:10px 16px;border-radius:10px;background:#2563eb;color:#fff;font:600 14px/1.4 system-ui,sans-serif;text-decoration:none;box-shadow:0 4px 12px #0003;cursor:move;touch-action:none;user-select:none'
    link.addEventListener('pointerdown', (event) => {
      if (event.button !== 0) return
      const startX = event.clientX
      const startY = event.clientY
      const bounds = link.getBoundingClientRect()
      const offsetX = startX - bounds.left
      const offsetY = startY - bounds.top
      moved = false
      link.setPointerCapture(event.pointerId)

      const move = (pointerEvent) => {
        if (Math.abs(pointerEvent.clientX - startX) + Math.abs(pointerEvent.clientY - startY) > 5) moved = true
        if (moved) applyPosition(link, pointerEvent.clientX - offsetX, pointerEvent.clientY - offsetY)
      }
      const finish = () => {
        link.removeEventListener('pointermove', move)
        link.removeEventListener('pointerup', finish)
        link.removeEventListener('pointercancel', finish)
        if (moved) {
          const rect = link.getBoundingClientRect()
          savePosition({ x: rect.left, y: rect.top })
        }
      }

      link.addEventListener('pointermove', move)
      link.addEventListener('pointerup', finish)
      link.addEventListener('pointercancel', finish)
    })
    link.addEventListener('click', (event) => {
      if (moved) {
        event.preventDefault()
        event.stopPropagation()
        moved = false
      }
    })
    document.body.appendChild(link)
    restorePosition(link)
  }

  async function checkAccess() {
    const token = localStorage.getItem('auth_token')
    if (token !== checkedToken && !checking) {
      checkedToken = token
      isStudent = false
      if (!token) {
        renderLink()
        return
      }

      checking = true
      try {
        const response = await fetch(apiUrl, {
          headers: { Accept: 'application/json', Authorization: `Bearer ${token}` },
        })
        if (response.ok) {
          const payload = await response.json()
          const roles = payload.user?.roles ?? payload.roles ?? []
          isStudent = Array.isArray(roles) && roles.some((role) =>
            (typeof role === 'string' ? role : role?.name) === 'MAHASISWA',
          )
        } else if (response.status >= 500) {
          checkedToken = null
        }
      } catch (error) {
        checkedToken = null
        console.error('Gagal memeriksa akses menu biodata mahasiswa.', error)
      } finally {
        checking = false
        renderLink()
      }
    } else {
      renderLink()
    }
  }

  checkAccess()
  window.setInterval(checkAccess, 1500)
  new MutationObserver(renderLink).observe(document.documentElement, { childList: true, subtree: true })
  window.addEventListener('storage', checkAccess)
  window.addEventListener('resize', () => {
    const link = document.getElementById(linkId)
    if (!link) return
    const rect = link.getBoundingClientRect()
    const position = applyPosition(link, rect.left, rect.top)
    savePosition(position)
  })
})()
