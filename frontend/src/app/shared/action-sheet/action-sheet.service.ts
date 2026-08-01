// src/app/shared/action-sheet/action-sheet.service.ts
import { Injectable, inject } from '@angular/core';
import { Dialog } from '@angular/cdk/dialog';
import { Overlay } from '@angular/cdk/overlay';
import { Observable } from 'rxjs';
import { ActionSheetComponent, ActionSheetData } from './action-sheet.component';

/**
 * The one row-menu surface for coarse pointers: a sheet pinned to the bottom of
 * the VIEWPORT, so it can never clip inside a drawer the way the old
 * right-anchored popover did (#185). Rendered through the CDK overlay because
 * the open drawer carries a transform, which would turn any position: fixed
 * descendant into a drawer-relative box.
 */
@Injectable({ providedIn: 'root' })
export class ActionSheet {
  private readonly dialog = inject(Dialog);
  private readonly overlay = inject(Overlay);

  /** Emits the chosen action id, or undefined when dismissed. */
  open(data: ActionSheetData): Observable<string | undefined> {
    return this.dialog.open<string>(ActionSheetComponent, {
      panelClass: 'app-action-sheet',
      positionStrategy: this.overlay.position().global().centerHorizontally().bottom('0'),
      width: 'min(100%, 30rem)',
      data,
    }).closed;
  }
}
