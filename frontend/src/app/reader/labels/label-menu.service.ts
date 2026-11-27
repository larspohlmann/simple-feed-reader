import { Injectable, Injector, computed, inject } from '@angular/core';
import { Dialog } from '@angular/cdk/dialog';
import { Overlay } from '@angular/cdk/overlay';
import { ComponentPortal } from '@angular/cdk/portal';
import { TranslocoService } from '@jsverse/transloco';
import { filter, merge, takeUntil } from 'rxjs';
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
import { carriesLabel, toLabelPill, toggleLabel } from './label-toggle';
import { LabelsStore } from './labels.store';
import { NewLabelDialogComponent, NewLabelDialogData } from './new-label-dialog.component';

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

  open(entry: EntryDto, anchor: HTMLElement, actions: EntryActionHandler): void {
    if (this.layout.isCoarse()) this.openSheet(entry, actions);
    else this.openPopover(entry, anchor, actions);
  }

  private openPopover(entry: EntryDto, anchor: HTMLElement, actions: EntryActionHandler): void {
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
    const panel = popover.attach(new ComponentPortal(LabelMenuPanelComponent, null, this.injector));
    panel.setInput('entry', entry);
    panel.setInput('actions', actions);
    merge(
      popover.backdropClick(),
      popover.keydownEvents().pipe(filter((event) => event.key === 'Escape')),
    )
      .pipe(takeUntil(popover.detachments()))
      .subscribe(() => {
        popover.dispose();
        anchor.focus({ preventScroll: true });
      });
  }

  private openSheet(entry: EntryDto, actions: EntryActionHandler): void {
    const shown = computed(() => this.rows.entry(entry.id) ?? entry);
    const checks = this.labels.labels().map((tally): ActionSheetCheck => {
      const label = toLabelPill(tally);
      return {
        id: `label-${label.id}`,
        label: label.name,
        checked: computed(() => carriesLabel(shown(), label.id)),
        toggle: () => toggleLabel(actions, shown(), label),
      };
    });
    const items: ActionSheetItem[] = [
      ...checks,
      ...(checks.length > 0 ? [ACTION_SHEET_DIVIDER] : []),
      { id: NEW_LABEL, label: this.transloco.translate('reader.labels.newLabel'), symbol: 'add' },
    ];
    this.sheet
      .open({ title: this.transloco.translate('reader.labels.menu'), actions: items })
      .subscribe((choice) => {
        if (choice === NEW_LABEL) this.openNewLabelDialog({ entry: shown(), actions });
      });
  }

  private openNewLabelDialog(data: NewLabelDialogData): void {
    this.dialog.open(NewLabelDialogComponent, { panelClass: 'app-dialog', data });
  }
}
