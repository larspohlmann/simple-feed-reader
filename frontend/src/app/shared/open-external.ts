/** Opens an address in a new tab that can neither reach back into the reader nor learn where it came from. */
export function openExternal(url: string): void {
  window.open(url, '_blank', 'noopener,noreferrer');
}
