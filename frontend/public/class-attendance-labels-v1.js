(() => {
  const replacements = [
    ['Peserta Kelas PDF', 'Presensi Kelas PDF'],
    ['Peserta Kelas Excel', 'Presensi Kelas Excel'],
    ['Daftar Peserta Kelas', 'Presensi Kelas'],
  ]

  function replaceText(node) {
    let value = node.nodeValue ?? ''
    for (const [from, to] of replacements) {
      value = value.replaceAll(from, to)
    }
    if (value !== node.nodeValue) node.nodeValue = value
  }

  function updateText(root) {
    const walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT)
    let node = walker.nextNode()
    while (node) {
      replaceText(node)
      node = walker.nextNode()
    }
  }

  function updateNode(node) {
    if (node.nodeType === Node.TEXT_NODE) {
      replaceText(node)
    } else if (node instanceof Element) {
      updateText(node)
    }
  }

  updateText(document.body)
  const observer = new MutationObserver((records) => {
    for (const record of records) {
      if (record.type === 'characterData') {
        updateNode(record.target)
      } else {
        for (const node of record.addedNodes) updateNode(node)
      }
    }
  })
  observer.observe(document.body, { childList: true, characterData: true, subtree: true })
})()
