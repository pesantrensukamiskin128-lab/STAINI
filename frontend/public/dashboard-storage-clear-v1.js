(() => {
  const buttonId = 'asc-dashboard-storage-clear'
  const apiBase = 'https://api-asc.aljawami.ac.id/api'
  let clearing = false

  function mountButton() {
    if (window.location.pathname.replace(/\/+$/, '') !== '/dashboard') return
    if (document.getElementById(buttonId)) return
    if ([...document.querySelectorAll('#app button')].some(button => button.textContent?.includes('Bersihkan cache & keluar'))) return

    const heading = [...document.querySelectorAll('#app h1')]
      .find(element => element.textContent?.includes("Assalamu'alaikum"))
    if (!heading?.parentElement) return

    const header = heading.parentElement
    header.style.display = 'flex'
    header.style.flexWrap = 'wrap'
    header.style.alignItems = 'flex-start'
    header.style.justifyContent = 'space-between'
    header.style.gap = '12px'

    const button = document.createElement('button')
    button.id = buttonId
    button.type = 'button'
    button.textContent = 'Bersihkan cache & keluar'
    button.setAttribute('aria-label', 'Bersihkan cache dan penyimpanan ASC, lalu keluar')
    button.style.cssText = 'display:inline-flex;align-items:center;gap:8px;border:1px solid #e5e7eb;border-radius:8px;background:#fff;padding:8px 12px;color:#374151;font:500 14px system-ui;cursor:pointer'
    button.addEventListener('click', async () => {
      if (clearing || !window.confirm('Hapus cache dan seluruh penyimpanan lokal ASC di browser ini, termasuk sesi login? Anda harus masuk kembali setelahnya.')) return
      clearing = true
      button.disabled = true
      button.textContent = 'Membersihkan...'
      button.style.cursor = 'wait'
      button.style.opacity = '0.6'

      let serverLogoutSucceeded = false
      const token = localStorage.getItem('auth_token')
      if (token) {
        try {
          const response = await fetch(`${apiBase}/auth/logout`, {
            method: 'POST',
            headers: { Accept: 'application/json', Authorization: `Bearer ${token}` },
          })
          serverLogoutSucceeded = response.ok
        } catch (error) {
          console.warn('Sesi server ASC tidak dapat dicabut; sesi browser tetap akan dihapus.', error)
        }
      }

      try {
        const cacheNames = await caches.keys()
        const cacheResults = await Promise.all(cacheNames.map(cacheName => caches.delete(cacheName)))
        if (cacheResults.some(deleted => !deleted)) throw new Error('Sebagian cache browser tidak dapat dihapus.')

        localStorage.clear()
        sessionStorage.clear()

        if (indexedDB.databases) {
          const databases = await indexedDB.databases()
          await Promise.all(databases
            .filter(database => database.name)
            .map(database => new Promise((resolve, reject) => {
              const request = indexedDB.deleteDatabase(database.name)
              request.onsuccess = () => resolve()
              request.onerror = () => reject(request.error ?? new Error(`Gagal menghapus database ${database.name}.`))
              request.onblocked = () => reject(new Error(`Database ${database.name} masih digunakan tab lain.`))
            })))
        }
      } catch (error) {
        console.error('Pembersihan penyimpanan ASC gagal.', error)
        window.alert(error instanceof Error
          ? `Pembersihan penyimpanan gagal: ${error.message}`
          : 'Pembersihan penyimpanan gagal.')
        button.disabled = false
        button.textContent = 'Bersihkan cache & keluar'
        button.style.cursor = 'pointer'
        button.style.opacity = '1'
        clearing = false
        return
      }

      if (token && !serverLogoutSucceeded) {
        window.alert('Penyimpanan browser sudah dibersihkan, tetapi sesi server tidak dapat dikonfirmasi untuk dicabut. Jika sesi masih aktif di perangkat lain, hubungi administrator.')
      } else {
        window.alert('Cache dan penyimpanan ASC sudah dibersihkan. Silakan masuk kembali.')
      }
      window.location.assign('/login')
    })

    header.appendChild(button)
  }

  new MutationObserver(mountButton).observe(document.body, { childList: true, subtree: true })
  window.addEventListener('popstate', mountButton)
  mountButton()
})()
