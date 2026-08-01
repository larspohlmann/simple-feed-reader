// src/app/shared/action-sheet/action-sheet.component.ts
import { ChangeDetectionStrategy, Component, ElementRef, inject } from '@angular/core';
import { DIALOG_DATA, DialogRef } from '@angular/cdk/dialog';

/** One choice on the sheet. `label` arrives already translated — this lives in
 *  shared/ and must not know any feature's i18n keys. */
export interface ActionSheetAction {
  id: string;
  label: string;
  danger?: boolean;
}

/** What the sheet shows: the row it acts on, and that row's actions. */
export interface ActionSheetData {
  title: string;
  actions: ActionSheetAction[];
}

@Component({
  selector: 'app-action-sheet',
  templateUrl: './action-sheet.component.html',
  styleUrl: './action-sheet.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
  host: {
    '(touchstart)': 'onTouchStart($event)',
    '(touchmove)': 'onTouchMove($event)',
    '(touchend)': 'onTouchEnd()',
    '(keydown)': 'onKeydown($event)',
  },
})
export class ActionSheetComponent {
  readonly data = inject<ActionSheetData>(DIALOG_DATA);
  readonly ref = inject<DialogRef<string>>(DialogRef);
  private readonly host = inject<ElementRef<HTMLElement>>(ElementRef);

  private startY = 0;
  private dy = 0;

  /** role=menu's keyboard contract: Arrow keys walk the items, wrapping. The
   *  CDK dialog already owns Tab (focus trap) and Escape (dismiss). */
  onKeydown(event: KeyboardEvent): void {
    const step = event.key === 'ArrowDown' ? 1 : event.key === 'ArrowUp' ? -1 : 0;
    if (step === 0) return;
    event.preventDefault();
    const items = Array.from(
      this.host.nativeElement.querySelectorAll<HTMLElement>('[role="menuitem"]'),
    );
    if (items.length === 0) return;
    const active = items.indexOf(document.activeElement as HTMLElement);
    // From outside the items, ArrowDown enters at the first, ArrowUp at the last.
    const from = active === -1 ? (step === 1 ? -1 : 0) : active;
    items[(from + step + items.length) % items.length].focus();
  }

  onTouchStart(event: TouchEvent): void {
    if (event.touches.length !== 1) return;
    this.startY = event.touches[0].clientY;
    this.dy = 0;
  }

  onTouchMove(event: TouchEvent): void {
    if (event.touches.length === 1) this.dy = event.touches[0].clientY - this.startY;
  }

  /** A decisive downward pull dismisses, mirroring the sheet's slide-up entry.
   *  60px keeps a scroll-ish wobble from closing it. */
  onTouchEnd(): void {
    if (this.dy > 60) this.ref.close();
  }
}
