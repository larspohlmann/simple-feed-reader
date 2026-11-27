import { Injectable, Injector, computed, inject, signal } from '@angular/core';
import { Dialog } from '@angular/cdk/dialog';
import { Overlay, OverlayRef } from '@angular/cdk/overlay';
import { ComponentPortal } from '@angular/cdk/portal';
import { TranslocoService } from '@jsverse/transloco';
import { filter, finalize, merge, take, takeUntil } from 'rxjs';
import {
  ACTION_SHEET_DIVIDER,
  ActionSheetCheck,
  ActionSheetItem,
} from '../../shared/action-sheet/action-sheet.component';
import { ActionSheet } from '../../shared/action-sheet/action-sheet.service';
import { MENU_VIEWPORT_MARGIN, TOOLBAR_MENU_POSITIONS } from '../../shared/menu-keys';
import { EntryActionHandler } from '../entry/entry-actions/entry-action-handler';
import { LayoutService } from '../layout.service';
import { EntryDto } from '../models';
import { EntryRowCache } from '../state/entry-row-cache';
import { LabelMenuPanelComponent } from './label-menu-panel.component';
import { LabelledRow, toLabelPill } from './labelled-row';
import { LabelsStore } from './labels.store';
import { NewLabelDialogComponent } from './new-label-dialog.component';

const NEW_LABEL = 'new';

/** Puts labels on an entry and takes them off: a popover at the trigger on a fine pointer, the action sheet on a
 *  coarse one. The caller hands in its `EntryActionHandler`, since the menu renders outside the trigger's injector. */
@Injectable({ providedIn: 'root' })
export class LabelMenu {
  private readonly overlay = inject(Overlay);
  private readonly sheet = inject(ActionSheet);
  private readonly dialog = inject(Dialog);
  private readonly layout = inject(LayoutService);
  private readonly labels = inject(LabelsStore);
  private readonly rows = inject(EntryRowCache);
  private readonly transloco = inject(TranslocoService);
  private readonly injector = inject(Injector);
  private readonly openAnchor = signal<HTMLElement | null>(null);
  private popover: OverlayRef | null = null;

  open(entry: EntryDto, anchor: HTMLElement, actions: EntryActionHandler): void {
    this.popover?.dispose();
    const row = new LabelledRow(this.rows, entry, actions);
    this.openAnchor.set(anchor);
    if (this.layout.isCoarse()) this.openSheet(row);
    else this.openPopover(row, anchor);
  }

  isOpenAt(anchor: HTMLElement): boolean {
    return this.openAnchor() === anchor;
  }

  /** Closes the popover when its trigger lies within `host`, which is leaving the page: a row that leaves its label's
   *  list takes its menu with it rather than leaving it floating at nothing. */
  closeWithin(host: HTMLElement): void {
    const anchor = this.openAnchor();
    if (anchor !== null && host.contains(anchor)) this.popover?.dispose();
  }

  private openPopover(row: LabelledRow, anchor: HTMLElement): void {
    const popover = this.overlay.create({
      positionStrategy: this.overlay
        .position()
        .flexibleConnectedTo(anchor)
        .withPositions(TOOLBAR_MENU_POSITIONS)
        .withPush(true)
        .withViewportMargin(MENU_VIEWPORT_MARGIN),
      scrollStrategy: this.overlay.scrollStrategies.reposition(),
      hasBackdrop: true,
      backdropClass: 'cdk-overlay-transparent-backdrop',
      disposeOnNavigation: true,
    });
    popover
      .attach(new ComponentPortal(LabelMenuPanelComponent, null, this.injector))
      .setInput('row', row);
    this.popover = popover;
    merge(
      popover.backdropClick(),
      popover.keydownEvents().pipe(filter((event) => event.key === 'Escape')),
    )
      .pipe(takeUntil(popover.detachments()))
      .subscribe(() => {
        popover.dispose();
        anchor.focus({ preventScroll: true });
      });
    popover
      .detachments()
      .pipe(take(1))
      .subscribe(() => this.forget(popover, anchor));
  }

  private openSheet(row: LabelledRow): void {
    const checks = this.labels.labels().map((tally): ActionSheetCheck => {
      const label = toLabelPill(tally);
      return {
        id: `label-${label.id}`,
        label: label.name,
        checked: computed(() => row.carries(label.id)),
        toggle: () => row.toggle(label),
      };
    });
    const items: ActionSheetItem[] = [
      ...checks,
      ...(checks.length > 0 ? [ACTION_SHEET_DIVIDER] : []),
      { id: NEW_LABEL, label: this.transloco.translate('reader.labels.newLabel'), symbol: 'add' },
    ];
    const anchor = this.openAnchor();
    this.sheet
      .open({ title: this.transloco.translate('reader.labels.menu'), actions: items })
      .pipe(finalize(() => this.forget(null, anchor)))
      .subscribe((choice) => {
        if (choice === NEW_LABEL) this.openNewLabelDialog(row);
      });
  }

  private openNewLabelDialog(row: LabelledRow): void {
    this.dialog.open(NewLabelDialogComponent, { panelClass: 'app-dialog', data: { row } });
  }

  private forget(popover: OverlayRef | null, anchor: HTMLElement | null): void {
    if (popover !== null && this.popover === popover) this.popover = null;
    if (this.openAnchor() === anchor) this.openAnchor.set(null);
  }
}
