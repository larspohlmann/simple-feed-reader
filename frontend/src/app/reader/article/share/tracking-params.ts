const CLICK_IDS = new Set([
  'fbclid',
  'gclid',
  'dclid',
  'msclkid',
  'mc_eid',
  'mc_cid',
  'igshid',
  'yclid',
  '_hsenc',
  '_hsmi',
  'ref_src',
]);

function isTracking(key: string): boolean {
  const lower = key.toLowerCase();
  return lower.startsWith('utm_') || CLICK_IDS.has(lower);
}

export function stripTracking(url: string): string {
  if (!URL.canParse(url)) return url;
  const parsed = new URL(url);
  const tracking = [...parsed.searchParams.keys()].filter(isTracking);
  if (tracking.length === 0) return url;
  tracking.forEach((key) => parsed.searchParams.delete(key));
  return parsed.toString();
}
