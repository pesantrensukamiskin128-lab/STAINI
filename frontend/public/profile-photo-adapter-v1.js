(() => {
  const apiBase = 'https://api-asc.aljawami.ac.id/api'
  const photoUploadUrl = `${apiBase}/auth/profile/photo`
  const maxPhotoBytes = 2 * 1024 * 1024
  let activeToken = null
  let profile = null
  let lastPath = window.location.pathname
  let refreshPending = false

  const isAvatar = (element, size) =>
    element instanceof HTMLElement &&
    element.classList.contains('rounded-full') &&
    element.classList.contains(size)

  function updateAvatar(element) {
    if (!profile || !isAvatar(element, element.classList.contains('w-16') ? 'w-16' : 'w-7')) return
    if (element.dataset.profilePhotoFailed === profile.photo_url) return

    element.classList.add('overflow-hidden')
    element.style.overflow = 'hidden'
    const existingImage = element.querySelector('img[data-profile-photo]')
    if (profile.photo_url) {
      if (existingImage?.getAttribute('src') === profile.photo_url) return
      const image = document.createElement('img')
      image.dataset.profilePhoto = 'true'
      image.src = profile.photo_url
      image.alt = `Foto profil ${profile.name}`
      image.style.cssText = 'width:100%;height:100%;object-fit:cover'
      image.addEventListener('error', () => {
        element.dataset.profilePhotoFailed = profile.photo_url
        image.remove()
        const uploadButton = element.querySelector('button[data-profile-photo-upload]')
        element.insertBefore(document.createTextNode(profile.name.charAt(0).toUpperCase()), uploadButton)
      }, { once: true })
      const controls = [...element.querySelectorAll('[data-profile-photo-upload]')]
      element.replaceChildren(image, ...controls)
    } else if (existingImage) {
      element.textContent = profile.name.charAt(0).toUpperCase()
    }
  }

  function addUploadControl(avatar) {
    const container = avatar
    if (!container || container.querySelector('[data-profile-photo-upload]')) return

    container.style.position = 'relative'
    const input = document.createElement('input')
    input.type = 'file'
    input.accept = 'image/jpeg,image/png,image/webp'
    input.hidden = true
    input.dataset.profilePhotoUpload = 'input'

    const button = document.createElement('button')
    button.type = 'button'
    button.title = profile.photo_url ? 'Ganti foto profil' : 'Tambah foto profil'
    button.setAttribute('aria-label', button.title)
    button.dataset.profilePhotoUpload = 'true'
    button.className = 'absolute -bottom-1 -right-1 z-10 flex h-7 w-7 items-center justify-center rounded-full border-2 border-white bg-blue-600 text-white shadow hover:bg-blue-700'
    button.style.cssText = 'position:absolute;right:1px;bottom:1px;z-index:10;display:flex;width:24px;height:24px;align-items:center;justify-content:center;border:2px solid white;border-radius:9999px;background:#2563eb;color:white;box-shadow:0 1px 3px #0003;cursor:pointer'
    button.innerHTML = '<svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="h-4 w-4"><path stroke-linecap="round" stroke-linejoin="round" d="M4 7h3l1.5-2h7L17 7h3v12H4z"/><circle cx="12" cy="13" r="3"/></svg>'
    button.querySelector('svg').style.cssText = 'width:16px;height:16px'
    button.addEventListener('click', () => input.click())

    input.addEventListener('change', async () => {
      const photo = input.files?.[0]
      if (!photo || !activeToken) return
      if (!['image/jpeg', 'image/png', 'image/webp'].includes(photo.type) || photo.size > maxPhotoBytes) {
        window.alert('Pilih foto JPEG, PNG, atau WebP dengan ukuran maksimal 2 MB.')
        input.value = ''
        return
      }

      button.disabled = true
      button.title = 'Mengunggah foto...'
      try {
        const body = new FormData()
        body.append('photo', photo)
        const response = await fetch(photoUploadUrl, {
          method: 'POST',
          headers: { Authorization: `Bearer ${activeToken}`, Accept: 'application/json' },
          body,
        })
        const result = await response.json()
        if (!response.ok) throw new Error(result.message || 'Foto profil gagal diunggah.')
        profile = { ...profile, ...result.user }
        button.title = 'Ganti foto profil'
        button.setAttribute('aria-label', button.title)
        document.querySelectorAll('div.rounded-full.w-7, div.rounded-full.w-16').forEach(updateAvatar)
        window.alert(result.message || 'Foto profil berhasil diperbarui.')
      } catch (error) {
        window.alert(error instanceof Error ? error.message : 'Foto profil gagal diunggah.')
      } finally {
        button.disabled = false
        button.title = profile.photo_url ? 'Ganti foto profil' : 'Tambah foto profil'
        button.setAttribute('aria-label', button.title)
        input.value = ''
      }
    })

    container.append(input, button)
  }

  function refreshAvatars() {
    if (!profile) return

    document.querySelectorAll('div.rounded-full.w-7, div.rounded-full.w-16').forEach((avatar) => {
      updateAvatar(avatar)
      if (window.location.pathname === '/profile' && avatar.classList.contains('w-16')) {
        addUploadControl(avatar)
      }
    })
  }

  async function loadProfile(token) {
    if (!token || refreshPending) return
    refreshPending = true
    try {
      const response = await fetch(`${apiBase}/auth/me`, {
        headers: { Authorization: `Bearer ${token}`, Accept: 'application/json' },
      })
      if (!response.ok) return
      profile = await response.json()
      refreshAvatars()
    } catch (error) {
      console.error('Gagal memuat foto profil pengguna.', error)
    } finally {
      refreshPending = false
    }
  }

  const observer = new MutationObserver(() => refreshAvatars())
  observer.observe(document.documentElement, { childList: true, subtree: true })

  window.setInterval(() => {
    const token = window.localStorage.getItem('auth_token')
    const path = window.location.pathname
    if (token !== activeToken) {
      activeToken = token
      profile = null
      if (token) loadProfile(token)
    }
    if (path !== lastPath) lastPath = path
    refreshAvatars()
  }, 1000)
})()
