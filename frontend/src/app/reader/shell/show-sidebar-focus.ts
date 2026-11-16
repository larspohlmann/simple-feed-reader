import { ElementRef, Signal, afterRenderEffect } from '@angular/core';

/** When the sidebar collapses, focus the "Show sidebar" button so a keyboard user who clicked "Hide
 *  sidebar" is not dropped to `<body>`. Armed only once the sidebar has been visible, so the very
 *  first render does not pull focus onto the (absent) button. Call from an injection context. */
export function focusShowSidebarButtonOnCollapse(
  collapsed: Signal<boolean>,
  button: Signal<ElementRef<HTMLButtonElement> | undefined>,
): void {
  let wasVisible = false;
  afterRenderEffect(() => {
    if (!collapsed()) {
      wasVisible = true;
      return;
    }
    if (!wasVisible) return;
    wasVisible = false;
    button()?.nativeElement.focus({ preventScroll: true });
  });
}
