import { Signal, computed, linkedSignal } from '@angular/core';
import { ToggleStart, persistedToggle } from './persisted-toggle';

/** A sidebar folder's open state: stored by its chevron, and opened without storing while one of its children is current. */
export interface FolderToggle {
  readonly expanded: Signal<boolean>;
  toggle(): void;
}

/**
 * `currentChild` is null or false while no child is current. The chevron closes a folder a child
 * opened until `currentChild` changes; leaving the child falls back to the stored state.
 */
export function folderToggle(
  key: () => string,
  currentChild: () => unknown,
  start: ToggleStart = 'collapsed',
): FolderToggle {
  const stored = persistedToggle(key, start);
  const child = computed(currentChild);
  const collapsedHere = linkedSignal({ source: child, computation: () => false });
  const expanded = computed(
    () => !collapsedHere() && (stored.expanded() || (child() !== null && child() !== false)),
  );
  return {
    expanded,
    toggle: () => {
      if (expanded()) {
        if (stored.expanded()) stored.toggle();
        collapsedHere.set(true);
        return;
      }
      collapsedHere.set(false);
      stored.open();
    },
  };
}
