/** "Color=Red, Size=M" -> {Color: 'Red', Size: 'M'}. Null when a part is not `name=value`. Empty text is an empty object. */
export function parseOptions(text: string): Record<string, string> | null {
  const options: Record<string, string> = {}
  for (const part of text.split(',').map((p) => p.trim()).filter(Boolean)) {
    const [name, ...rest] = part.split('=')
    const value = rest.join('=').trim()
    if (!name?.trim() || !value) return null
    options[name.trim()] = value
  }

  return options
}

/** {Color: 'Red', Size: 'M'} -> "Color=Red, Size=M". */
export function optionsText(options: unknown): string {
  if (!options || typeof options !== 'object') return ''

  return Object.entries(options as Record<string, string>).map(([k, v]) => `${k}=${v}`).join(', ')
}
