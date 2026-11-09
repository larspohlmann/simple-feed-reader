export function refreshParams(refresh: boolean): Record<string, string> {
  return refresh ? { refresh: '1' } : {};
}
