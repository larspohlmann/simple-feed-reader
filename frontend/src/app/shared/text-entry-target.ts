/** Elements a bare-key shortcut must type into rather than steal from. Matches the
 *  event target itself, so a key typed inside a field always reaches the field. */
export function isTextEntryTarget(target: EventTarget | null): boolean {
  if (!(target instanceof HTMLElement)) return false;
  return target.tagName === 'INPUT' || target.tagName === 'TEXTAREA' || target.isContentEditable;
}
