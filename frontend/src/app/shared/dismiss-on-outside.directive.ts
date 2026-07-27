import { Directive, ElementRef, Renderer2, effect, inject, input, output } from '@angular/core';

/**
 * Dismisses an open popover when the pointer goes down anywhere outside it, or
 * when Escape is pressed.
 *
 * Put it on the wrapper that holds *both* the trigger and the popover: a press
 * on the trigger then counts as inside, so the trigger's own toggle closes the
 * menu instead of fighting a dismiss that already fired on the way down.
 *
 * It listens on `pointerdown` rather than `click` so the menu is gone before the
 * click lands, and only while open — the sidebar renders one of these per row,
 * and the closed ones must cost nothing.
 */
@Directive({
  selector: '[appDismissOnOutside]',
})
export class DismissOnOutsideDirective {
  /** Whether the popover is currently open; listening is bound to this. */
  readonly open = input.required<boolean>({ alias: 'appDismissOnOutside' });

  readonly dismiss = output<void>();

  private readonly host: HTMLElement = inject(ElementRef).nativeElement;
  private readonly renderer = inject(Renderer2);

  constructor() {
    effect((onCleanup) => {
      if (!this.open()) return;
      const unlisten = [
        this.renderer.listen('document', 'pointerdown', (event: Event) =>
          this.dismissUnlessInside(event),
        ),
        this.renderer.listen('document', 'keydown', (event: KeyboardEvent) =>
          this.dismissOnEscape(event),
        ),
      ];
      onCleanup(() => unlisten.forEach((stop) => stop()));
    });
  }

  private dismissUnlessInside(event: Event): void {
    const target = event.target;
    if (target instanceof Node && this.host.contains(target)) return;
    this.dismiss.emit();
  }

  private dismissOnEscape(event: KeyboardEvent): void {
    if (event.key !== 'Escape') return;
    this.dismiss.emit();
  }
}
