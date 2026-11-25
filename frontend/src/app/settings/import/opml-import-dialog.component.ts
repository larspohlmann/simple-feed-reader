import {
  ChangeDetectionStrategy,
  Component,
  ElementRef,
  computed,
  effect,
  inject,
  signal,
  viewChild,
} from '@angular/core';
import { takeUntilDestroyed, toObservable } from '@angular/core/rxjs-interop';
import { A11yModule } from '@angular/cdk/a11y';
import { DIALOG_DATA, DialogRef } from '@angular/cdk/dialog';
import { HttpErrorResponse } from '@angular/common/http';
import { TranslocoPipe } from '@jsverse/transloco';
import {
  EMPTY,
  Subject,
  catchError,
  debounceTime,
  filter,
  map,
  pairwise,
  switchMap,
  takeUntil,
} from 'rxjs';
import { toggled } from '../../core/toggled-set';
import { pluralKey } from '../../core/i18n/plural-key';
import { parseProblem } from '../../core/problem';
import { TagPickerComponent } from '../../reader/feeds/tag-picker/tag-picker.component';
import { TagsStore } from '../../reader/state/tags.store';
import { IconButtonDirective } from '../../shared/icon-button/icon-button.directive';
import { IconComponent } from '../../shared/icon/icon.component';
import { SegmentedChoiceComponent } from '../../shared/segmented-choice/segmented-choice.component';
import { ButtonComponent } from '../../shared/button/button.component';
import { ErrorBannerComponent } from '../../shared/error-banner/error-banner.component';
import { FieldComponent } from '../../shared/field/field.component';
import { inputValue } from '../../shared/input-value';
import { OverlayPanelComponent } from '../../shared/overlay-panel/overlay-panel.component';
import { WarningBoxComponent } from '../../shared/warning-box/warning-box.component';
import { SettingsApi } from '../settings-api';
import { OpmlImportResult } from '../settings.models';
import {
  OpmlFeedState,
  OpmlImportRow,
  OpmlPlacement,
  OpmlPreview,
  OpmlTagMode,
} from './opml-import.models';
import { OpmlImportSelection } from './opml-import-selection';

export interface OpmlImportDialogData {
  opml: string;
  preview: OpmlPreview;
}

const PREVIEW_DEBOUNCE_MS = 300;

type FolderRow = Extract<OpmlImportRow, { kind: 'folder' }>;

function placementKey(placement: OpmlPlacement): string {
  const names = Object.entries(placement.folderNames).sort(([left], [right]) =>
    left.localeCompare(right),
  );
  return JSON.stringify([placement.parentTagName, names]);
}

const TAG_MODES: readonly OpmlTagMode[] = ['folder', 'folderPlusTags', 'tagsOnly'];

const STATE_KEYS: Readonly<Record<Exclude<OpmlFeedState, 'new'>, string>> = {
  subscribed: 'settings.opml.stateSubscribed',
  scraped: 'settings.opml.stateScraped',
  invalid_url: 'settings.opml.stateInvalidUrl',
};

@Component({
  selector: 'app-opml-import-dialog',
  imports: [
    A11yModule,
    ButtonComponent,
    ErrorBannerComponent,
    FieldComponent,
    IconButtonDirective,
    IconComponent,
    OverlayPanelComponent,
    SegmentedChoiceComponent,
    TagPickerComponent,
    TranslocoPipe,
    WarningBoxComponent,
  ],
  providers: [OpmlImportSelection],
  templateUrl: './opml-import-dialog.component.html',
  styleUrl: './opml-import-dialog.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class OpmlImportDialogComponent {
  private readonly dialogRef = inject<DialogRef<OpmlImportResult | undefined>>(DialogRef);
  private readonly data = inject<OpmlImportDialogData>(DIALOG_DATA);
  private readonly api = inject(SettingsApi);
  private readonly tagsStore = inject(TagsStore);
  protected readonly selection = inject(OpmlImportSelection);

  protected readonly stateKeys = STATE_KEYS;
  protected readonly tagModes = TAG_MODES;
  protected readonly tags = this.tagsStore.tags;
  protected readonly topLevelTagNames = computed(() =>
    this.tags()
      .filter((tag) => tag.parentId === null)
      .map((tag) => tag.name),
  );
  private readonly tagNamesById = computed(
    () => new Map(this.tags().map((tag) => [tag.id, tag.name])),
  );
  protected readonly bulkOpen = signal(false);
  protected readonly bulkMode = signal<OpmlTagMode>('folder');
  protected readonly bulkTagIds = signal<ReadonlySet<number>>(new Set());
  protected readonly parent = signal('');
  protected readonly importing = signal(false);
  protected readonly failure = signal<string | null>(null);
  protected readonly counterKey = computed(() =>
    pluralKey(
      this.selection.overLimit() ? 'settings.opml.overLimit' : 'settings.opml.counter',
      this.selection.freeSlots(),
    ),
  );
  private readonly currentPlacement = computed<OpmlPlacement>(() => ({
    parentTagName: this.parent().trim() || null,
    folderNames: this.selection.folderNames(),
  }));
  private readonly previewedPlacement = signal<OpmlPlacement | null>({
    parentTagName: null,
    folderNames: {},
  });
  protected readonly canImport = computed(() => {
    const previewed = this.previewedPlacement();
    return (
      this.selection.selectedCount() > 0 &&
      !this.selection.overLimit() &&
      previewed !== null &&
      placementKey(previewed) === placementKey(this.currentPlacement())
    );
  });
  protected readonly editingKey = signal<string | null>(null);
  private readonly renameInput = viewChild<ElementRef<HTMLInputElement>>('renameInput');

  private readonly placements = new Subject<OpmlPlacement>();
  private readonly importStarts = new Subject<void>();

  constructor() {
    this.selection.load(this.data.preview);
    if (this.tags().length === 0) this.tagsStore.load();
    this.placements
      .pipe(
        debounceTime(PREVIEW_DEBOUNCE_MS),
        filter(() => !this.importing()),
        switchMap((placement) =>
          this.api.previewOpml(this.data.opml, placement).pipe(
            map((preview) => ({ placement, preview })),
            takeUntil(this.importStarts),
            catchError((error: HttpErrorResponse) => this.fail(error)),
          ),
        ),
        takeUntilDestroyed(),
      )
      .subscribe(({ placement, preview }) => {
        this.failure.set(null);
        this.selection.load(preview);
        this.previewedPlacement.set(placement);
      });
    effect(() => {
      this.renameInput()?.nativeElement.select();
    });
    toObservable(this.tags)
      .pipe(
        map((tags) => tags.length),
        pairwise(),
        filter(([before, after]) => after > before),
        takeUntilDestroyed(),
      )
      .subscribe(() => this.placements.next(this.currentPlacement()));
  }

  protected typeParent(event: Event): void {
    this.parent.set(inputValue(event));
    this.placements.next(this.currentPlacement());
  }

  protected startRename(row: FolderRow): void {
    this.editingKey.set(row.key);
  }

  protected commitRename(row: FolderRow, event: Event): void {
    if (this.editingKey() !== row.key) return;
    this.editingKey.set(null);
    this.renameTo(row, inputValue(event));
  }

  protected cancelRename(): void {
    this.editingKey.set(null);
  }

  protected resetFolder(row: FolderRow): void {
    this.selection.resetFolder(row);
    this.placements.next(this.currentPlacement());
  }

  private renameTo(row: FolderRow, name: string): void {
    const before = placementKey(this.currentPlacement());
    this.selection.renameFolder(row, name);
    if (placementKey(this.currentPlacement()) !== before) {
      this.placements.next(this.currentPlacement());
    }
  }

  protected tagNames(tagIds: ReadonlySet<number>): string {
    const names = this.tagNamesById();
    return [...tagIds].map((tagId) => names.get(tagId) ?? '').join(', ');
  }

  protected openBulk(): void {
    this.bulkMode.set('folder');
    this.bulkTagIds.set(new Set());
    this.bulkOpen.set(true);
  }

  protected closeBulk(): void {
    this.bulkOpen.set(false);
  }

  protected toggleBulkTag(tagId: number): void {
    this.bulkTagIds.update((previous) => toggled(previous, tagId));
  }

  protected applyBulk(): void {
    this.selection.applyToTicked(this.bulkMode(), this.bulkTagIds());
    this.closeBulk();
  }

  protected apply(): void {
    const closeWasDisabled = this.dialogRef.disableClose;
    this.importStarts.next();
    this.importing.set(true);
    this.dialogRef.disableClose = true;
    this.failure.set(null);
    this.api
      .importOpml(
        this.selection.toRequest(this.data.opml, this.previewedPlacement()?.parentTagName ?? null),
      )
      .subscribe({
        next: (result) => this.dialogRef.close(result),
        error: (error: HttpErrorResponse) => {
          this.importing.set(false);
          this.dialogRef.disableClose = closeWasDisabled;
          this.fail(error);
        },
      });
  }

  protected cancel(): void {
    this.dialogRef.close(undefined);
  }

  private fail(error: HttpErrorResponse): typeof EMPTY {
    const problem = parseProblem(error);
    this.failure.set(problem.detail || problem.title);
    return EMPTY;
  }
}
