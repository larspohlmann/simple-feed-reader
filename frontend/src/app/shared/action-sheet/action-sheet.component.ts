import { ChangeDetectionStrategy, Component, ElementRef, inject, viewChild } from '@angular/core';
import { DIALOG_DATA, DialogRef } from '@angular/cdk/dialog';
import { TranslocoPipe } from '@jsverse/transloco';
import { IconComponent } from '../icon/icon.component';
import { IconButtonDirective } from '../icon-button/icon-button.directive';
import { MaskedLogoComponent } from '../masked-logo/masked-logo.component';
import { stepMenuFocus } from '../menu-keys';

export interface ActionSheetLogo {
  url: string;
  color?: string;
}

/** One choice on the sheet. `label` arrives already translated — this lives in
 *  shared/ and must not know any feature's i18n keys. */
export interface ActionSheetAction {
  id: string;
  label: string;
  symbol?: string;
  logo?: ActionSheetLogo;
  danger?: boolean;
  disabled?: boolean;
  /** A second line under the label, already translated: why a disabled action is unavailable. */
  hint?: string;
}

export const ACTION_SHEET_DIVIDER = { divider: true } as const;
export type ActionSheetDivider = typeof ACTION_SHEET_DIVIDER;
export type ActionSheetItem = ActionSheetAction | ActionSheetDivider;

/** What the sheet shows: the row it acts on, and that row's actions. */
export interface ActionSheetData {
  title: string;
  actions: readonly ActionSheetItem[];
}

@Component({
  selector: 'app-action-sheet',
  imports: [TranslocoPipe, IconComponent, IconButtonDirective, MaskedLogoComponent],
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
  private readonly menu = viewChild.required<ElementRef<HTMLElement>>('menu');

  private startY = 0;
  private dy = 0;
  private pulling = false;

  protected isDivider(item: ActionSheetItem): item is ActionSheetDivider {
    return 'divider' in item;
  }

  onKeydown(event: KeyboardEvent): void {
    stepMenuFocus(event, this.host.nativeElement);
  }

  /** A list scrolled away from its top is being scrolled back, not pulled down. */
  onTouchStart(event: TouchEvent): void {
    if (event.touches.length !== 1) return;
    this.startY = event.touches[0].clientY;
    this.dy = 0;
    this.pulling = this.menu().nativeElement.scrollTop === 0;
  }

  onTouchMove(event: TouchEvent): void {
    if (event.touches.length === 1) this.dy = event.touches[0].clientY - this.startY;
  }

  /** A decisive downward pull dismisses, mirroring the sheet's slide-up entry.
   *  60px keeps a scroll-ish wobble from closing it. */
  onTouchEnd(): void {
    if (this.pulling && this.dy > 60) this.ref.close();
  }
}
