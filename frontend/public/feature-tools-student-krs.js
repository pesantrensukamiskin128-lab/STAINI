(() => {
  const apiBase = '/api'
  const path = '/akademik/krs-saya'
  let scheduled = false
  let loading = false
  let cacheExpires = 0
  let currentToken = null
  let hasCache = false
  let currentKrs = null

  async function request(url, token) {
    const response = await fetch(`${apiBase}${url}`, {
      headers: {
        Accept: 'application/json',
        Authorization: `Bearer ${token}`,
      },
    })
    if (!response.ok) throw new Error(`KRS status request failed with HTTP ${response.status}.`)
    return response.json()
  }

  async function fetchCurrentKrs(token) {
    const semestersResponse = await request('/semesters', token)
    const semesters = Array.isArray(semestersResponse)
      ? semestersResponse
      : (semestersResponse.data ?? [])
    const semester = semesters.find(item => item.is_active) ?? semesters[0]
    if (!semester?.id) return null

    const response = await request(`/krs?semester_id=${encodeURIComponent(semester.id)}&per_page=1`, token)
    const records = Array.isArray(response) ? response : (response.data ?? [])
    return records[0] ?? null
  }

  function updateStudentStatus(krs) {
    if (!krs || krs.advisor_id || krs.advisor?.id) return
    const paragraphs = [...document.querySelectorAll('#app p')]
    if (krs.status === 'SUBMITTED') {
      const heading = paragraphs.find(node => node.textContent.trim() === 'Menunggu Persetujuan Dosen Wali')
      if (heading) heading.textContent = 'Menunggu Review Program Studi'
      const message = paragraphs.find(node => node.textContent.trim() === 'KRS Anda sedang direview. Tunggu notifikasi.')
      if (message) message.textContent = 'KRS Anda belum memiliki Dosen Wali dan menunggu review Kaprodi/Sekretaris Prodi.'
      return
    }

    if (krs.status === 'APPROVED') {
      const heading = paragraphs.find(node => node.textContent.trim() === 'KRS Disetujui ✓')
      const message = paragraphs.find(node => node.textContent.includes('Disetujui oleh dosen wali. Total '))
      if (krs.kaprodi_approved_at) {
        if (heading) heading.textContent = 'KRS Disetujui Prodi ✓'
        if (message) {
          message.textContent = `Disetujui langsung oleh program studi karena belum memiliki Dosen Wali. Menunggu tanda tangan Kaprodi. Total ${krs.total_credits} SKS.`
        }
      } else {
        if (heading) heading.textContent = 'Menunggu Persetujuan Prodi'
        if (message) message.textContent = 'KRS belum memiliki Dosen Wali dan menunggu persetujuan program studi.'
      }
    }
  }

  async function refresh() {
    if (location.pathname.replace(/\/+$/, '') !== path) return
    const token = localStorage.getItem('auth_token')
    if (!token) return
    if (token !== currentToken) {
      currentToken = token
      currentKrs = null
      cacheExpires = 0
      hasCache = false
    }
    if (hasCache && Date.now() < cacheExpires) {
      updateStudentStatus(currentKrs)
      return
    }
    if (loading) return

    loading = true
    try {
      currentKrs = await fetchCurrentKrs(token)
      hasCache = true
      cacheExpires = Date.now() + 15000
      updateStudentStatus(currentKrs)
    } catch (error) {
      console.error('Gagal memperbarui status persetujuan KRS mahasiswa.', error)
    } finally {
      loading = false
    }
  }

  function scheduleRefresh() {
    if (scheduled) return
    scheduled = true
    window.setTimeout(() => {
      scheduled = false
      void refresh()
    }, 250)
  }

  new MutationObserver(scheduleRefresh).observe(document.body, { childList: true, subtree: true })
  window.addEventListener('focus', scheduleRefresh)
  window.addEventListener('popstate', scheduleRefresh)
  scheduleRefresh()
})()
