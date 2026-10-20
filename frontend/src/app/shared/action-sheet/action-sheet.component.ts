import { ChangeDetectionStrategy, Component, ElementRef, inject } from '@angular/core';
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
}

export const ACTION_SHEET_DIVIDER = { divider: true } as const;
export type ActionSheetDivider = typeof ACTION_SHEET_DIVIDER;
export type ActionSheetItem = ActionSheetAction | ActionSheetDivider;

/** What the sheet shows: the row it acts on, and that row's actions with the dividers between their groups. */
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

  private startY = 0;
  private dy = 0;

  protected isDivider(item: ActionSheetItem): item is ActionSheetDivider {
    return 'divider' in item;
  }

  onKeydown(event: KeyboardEvent): void {
    stepMenuFocus(event, this.host.nativeElement);
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
