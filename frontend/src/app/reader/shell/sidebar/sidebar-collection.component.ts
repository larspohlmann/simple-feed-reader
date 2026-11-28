import { HttpErrorResponse } from '@angular/common/http';
import {
  ChangeDetectionStrategy,
  Component,
  DestroyRef,
  ElementRef,
  computed,
  effect,
  inject,
  input,
  signal,
  viewChild,
} from '@angular/core';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { Router, RouterLink } from '@angular/router';
import { TranslocoPipe, TranslocoService } from '@jsverse/transloco';
import { parseProblem } from '../../../core/problem';
import { pluralKey } from '../../../core/i18n/plural-key';
import { folderToggle } from '../../../core/preferences/folder-toggle';
import { ActionSheetAction } from '../../../shared/action-sheet/action-sheet.component';
import { ActionSheet } from '../../../shared/action-sheet/action-sheet.service';
import { ConfirmService } from '../../../shared/confirm-dialog/confirm.service';
import { IconComponent } from '../../../shared/icon/icon.component';
import { ToastService } from '../../../shared/toast/toast.service';
import { ExportDialogService } from '../../export/export-dialog.service';
import { LayoutService } from '../../layout.service';
import {
  COLLECTIONS,
  LABEL_NAME_MAX,
  LabelCollection,
  LabelFilter,
  LabelTally,
} from '../../labels/labels.models';
import { LabelsStore } from '../../labels/labels.store';
import { Selection, SelectionOf, SelectionParams, selectionQueryParams } from '../../query/query';
import { SubscriptionsStore } from '../../state/subscriptions.store';
import { SidebarRowActions } from './sidebar-row-actions.service';
import { SidebarRowMenuComponent } from './sidebar-row-menu.component';

/** A subfolder of the block: a label, or the posts that carry none (`label` null). */
interface CollectionFolder {
  filter: LabelFilter;
  params: SelectionParams;
  count: number;
  label: LabelTally | null;
}

type CollectionSelection = SelectionOf<LabelCollection>;

const isCollectionSelection = (selection: Selection): selection is CollectionSelection =>
  selection.kind === 'favorites' || selection.kind === 'kept';

/** The sidebar's Favorites or Kept block (#2581): the collection's head, with a subfolder per label it holds. */
@Component({
  selector: 'app-sidebar-collection',
  imports: [RouterLink, TranslocoPipe, IconComponent, SidebarRowMenuComponent],
  templateUrl: './sidebar-collection.component.html',
  styleUrl: './sidebar-collection.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class SidebarCollectionComponent {
  readonly collection = input.required<LabelCollection>();
  readonly selection = input.required<Selection>();

  private readonly labels = inject(LabelsStore);
  private readonly subscriptions = inject(SubscriptionsStore);
  private readonly confirm = inject(ConfirmService);
  private readonly toast = inject(ToastService);
  private readonly sheet = inject(ActionSheet);
  private readonly exportDialog = inject(ExportDialogService);
  private readonly transloco = inject(TranslocoService);
  private readonly router = inject(Router);
  private readonly destroyRef = inject(DestroyRef);
  protected readonly rows = inject(SidebarRowActions);
  protected readonly screen = inject(LayoutService);
  protected readonly selectionQueryParams = selectionQueryParams;
  protected readonly labelNameMax = LABEL_NAME_MAX;

  protected readonly icon = computed(() => COLLECTIONS[this.collection()].icon);
  protected readonly toggleLabel = computed(() => COLLECTIONS[this.collection()].toggleKey);

  protected readonly total = computed(() =>
    this.collection() === 'favorites'
      ? this.subscriptions.favoritesCount()
      : this.subscriptions.keptCount(),
  );

  protected readonly folders = computed((): CollectionFolder[] => {
    const view = this.collection();
    const countIn = COLLECTIONS[view].countField;
    const folder = (
      filter: LabelFilter,
      count: number,
      label: LabelTally | null,
    ): CollectionFolder => ({
      filter,
      params: selectionQueryParams({ view, label: filter }),
      count,
      label,
    });
    const labelled = this.labels
      .labels()
      .filter((label) => label[countIn] > 0)
      .map((label) => folder(label.id, label[countIn], label));
    const unlabeled = this.labels.unlabeled()[countIn];
    if (labelled.length === 0 || unlabeled === 0) return labelled;
    return [...labelled, folder('unlabeled', unlabeled, null)];
  });

  /** The label the list is narrowed to, when it is this collection's list. */
  protected readonly activeFilter = computed((): LabelFilter | null => {
    const selection = this.selection();
    if (!isCollectionSelection(selection) || selection.kind !== this.collection()) return null;
    return selection.label ?? null;
  });

  protected readonly folder = folderToggle(
    () => `sfr.sidebar.${this.collection()}.collapsed`,
    this.activeFilter,
  );

  protected readonly headCurrent = computed(
    () => this.selection().kind === this.collection() && this.activeFilter() === null,
  );

  protected readonly renamingId = signal<number | null>(null);
  private readonly renameField = viewChild<ElementRef<HTMLInputElement>>('renameField');

  private readonly focusRenameField = effect(() => {
    const field = this.renameField()?.nativeElement;
    field?.focus();
    field?.select();
  });

  protected readonly listName = computed(() =>
    this.transloco.translate(`reader.${this.collection()}`),
  );

  protected menuKey(folder: CollectionFolder): string {
    return `label-${this.collection()}-${folder.filter}`;
  }

  protected folderName(folder: CollectionFolder): string {
    return folder.label?.name ?? this.transloco.translate('reader.labels.unlabeled');
  }

  protected exportCollection(): void {
    this.rows.closeMenu();
    this.exportDialog.open({
      collection: this.collection(),
      label: null,
      title: this.listName(),
    });
  }

  protected exportFolder(folder: CollectionFolder): void {
    this.rows.closeMenu();
    this.exportDialog.open({
      collection: this.collection(),
      label: folder.filter,
      title: `${this.listName()} – ${this.folderName(folder)}`,
    });
  }

  protected startRename(label: LabelTally): void {
    this.rows.closeMenu();
    this.renamingId.set(label.id);
  }

  protected cancelRename(): void {
    this.renamingId.set(null);
  }

  protected saveRename(label: LabelTally, typed: string): void {
    const name = typed.trim();
    if (name === '' || name === label.name) {
      this.cancelRename();
      return;
    }
    this.labels.rename(label.id, name).subscribe({
      next: () => this.cancelRename(),
      error: (error: HttpErrorResponse) => this.showFailure(error),
    });
  }

  protected confirmDelete(label: LabelTally): void {
    this.rows.closeMenu();
    this.confirm
      .ask({
        title: this.transloco.translate('reader.labels.deleteTitle'),
        message: this.transloco.translate(
          pluralKey('reader.labels.deleteConfirm', label.articleCount),
          { name: label.name, count: label.articleCount },
        ),
        confirmLabel: this.transloco.translate('reader.labels.delete'),
        danger: true,
      })
      .subscribe((confirmed) => {
        if (confirmed) this.delete(label);
      });
  }

  protected openCollectionSheet(): void {
    this.sheet
      .open({ title: this.listName(), actions: [this.exportAction()] })
      .pipe(takeUntilDestroyed(this.destroyRef))
      .subscribe((choice) => {
        if (choice === 'export') this.exportCollection();
      });
  }

  protected openFolderSheet(folder: CollectionFolder): void {
    const label = folder.label;
    this.sheet
      .open({
        title: this.folderName(folder),
        actions:
          label === null ? [this.exportAction()] : [...this.labelActions(), this.exportAction()],
      })
      .pipe(takeUntilDestroyed(this.destroyRef))
      .subscribe((choice) => {
        if (choice === 'export') this.exportFolder(folder);
        if (label === null) return;
        if (choice === 'rename') this.startRename(label);
        if (choice === 'delete') this.confirmDelete(label);
      });
  }

  private exportAction(): ActionSheetAction {
    return {
      id: 'export',
      label: this.transloco.translate('reader.export.action'),
      symbol: 'download',
    };
  }

  private labelActions(): ActionSheetAction[] {
    return [
      { id: 'rename', label: this.transloco.translate('reader.labels.rename'), symbol: 'edit' },
      {
        id: 'delete',
        label: this.transloco.translate('reader.labels.delete'),
        symbol: 'delete',
        danger: true,
      },
    ];
  }

  private delete(label: LabelTally): void {
    const selection = this.selection();
    this.labels.remove(label.id).subscribe({
      next: () => {
        if (isCollectionSelection(selection) && selection.label === label.id) {
          this.openWholeCollection(selection.kind);
        }
      },
      error: (error: HttpErrorResponse) => this.showFailure(error),
    });
  }

  private showFailure(error: HttpErrorResponse): void {
    this.toast.showProblem(parseProblem(error));
  }

  private openWholeCollection(collection: LabelCollection): void {
    void this.router.navigate(['/'], {
      queryParams: selectionQueryParams({ view: collection }),
      queryParamsHandling: 'merge',
    });
  }
}
