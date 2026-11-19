(() => {
  const apiUrl = '/api/auth/me'
  const bundleUrl = '/assets/class-schedule-20261005-2114.js'
  const cssUrl = '/assets/class-schedule-20261005-2114.css'
  let access = false
  let mountedElement = null
  let unmount = null
  let loadedMount = null
  let loadedStyles = null
  let scheduled = false
  const tokenCache = { token: null, promise: null }

  async function checkAccess() {
    const token = localStorage.getItem('auth_token')
    if (!token) {
      access = false
      tokenCache.token = null
      tokenCache.promise = null
      return
    }
    if (tokenCache.token === token && tokenCache.promise) return tokenCache.promise
    tokenCache.token = token
    tokenCache.promise = fetch(apiUrl, {
      headers: { Accept: 'application/json', Authorization: ['Bearer', token].join(' ') },
    }).then(async (response) => {
      if (!response.ok) throw new Error(`Gagal memeriksa akses NURIS (HTTP ${response.status}).`)
      const user = await response.json()
      const roles = user.roles ?? user.user?.roles ?? []
      const positions = user.position_codes ?? user.user?.position_codes ?? []
      const permissions = user.permissions ?? user.user?.permissions ?? []
      access = roles.some((role) => [
        'SUPER_ADMIN', 'ADMIN_AKADEMIK', 'DOSEN', 'WK1', 'WR1', 'KAPRODI', 'SEKPRODI', 'WADEK1',
      ].includes(role)) || positions.some((position) => ['KAPRODI', 'SEKPRODI'].includes(position))
        || permissions.includes('jadwal.create')
    }).catch((error) => {
      access = false
      tokenCache.promise = null
      console.error('Gagal memeriksa akses pengelolaan jadwal.', error)
    })
    return tokenCache.promise
  }

  function activePage() {
    return location.pathname.replace(/\/+$/, '') === '/akademik/kelas' && access
  }

  function loadMount() {
    if (loadedMount) return loadedMount
    loadedMount = import(bundleUrl).then((module) => module.mount)
    return loadedMount
  }

  function loadStyles() {
    if (loadedStyles) return loadedStyles
    loadedStyles = new Promise((resolve, reject) => {
      const stylesheet = document.createElement('link')
      stylesheet.id = 'asc-class-schedule-styles'
      stylesheet.rel = 'stylesheet'
      stylesheet.href = cssUrl
      stylesheet.onload = () => resolve()
      stylesheet.onerror = () => {
        stylesheet.remove()
        loadedStyles = null
        reject(new Error('Gagal memuat stylesheet edit jadwal.'))
      }
      document.head.appendChild(stylesheet)
    })
    return loadedStyles
  }

  async function sync() {
    const active = activePage()
    if (!active || !document.querySelector('#app main')) {
      if (unmount) unmount()
      unmount = null
      mountedElement = null
      document.querySelector('[data-asc-class-schedule-host]')?.remove()
      return
    }

    let host = document.querySelector('[data-asc-class-schedule-host]')
    if (!host) {
      host = document.createElement('div')
      host.dataset.ascClassScheduleHost = 'true'
      document.body.append(host)
    }
    if (mountedElement === host) return
    if (unmount) unmount()
    mountedElement = host
    try {
      await loadStyles()
      const mount = await loadMount()
      if (mountedElement === host && host.isConnected && activePage()) unmount = mount(host)
    } catch (error) {
      host.textContent = 'Fitur edit jadwal gagal dimuat. Muat ulang halaman untuk mencoba kembali.'
      console.error('Gagal memuat fitur edit jadwal.', error)
    }
  }

  function scheduleSync() {
    if (scheduled) return
    scheduled = true
    requestAnimationFrame(async () => {
      scheduled = false
      await checkAccess()
      await sync()
    })
  }

  const observer = new MutationObserver(scheduleSync)
  observer.observe(document.documentElement, { childList: true, subtree: true })
  window.addEventListener('popstate', scheduleSync)
  window.addEventListener('focus', scheduleSync)
  const originalPushState = history.pushState.bind(history)
  history.pushState = function (...args) {
    originalPushState(...args)
    scheduleSync()
  }
  const originalReplaceState = history.replaceState.bind(history)
  history.replaceState = function (...args) {
    originalReplaceState(...args)
    scheduleSync()
  }
  void checkAccess().then(sync)
})()
