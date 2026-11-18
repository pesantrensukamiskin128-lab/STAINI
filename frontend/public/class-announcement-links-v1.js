(function () {
  'use strict'

  const urlPattern = /(?:https?:\/\/|www\.)[^\s<>"']+/gi

  function hasClasses(element, classes) {
    return classes.every((className) => element.classList.contains(className))
  }

  function isAnnouncementContent(paragraph) {
    if (hasClasses(paragraph, ['line-clamp-2', 'text-sm', 'text-gray-600'])) {
      const card = paragraph.parentElement
      return Boolean(
        card &&
          hasClasses(card, ['rounded-lg', 'bg-gray-50', 'p-3']) &&
          card.firstElementChild?.matches('p.font-semibold.text-gray-900') &&
          card.lastElementChild?.matches('p.text-xs.text-gray-400'),
      )
    }

    if (!hasClasses(paragraph, ['text-sm', 'text-gray-700', 'mt-1'])) return false

    const content = paragraph.parentElement
    const card = content?.parentElement?.parentElement
    return Boolean(
      content &&
        content.firstElementChild?.matches('p.font-semibold.text-gray-900') &&
        content.lastElementChild?.matches('p.text-xs.text-gray-400') &&
        card &&
        hasClasses(card, ['p-4', 'bg-white', 'rounded-xl', 'border']),
    )
  }

  function makeLink(text, url) {
    const href = url.startsWith('www.') ? `https://${url}` : url
    try {
      const parsed = new URL(href)
      if (parsed.protocol !== 'http:' && parsed.protocol !== 'https:') return null
    } catch {
      return null
    }

    const link = document.createElement('a')
    link.href = href
    link.target = '_blank'
    link.rel = 'noopener noreferrer'
    link.className = 'text-blue-700 underline break-all'
    link.textContent = text
    return link
  }

  function linkifyTextNode(node) {
    const text = node.nodeValue || ''
    if (!urlPattern.test(text)) {
      urlPattern.lastIndex = 0
      return false
    }
    urlPattern.lastIndex = 0

    const fragment = document.createDocumentFragment()
    let lastIndex = 0
    let changed = false

    for (const match of text.matchAll(urlPattern)) {
      const rawUrl = match[0]
      const trailingPunctuation = rawUrl.match(/[.,!?;:)}\]]+$/)?.[0] || ''
      const url = rawUrl.slice(0, rawUrl.length - trailingPunctuation.length)
      const start = match.index || 0
      const link = url ? makeLink(url, url) : null

      if (!link) continue
      if (start > lastIndex) fragment.append(document.createTextNode(text.slice(lastIndex, start)))
      fragment.append(link)
      if (trailingPunctuation) fragment.append(document.createTextNode(trailingPunctuation))
      lastIndex = start + rawUrl.length
      changed = true
    }

    if (!changed) return false
    if (lastIndex < text.length) fragment.append(document.createTextNode(text.slice(lastIndex)))
    node.replaceWith(fragment)
    return true
  }

  function linkifyParagraph(paragraph) {
    const walker = document.createTreeWalker(paragraph, NodeFilter.SHOW_TEXT)
    const textNodes = []
    while (walker.nextNode()) {
      if (!walker.currentNode.parentElement?.closest('a')) textNodes.push(walker.currentNode)
    }
    textNodes.forEach(linkifyTextNode)
  }

  function onClassDetailRoute() {
    return /\/perkuliahan\/[^/?#]+(?:[/?#]|$)/.test(`${location.pathname}${location.hash}`)
  }

  function scan() {
    if (!onClassDetailRoute()) return
    document.querySelectorAll('#app p').forEach((paragraph) => {
      if (isAnnouncementContent(paragraph)) linkifyParagraph(paragraph)
    })
  }

  let scanScheduled = false
  function scheduleScan() {
    if (scanScheduled) return
    scanScheduled = true
    queueMicrotask(() => {
      scanScheduled = false
      scan()
    })
  }

  const app = document.getElementById('app') || document.body
  new MutationObserver(scheduleScan).observe(app, { childList: true, subtree: true })
  window.addEventListener('hashchange', scheduleScan)
  window.addEventListener('popstate', scheduleScan)
  scan()
})()
