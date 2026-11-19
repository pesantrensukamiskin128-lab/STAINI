(() => {
  const apiUrl = '/api/auth/me'
  const bundleUrl = '/assets/academic-tools-20261005-1640.js'
  const cssUrl = '/assets/academic-tools-20261005-1640.css'
  let access = { advisor: false, krs: false }
  let mountedElement = null
  let mountedPage = null
  let unmount = null
  let loadedMount = null
  let loadedStyles = null
  let scheduled = false
  const tokenCache = { token: null, promise: null }

  async function checkAccess() {
    const token = localStorage.getItem('auth_token')
    if (!token) {
      access = { advisor: false, krs: false }
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
      const adminAdvisor = roles.some((role) => ['SUPER_ADMIN', 'ADMIN_AKADEMIK', 'ADMIN_UMUM'].includes(role))
      const prodi = positions.some((position) => ['KAPRODI', 'SEKPRODI'].includes(position))
      const reviewAdmin = roles.some((role) => ['SUPER_ADMIN', 'ADMIN_AKADEMIK'].includes(role))
      access = { advisor: adminAdvisor || prodi, krs: reviewAdmin || prodi }
    }).catch((error) => {
      access = { advisor: false, krs: false }
      console.error('Gagal memeriksa akses fitur akademik.', error)
    })
    return tokenCache.promise
  }

  function activePage() {
    const path = location.pathname.replace(/\/+$/, '') || '/'
    if (path === '/sdm/students' && access.advisor) return 'advisor'
    if (path === '/akademik/krs' && access.krs) return 'krs'
    return null
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
      stylesheet.id = 'asc-academic-tools-styles'
      stylesheet.rel = 'stylesheet'
      stylesheet.href = cssUrl
      stylesheet.onload = () => resolve()
      stylesheet.onerror = () => {
        stylesheet.remove()
        loadedStyles = null
        reject(new Error('Gagal memuat stylesheet fitur akademik.'))
      }
      document.head.appendChild(stylesheet)
    })
    return loadedStyles
  }

  async function sync() {
    const page = activePage()
    if (!page || !document.querySelector('#app main')) {
      if (unmount) unmount()
      unmount = null
      mountedElement = null
      mountedPage = null
      document.querySelector('[data-asc-academic-tools-host]')?.remove()
      return
    }

    let host = document.querySelector('[data-asc-academic-tools-host]')
    if (!host) {
      host = document.createElement('div')
      host.dataset.ascAcademicToolsHost = 'true'
      document.body.append(host)
    }

    if (mountedElement === host && mountedPage === page) return
    if (unmount) unmount()
    mountedElement = host
    mountedPage = page
    try {
      await loadStyles()
      const mount = await loadMount()
      if (mountedElement === host && host.isConnected && activePage() === page) {
        unmount = mount(host, page)
      }
    } catch (error) {
      host.textContent = 'Fitur akademik gagal dimuat. Muat ulang halaman untuk mencoba kembali.'
      console.error('Gagal memuat fitur akademik embedded.', error)
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
