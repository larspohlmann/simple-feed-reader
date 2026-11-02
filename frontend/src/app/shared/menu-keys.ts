import { ConnectedPosition } from '@angular/cdk/overlay';

/** A popover menu under its toolbar button, flush with its end edge, else above it. */
export const TOOLBAR_MENU_POSITIONS: ConnectedPosition[] = [
  { originX: 'end', originY: 'bottom', overlayX: 'end', overlayY: 'top', offsetY: 6 },
  { originX: 'end', originY: 'top', overlayX: 'end', overlayY: 'bottom', offsetY: -6 },
];

/** Steps focus through the role=menu the key was pressed in. */
export function stepMenu(event: KeyboardEvent): void {
  const menu = (event.target as Element).closest('[role="menu"]');
  if (menu !== null) stepMenuFocus(event, menu);
}

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
