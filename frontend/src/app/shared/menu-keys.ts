/** role=menu's keyboard contract: Arrow keys walk the container's items, wrapping. */
export function stepMenuFocus(event: KeyboardEvent, container: Element): void {
  const step = event.key === 'ArrowDown' ? 1 : event.key === 'ArrowUp' ? -1 : 0;
  if (step === 0) return;
  event.preventDefault();
  const items = Array.from(container.querySelectorAll<HTMLElement>('[role="menuitem"]'));
  if (items.length === 0) return;
  const active = items.indexOf(document.activeElement as HTMLElement);
  // From outside the items, ArrowDown enters at the first, ArrowUp at the last.
  const from = active === -1 ? (step === 1 ? -1 : 0) : active;
  items[(from + step + items.length) % items.length].focus();
}
