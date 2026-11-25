export function matchesAccept(file: File, accept: string): boolean {
  const entries = accept
    .split(',')
    .map((entry) => entry.trim().toLowerCase())
    .filter((entry) => entry !== '');
  if (entries.length === 0) return true;
  const name = file.name.toLowerCase();
  const type = file.type.toLowerCase();
  return entries.some((entry) => (entry.startsWith('.') ? name.endsWith(entry) : entry === type));
}
