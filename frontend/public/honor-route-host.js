import { W as defineComponent, U as createVNode, X as onMounted, Y as onBeforeUnmount } from './api-DeqLARHl.js'

export default defineComponent({
  setup() {
    let hostElement = null
    let embeddedElement = null
    let unmountEmbeddedApp = null
    let isUnmounted = false

    function loadHonorStyles() {
      if (document.getElementById('asc-honor-embedded-styles')) return
      const stylesheet = document.createElement('link')
      stylesheet.id = 'asc-honor-embedded-styles'
      stylesheet.rel = 'stylesheet'
      stylesheet.href = '/assets/honor-embedded-20261009-journal-review.css'
      document.head.appendChild(stylesheet)
    }

    const attach = (element) => {
      hostElement = element
    }

    onMounted(async () => {
      const host = hostElement
      if (!host) throw new Error('Honor route host was not mounted.')
      loadHonorStyles()
      const honorPage = window.location.pathname.replace(/\/+$/, '').endsWith('/honor-saya')
        ? 'mine'
        : new URLSearchParams(window.location.search).get('view') === 'journals' ? 'review' : 'manage'
      const element = document.createElement('div')
      element.className = 'honor-embedded-root'
      embeddedElement = element
      host.appendChild(element)
      const { mount } = await import('./honor-embedded-20261009-journal-review.js')
      if (!isUnmounted && host.isConnected && embeddedElement === element && element.isConnected) {
        unmountEmbeddedApp = mount(element, honorPage)
      } else {
        element.remove()
      }
    })

    onBeforeUnmount(() => {
      isUnmounted = true
      unmountEmbeddedApp?.()
      unmountEmbeddedApp = null
      embeddedElement?.remove()
      embeddedElement = null
      hostElement = null
    })

    return () => createVNode('div', {
      class: 'honor-route-host min-h-64',
      ref: attach,
    })
  },
})
