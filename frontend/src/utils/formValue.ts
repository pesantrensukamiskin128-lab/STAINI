/**
 * Normalize API date values for native HTML form controls.
 *
 * Laravel date casts can be serialized as either YYYY-MM-DD or a full ISO
 * timestamp. Native date inputs only accept the former and silently render an
 * invalid value as an empty field.
 */
export function toDateInput(value: unknown): string {
  if (value === null || value === undefined || value === '') return ''

  const text = String(value).trim()
  const plain = text.match(/^(\d{4}-\d{2}-\d{2})$/)
  if (plain) return plain[1]

  // Timestamp with timezone (e.g. 2026-11-01T17:00:00.000000Z): use the local calendar date.
  if (/^\d{4}-\d{2}-\d{2}T.*(Z|[+-]\d{2}:?\d{2})$/.test(text)) {
    const parsed = new Date(text)
    if (!Number.isNaN(parsed.getTime())) {
      const pad = (n: number) => String(n).padStart(2, '0')
      return `${parsed.getFullYear()}-${pad(parsed.getMonth() + 1)}-${pad(parsed.getDate())}`
    }
  }

  const datePart = text.match(/^(\d{4}-\d{2}-\d{2})/)

  return datePart?.[1] ?? ''
}

/** Normalize an API timestamp for an input[type="datetime-local"]. */
export function toDateTimeLocalInput(value: unknown): string {
  if (value === null || value === undefined || value === '') return ''

  const text = String(value).trim()
  const dateTimePart = text.match(/^(\d{4}-\d{2}-\d{2})[T ](\d{2}:\d{2})/)

  return dateTimePart ? `${dateTimePart[1]}T${dateTimePart[2]}` : ''
}
