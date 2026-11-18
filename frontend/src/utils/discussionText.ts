export interface DiscussionTextPart {
  text: string
  href?: string
}

export function discussionTextParts(text: string): DiscussionTextPart[] {
  const parts: DiscussionTextPart[] = []
  const urlPattern = /(?:https?:\/\/|www\.)[^\s<>"']+/gi
  let lastIndex = 0

  for (const match of text.matchAll(urlPattern)) {
    const rawUrl = match[0]
    const trailingPunctuation = rawUrl.match(/[.,!?;:)}\]]+$/)?.[0] ?? ''
    const url = rawUrl.slice(0, rawUrl.length - trailingPunctuation.length)
    const start = match.index ?? 0

    if (start > lastIndex) parts.push({ text: text.slice(lastIndex, start) })
    if (url) {
      parts.push({
        text: url,
        href: url.startsWith('www.') ? `https://${url}` : url,
      })
    }
    if (trailingPunctuation) parts.push({ text: trailingPunctuation })
    lastIndex = start + rawUrl.length
  }

  if (lastIndex < text.length) parts.push({ text: text.slice(lastIndex) })
  return parts
}
