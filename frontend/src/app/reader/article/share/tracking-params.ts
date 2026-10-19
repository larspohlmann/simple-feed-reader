const TRACKING_PARAMS = new Set([
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
  return lower.startsWith('utm_') || TRACKING_PARAMS.has(lower);
}

function isTrackingPiece(piece: string): boolean {
  const key = piece.split('=')[0].replace(/\+/g, ' ');
  try {
    return isTracking(decodeURIComponent(key));
  } catch {
    return isTracking(key);
  }
}

export function stripTracking(url: string): string {
  let parsed: URL;
  try {
    parsed = new URL(url);
  } catch {
    return url;
  }
  if (parsed.search === '') return url;
  const pieces = parsed.search.slice(1).split('&');
  const kept = pieces.filter((piece) => !isTrackingPiece(piece));
  if (kept.length === pieces.length) return url;
  parsed.search = kept.join('&');
  return parsed.toString();
}
