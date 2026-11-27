import { Signal, linkedSignal } from '@angular/core';

/** A section's expanded state, remembered in localStorage as `'true'` under its key while collapsed. */
export interface PersistedToggle {
  readonly expanded: Signal<boolean>;
  toggle(): void;
  open(): void;
}

export type ToggleStart = 'expanded' | 'collapsed';

/** `start` until stored otherwise; follows a key that changes, and keeps working in memory without storage. */
export function persistedToggle(
  key: () => string,
  start: ToggleStart = 'expanded',
): PersistedToggle {
  const expanded = linkedSignal({
    source: key,
    computation: (current) => isExpanded(storedValue(current), start),
  });
  const toggle = (): void => {
    const open = !expanded();
    expanded.set(open);
    store(key(), String(!open));
  };
  return {
    expanded: expanded.asReadonly(),
    toggle,
    open: () => {
      if (!expanded()) toggle();
    },
  };
}

function isExpanded(stored: string | null, start: ToggleStart): boolean {
  return stored === null ? start === 'expanded' : stored !== 'true';
}

function storedValue(key: string): string | null {
  try {
    return localStorage.getItem(key);
  } catch {
    return null;
  }
}

function store(key: string, value: string): void {
  try {
    localStorage.setItem(key, value);
  } catch {
    // The toggle still works for this visit when browser storage is blocked.
  }
}
