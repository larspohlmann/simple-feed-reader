import { Signal, afterRenderEffect } from '@angular/core';
import { PAGE_PINNED_CLASS } from '../../shared/scroll-host/page-layer';

/** When the sidebar collapses, focus the "Show sidebar" button so a keyboard user who clicked "Hide
 *  sidebar" is not dropped to `<body>`. Armed only once the sidebar has been visible, so the very
 *  first render does not pull focus onto the (absent) button. Of the shell's buttons it is the one on
 *  the layer the reader sees, not one in a list a story's covers (#2329). Call from an injection context. */
export function focusShowSidebarButtonOnCollapse(
  collapsed: Signal<boolean>,
  shell: HTMLElement,
): void {
  let wasVisible = false;
  afterRenderEffect(() => {
    if (!collapsed()) {
      wasVisible = true;
      return;
    }
    if (!wasVisible) return;
    wasVisible = false;
    visibleButton(shell)?.focus({ preventScroll: true });
  });
}

function visibleButton(shell: HTMLElement): HTMLButtonElement | undefined {
  return [...shell.querySelectorAll<HTMLButtonElement>('.sidebar-show')].find(
    (button) => button.closest(`.${PAGE_PINNED_CLASS}`) === null,
  );
}
