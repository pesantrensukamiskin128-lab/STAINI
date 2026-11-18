(() => {
  const apiBase = 'https://api-asc.aljawami.ac.id/api'
  const pagePath = '/akademik/krs-saya'
  let currentToken = null
  let classesById = new Map()
  let classesRequest = null
  let classesLoaded = false
  let scheduled = false

  async function request(url, token) {
    const response = await fetch(`${apiBase}${url}`, {
      headers: {
        Accept: 'application/json',
        Authorization: `Bearer ${token}`,
      },
    })
    if (!response.ok) throw new Error(`Class details request failed with HTTP ${response.status}.`)
    return response.json()
  }

  async function loadClasses(token) {
    if (classesRequest) return classesRequest

    classesRequest = (async () => {
      const semesterResponse = await request('/semesters', token)
      const semesters = Array.isArray(semesterResponse)
        ? semesterResponse
        : (semesterResponse.data ?? [])
      const semester = semesters.find(item => item.is_active) ?? semesters[0]
      if (!semester?.id) {
        classesLoaded = true
        return
      }

      const response = await request(
        `/classes?semester_id=${encodeURIComponent(semester.id)}&available_for_krs=1&per_page=100`,
        token,
      )
      const classes = Array.isArray(response) ? response : (response.data ?? [])
      classesById = new Map(classes.map(item => [String(item.id), item]))
      classesLoaded = true
    })()

    try {
      await classesRequest
    } finally {
      classesRequest = null
    }
  }

  function classOptionLabel(classItem) {
    const schedule = classItem.schedules?.find(item => item.is_active !== false)
      ?? classItem.schedules?.[0]
    const lecturer = schedule?.lecturer?.full_name ?? schedule?.lecturer?.name
      ?? classItem.lecturer?.full_name ?? classItem.lecturer?.name ?? '-'
    const scheduleText = schedule
      ? `${schedule.day} ${schedule.start_time?.slice(0, 5)}-${schedule.end_time?.slice(0, 5)}`
      : '-'
    const room = schedule?.room?.name ?? classItem.room?.name ?? '-'
    return `${classItem.name} — ${lecturer} · ${scheduleText} · ${room} (${classItem.members_count ?? 0}/${classItem.capacity ?? 0})`
  }

  function updateClassOptions() {
    const labels = [...document.querySelectorAll('#app label')]
    const classLabel = labels.find(label => label.textContent.trim() === 'Pilih Kelas')
    const select = classLabel?.parentElement?.querySelector('select')
    if (!select) return

    for (const option of select.options) {
      const classItem = classesById.get(option.value)
      if (classItem) {
        const label = classOptionLabel(classItem)
        if (option.textContent !== label) option.textContent = label
      }
    }
  }

  async function refresh() {
    if (location.pathname.replace(/\/+$/, '') !== pagePath) return
    const token = localStorage.getItem('auth_token')
    if (!token) return
    if (token !== currentToken) {
      currentToken = token
      classesById = new Map()
      classesRequest = null
      classesLoaded = false
    }

    try {
      if (!classesLoaded) await loadClasses(token)
      updateClassOptions()
    } catch (error) {
      console.error('Gagal memuat jadwal dan ruangan kelas untuk pilihan KRS.', error)
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
