(() => {
  const apiUrl = 'https://api-asc.aljawami.ac.id/api/auth/me'
  const canManageRoles = ['SUPER_ADMIN', 'ADMIN_KEUANGAN']
  const canViewRoles = [
    'ADMIN_KEUANGAN',
    'DOSEN',
    'ADMIN_UMUM',
    'ADMIN_AKADEMIK',
    'ADMIN_PMB',
    'ADMIN_DIGILIB',
  ]
  const menuIds = {
    manage: 'asc-honor-manage-link',
    mine: 'asc-honor-mine-link',
    review: 'asc-honor-journal-review-link',
  }
  let checkedToken = null
  let userRoles = []

  async function checkAccess() {
    const token = localStorage.getItem('auth_token')
    if (!token) {
      checkedToken = null
      userRoles = []
      removeLinks()
      return
    }
    if (checkedToken === token) {
      mountLinks()
      return
    }

    checkedToken = token
    userRoles = []
    removeLinks()
    try {
      const response = await fetch(apiUrl, {
        headers: { Accept: 'application/json', Authorization: `Bearer ${token}` },
      })
      if (!response.ok) {
        checkedToken = null
        return
      }
      const user = await response.json()
      userRoles = user.roles ?? []
      mountLinks()
    } catch (error) {
      checkedToken = null
      console.error('Gagal memeriksa akses menu honor.', error)
    }
  }

  function removeLinks() {
    Object.values(menuIds).forEach((id) => document.getElementById(id)?.remove())
  }

  function mountLinks() {
    if (userRoles.some(role => canManageRoles.includes(role))) mountManageLink()
    if (userRoles.some(role => canViewRoles.includes(role))) mountMineLink()
    if (userRoles.some(role => canManageRoles.includes(role))) mountReviewLink()
  }

  function mountManageLink() {
    if (document.getElementById(menuIds.manage)) return
    const financeDashboard = [...document.querySelectorAll('a[href="/keuangan"]')]
      .find(link => link.textContent.trim() === 'Dashboard')
    const container = financeDashboard?.parentElement
    if (!container) return

    const link = financeDashboard.cloneNode(false)
    link.id = menuIds.manage
    link.href = '/keuangan/honor'
    link.textContent = 'Kelola Honor'
    container.insertBefore(link, financeDashboard.nextSibling)
  }

  function mountMineLink() {
    if (document.getElementById(menuIds.mine)) return
    const libraryLink = [...document.querySelectorAll('a')]
      .find(link => link.textContent.trim() === 'Digital Library')
    if (!libraryLink) return

    const link = libraryLink.cloneNode(false)
    link.id = menuIds.mine
    link.href = '/keuangan/honor-saya'
    link.innerHTML = '<span class="h-5 w-5 shrink-0 flex items-center justify-center text-[10px] font-bold" aria-hidden="true">Rp</span><span>Honor Saya</span>'
    link.removeAttribute('target')
    libraryLink.insertAdjacentElement('afterend', link)
  }

  function mountReviewLink() {
    if (document.getElementById(menuIds.review)) return
    const manageLink = document.getElementById(menuIds.manage)
    if (!manageLink) return

    const link = manageLink.cloneNode(false)
    link.id = menuIds.review
    link.href = '/keuangan/honor?view=journals'
    link.textContent = 'Pemeriksaan Jurnal'
    manageLink.insertAdjacentElement('afterend', link)
  }

  new MutationObserver(() => {
    mountLinks()
    void checkAccess()
  }).observe(document.body, { childList: true, subtree: true })
  window.addEventListener('focus', () => { void checkAccess() })
  void checkAccess()
})()
