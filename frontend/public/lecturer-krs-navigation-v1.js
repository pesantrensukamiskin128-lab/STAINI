(() => {
  const KRS_PATH = '/akademik/krs'

  function patchLecturerNavigation() {
    const aside = document.querySelector('aside')
    const role = aside?.lastElementChild?.querySelector('p:last-child')?.textContent?.trim()
    if (role !== 'DOSEN') return

    const nav = aside.querySelector('nav')
    const groups = [...(nav?.querySelectorAll('details') ?? [])]
    const guidance = groups.find((group) => group.querySelector('summary')?.textContent?.trim() === 'Bimbingan Akademik')
    const krsLink = nav?.querySelector(`a[href="${KRS_PATH}"]`)
    const dashboardLink = guidance?.querySelector('a[href="/bimbingan"]')

    if (krsLink && dashboardLink) {
      if (krsLink.textContent?.trim() !== 'Validasi Ajuan KRS') krsLink.textContent = 'Validasi Ajuan KRS'
      if (krsLink.parentElement !== dashboardLink.parentElement || dashboardLink.nextElementSibling !== krsLink) {
        dashboardLink.after(krsLink)
      }
      if (location.pathname.startsWith(KRS_PATH)) guidance.open = true
    }

    if (location.pathname === '/dashboard') {
      for (const label of document.querySelectorAll('button span')) {
        if (label.textContent?.trim() === 'Perwalian KRS') label.textContent = 'Validasi KRS'
      }
    }
  }

  const observer = new MutationObserver(patchLecturerNavigation)
  observer.observe(document.documentElement, { childList: true, characterData: true, subtree: true })
  window.addEventListener('popstate', patchLecturerNavigation)
  patchLecturerNavigation()
})()
