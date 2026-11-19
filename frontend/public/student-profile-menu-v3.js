(() => {
  const apiUrl = '/api/auth/me'
  const styleId = 'asc-student-profile-menu-style'
  const navId = 'asc-student-profile-menu-nav'
  const menuId = 'asc-student-profile-biodata-menu'
  const panelId = 'asc-student-profile-biodata-panel'
  let token = null
  let checkedToken = null
  let isStudent = false
  let checkingAccess = false
  let selectedMenu = ''
  let wasProfilePage = false

  function addStyles() {
    if (document.getElementById(styleId)) return
    const style = document.createElement('style')
    style.id = styleId
    style.textContent = `
      #asc-student-profile-menu-layout{display:grid!important;grid-template-columns:220px minmax(0,1fr);align-items:start}
      #${navId}{display:flex!important;flex-direction:column;gap:4px;padding:12px;border:0!important;border-right:1px solid #e5e7eb!important}
      #${navId}>button{display:flex;width:100%;align-items:center;gap:12px;padding:10px 12px;border:0;border-radius:8px;text-align:left;font-size:14px;font-weight:500;cursor:pointer}
      #${navId}>button[aria-current="page"]{background:#eff6ff;color:#1d4ed8}
      #${navId}>button:not([aria-current="page"]){background:transparent;color:#4b5563}
      #asc-student-profile-biodata-panel{min-width:0;padding:20px}
      #asc-student-profile-biodata-frame{display:block;width:100%;min-height:760px;border:0;background:white}
      #asc-student-profile-menu-layout .asc-profile-menu-hidden{display:none!important}
      #asc-student-biodata-link{display:none!important}
      @media(max-width:767px){
        #asc-student-profile-menu-layout{grid-template-columns:minmax(0,1fr)}
        #${navId}{flex-direction:row;flex-wrap:wrap;border-right:0!important;border-bottom:1px solid #e5e7eb!important}
        #${navId}>button{width:auto;flex:1 1 140px}
        #asc-student-profile-biodata-panel{padding:12px}
        #asc-student-profile-biodata-frame{min-height:80vh}
      }
    `
    document.head.append(style)
  }

  function setActiveMenu(nav, menu) {
    nav.querySelectorAll('button').forEach((button) => {
      const active = button.dataset.profileMenu === menu || (menu === 'biodata' && button.id === menuId)
      if (active) button.setAttribute('aria-current', 'page')
      else button.removeAttribute('aria-current')
    })
  }

  function showExistingTab(layout, nav, panel, menu) {
    selectedMenu = menu
    panel.classList.add('asc-profile-menu-hidden')
    layout.querySelectorAll(`:scope > :not(#${navId})`).forEach((content) => {
      content.classList.remove('asc-profile-menu-hidden')
    })
    setActiveMenu(nav, menu)
  }

  function prepareEmbeddedPortal(panel) {
    const frame = panel.querySelector('iframe')
    if (!frame || frame.dataset.styled === 'true') return
    frame.dataset.styled = 'true'
    frame.addEventListener('load', () => {
      try {
        const documentInFrame = frame.contentDocument
        if (!documentInFrame || documentInFrame.getElementById('asc-profile-embedded-style')) return
        const style = documentInFrame.createElement('style')
        style.id = 'asc-profile-embedded-style'
        style.textContent = `
          html,body,#app{min-height:0!important;background:transparent!important}
          #app>main{min-height:0!important;padding:0!important;background:transparent!important}
          #app>main>div>header{display:none!important}
          #app>main>div>section{border:0!important;box-shadow:none!important}
        `
        documentInFrame.head.append(style)
      } catch (error) {
        console.error('Tampilan biodata tertanam tidak dapat disesuaikan.', error)
      }
    })
  }

  function renderProfileMenu() {
    const link = document.getElementById('asc-student-biodata-link')
    if (link) link.style.display = 'none'
    const onProfilePage = window.location.pathname.replace(/\/+$/, '') === '/profile'
    if (!onProfilePage) {
      wasProfilePage = false
      return
    }
    if (!wasProfilePage) selectedMenu = ''
    wasProfilePage = true
    if (!isStudent) return

    const infoButton = [...document.querySelectorAll('button')]
      .find((button) => button.textContent.trim() === 'Informasi Akun')
    const passwordButton = [...document.querySelectorAll('button')]
      .find((button) => button.textContent.trim() === 'Ubah Password')
    const nav = infoButton?.parentElement
    const layout = nav?.parentElement
    if (!nav || !layout || !infoButton || !passwordButton) return

    addStyles()
    layout.id = 'asc-student-profile-menu-layout'
    nav.id = navId
    nav.setAttribute('aria-label', 'Menu profil mahasiswa')
    const root = layout.parentElement
    if (root instanceof HTMLElement) root.style.maxWidth = '72rem'
    infoButton.dataset.profileMenu = 'info'
    passwordButton.dataset.profileMenu = 'password'

    let biodataButton = document.getElementById(menuId)
    if (!biodataButton) {
      biodataButton = document.createElement('button')
      biodataButton.id = menuId
      biodataButton.type = 'button'
      biodataButton.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5" aria-hidden="true" class="h-5 w-5 shrink-0"><path stroke-linecap="round" stroke-linejoin="round" d="M12 14.25c4.142 0 7.5-1.678 7.5-3.75S16.142 6.75 12 6.75 4.5 8.428 4.5 10.5s3.358 3.75 7.5 3.75Z"/><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 10.5v3.75C4.5 16.322 7.858 18 12 18s7.5-1.678 7.5-3.75V10.5M4.5 14.25V18c0 2.072 3.358 3.75 7.5 3.75s7.5-1.678 7.5-3.75v-3.75M19.5 10.5V6.75C19.5 4.678 16.142 3 12 3S4.5 4.678 4.5 6.75v3.75"/></svg><span>Biodata Mahasiswa</span>'
      biodataButton.setAttribute('aria-label', 'Buka Biodata Mahasiswa')
      biodataButton.addEventListener('click', () => {
        selectedMenu = 'biodata'
        const panel = document.getElementById(panelId)
        if (panel) {
          layout.querySelectorAll(`:scope > :not(#${navId})`).forEach((content) =>
            content.classList.add('asc-profile-menu-hidden'),
          )
          panel.classList.remove('asc-profile-menu-hidden')
          setActiveMenu(nav, 'biodata')
        }
      })
      infoButton.insertAdjacentElement('afterend', biodataButton)
    }

    let panel = document.getElementById(panelId)
    if (!panel) {
      panel = document.createElement('div')
      panel.id = panelId
      panel.classList.add('asc-profile-menu-hidden')
      const frame = document.createElement('iframe')
      frame.id = 'asc-student-profile-biodata-frame'
      frame.title = 'Form edit biodata mahasiswa'
      frame.src = '/biodata/'
      panel.append(frame)
      layout.append(panel)
    }
    prepareEmbeddedPortal(panel)

    if (!nav.dataset.profileMenuBound) {
      nav.dataset.profileMenuBound = 'true'
      nav.addEventListener('click', (event) => {
        const button = event.target instanceof Element ? event.target.closest('button') : null
        if (!button || button.id === menuId) return
        if (button === infoButton) showExistingTab(layout, nav, panel, 'info')
        if (button === passwordButton) showExistingTab(layout, nav, panel, 'password')
      })
    }

    if (selectedMenu === 'biodata') {
      layout.querySelectorAll(`:scope > :not(#${navId})`).forEach((content) =>
        content.classList.toggle('asc-profile-menu-hidden', content !== panel),
      )
      setActiveMenu(nav, 'biodata')
    } else {
      const active = infoButton.className.includes('border-blue-600') ? 'info' : 'password'
      selectedMenu = active
      layout.querySelectorAll(`:scope > :not(#${navId})`).forEach((content) => {
        content.classList.toggle('asc-profile-menu-hidden', content !== panel && !content.classList.contains('p-6'))
      })
      panel.classList.add('asc-profile-menu-hidden')
      setActiveMenu(nav, active)
    }
  }

  async function checkAccess() {
    const currentToken = window.localStorage.getItem('auth_token')
    if (currentToken !== token) {
      token = currentToken
      checkedToken = null
      isStudent = false
      selectedMenu = ''
    }
    if (!token || checkedToken === token || checkingAccess) {
      renderProfileMenu()
      return
    }

    checkingAccess = true
    checkedToken = token
    try {
      const response = await fetch(apiUrl, {
        headers: { Accept: 'application/json', Authorization: `Bearer ${token}` },
      })
      if (response.ok) {
        const profile = await response.json()
        isStudent = Array.isArray(profile.roles) && profile.roles.includes('MAHASISWA')
      } else if (response.status >= 500) {
        checkedToken = null
        console.error(`Pemeriksaan akses biodata gagal (${response.status}).`)
      }
    } catch (error) {
      checkedToken = null
      console.error('Gagal memeriksa akses menu biodata mahasiswa.', error)
    } finally {
      checkingAccess = false
      renderProfileMenu()
    }
  }

  const observer = new MutationObserver(renderProfileMenu)
  observer.observe(document.documentElement, { childList: true, subtree: true })
  window.addEventListener('storage', checkAccess)
  window.setInterval(() => {
    checkAccess()
    renderProfileMenu()
  }, 1000)
})()
