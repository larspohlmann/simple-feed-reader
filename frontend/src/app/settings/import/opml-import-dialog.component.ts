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
import { TagChipsComponent } from '../../reader/feeds/tag-picker/tag-chips.component';
import { TagPickerComponent } from '../../reader/feeds/tag-picker/tag-picker.component';
import { TagsStore } from '../../reader/state/tags.store';
import { IconButtonDirective } from '../../shared/icon-button/icon-button.directive';
import { IconComponent } from '../../shared/icon/icon.component';
import { ButtonComponent } from '../../shared/button/button.component';
import { ErrorBannerComponent } from '../../shared/error-banner/error-banner.component';
import { FieldComponent } from '../../shared/field/field.component';
import { inputValue } from '../../shared/input-value';
import { OverlayPanelComponent } from '../../shared/overlay-panel/overlay-panel.component';
import { WarningBoxComponent } from '../../shared/warning-box/warning-box.component';
import { SettingsApi } from '../settings-api';
import { OpmlImportResult } from '../settings.models';
import { OpmlFeedState, OpmlImportRow, OpmlPlacement, OpmlPreview } from './opml-import.models';
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

interface BulkChoice {
  keepsFolder: boolean;
  tagIds: ReadonlySet<number>;
}

type BulkFolder = { name: string } | { theirFolders: true };

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
    TagChipsComponent,
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
  protected readonly tags = this.tagsStore.tags;
  protected readonly topLevelTagNames = computed(() =>
    this.tags()
      .filter((tag) => tag.parentId === null)
      .map((tag) => tag.name),
  );
  private readonly tagNamesById = computed(
    () => new Map(this.tags().map((tag) => [tag.id, tag.name])),
  );
  private readonly openedFeeds = signal<ReadonlySet<string>>(new Set());
  protected readonly bulk = signal<BulkChoice | null>(null);
  protected readonly bulkFolder = computed<BulkFolder | null>(() => {
    const destinations = this.selection.tickedDestinations();
    if (destinations.length > 1) return { theirFolders: true };
    const name = destinations[0]?.at(-1);
    return name === undefined ? null : { name };
  });
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
  private readonly previewedPlacement = signal<OpmlPlacement>({
    parentTagName: null,
    folderNames: {},
  });
  private readonly currentPlacementKey = computed(() => placementKey(this.currentPlacement()));
  private readonly previewedPlacementKey = computed(() => placementKey(this.previewedPlacement()));
  protected readonly canImport = computed(
    () =>
      this.selection.selectedCount() > 0 &&
      !this.selection.overLimit() &&
      this.previewedPlacementKey() === this.currentPlacementKey(),
  );
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
      const input = this.renameInput()?.nativeElement;
      input?.focus();
      input?.select();
    });
    toObservable(this.tags)
      .pipe(
        map((tags) => tags.length),
        pairwise(),
        filter(([before, after]) => after > before),
        takeUntilDestroyed(),
      )
      .subscribe(() => this.requestPreview());
  }

  protected typeParent(event: Event): void {
    this.parent.set(inputValue(event));
    this.requestPreview();
  }

  protected startRename(row: FolderRow): void {
    this.editingKey.set(row.key);
  }

  protected commitRename(row: FolderRow, event: Event): void {
    if (this.editingKey() !== row.key) return;
    this.editingKey.set(null);
    const name = inputValue(event);
    if (name.trim() === row.tagName) return;
    this.renameTo(row, name);
  }

  protected cancelRename(): void {
    this.editingKey.set(null);
  }

  protected resetFolder(row: FolderRow): void {
    this.selection.resetFolder(row);
    this.requestPreview();
  }

  private renameTo(row: FolderRow, name: string): void {
    const before = this.currentPlacementKey();
    this.selection.renameFolder(row, name);
    if (this.currentPlacementKey() !== before) this.requestPreview();
  }

  private requestPreview(): void {
    this.placements.next(this.currentPlacement());
  }

  protected tagNames(tagIds: ReadonlySet<number>): string {
    const names = this.tagNamesById();
    return [...tagIds].flatMap((tagId) => names.get(tagId) ?? []).join(', ');
  }

  protected isTagsOpen(url: string): boolean {
    return this.openedFeeds().has(url) || this.selection.modeOf(url) !== 'folder';
  }

  protected toggleTags(url: string): void {
    this.openedFeeds.update((previous) => toggled(previous, url));
  }

  protected toggleFeedTag(url: string, tagId: number): void {
    this.keepTagsOpen(url);
    this.selection.toggleTag(url, tagId);
  }

  protected dropFeedFolder(url: string): void {
    this.keepTagsOpen(url);
    this.selection.dropFolder(url);
  }

  protected restoreFeedFolder(url: string): void {
    this.keepTagsOpen(url);
    this.selection.restoreFolder(url);
  }

  private keepTagsOpen(url: string): void {
    this.openedFeeds.update((previous) => new Set(previous).add(url));
  }

  protected openBulk(): void {
    this.bulk.set({ keepsFolder: true, tagIds: new Set() });
  }

  protected closeBulk(): void {
    this.bulk.set(null);
  }

  protected dropBulkFolder(): void {
    this.bulk.update((previous) => previous && { ...previous, keepsFolder: false });
  }

  protected restoreBulkFolder(): void {
    this.bulk.update((previous) => previous && { ...previous, keepsFolder: true });
  }

  protected toggleBulkTag(tagId: number): void {
    this.bulk.update(
      (previous) => previous && { ...previous, tagIds: toggled(previous.tagIds, tagId) },
    );
  }

  protected applyBulk(): void {
    const choice = this.bulk();
    if (choice === null) return;
    const keptMode = choice.tagIds.size === 0 ? 'folder' : 'folderPlusTags';
    this.selection.applyToTicked(choice.keepsFolder ? keptMode : 'tagsOnly', choice.tagIds);
    this.closeBulk();
  }

  protected apply(): void {
    const closeWasDisabled = this.dialogRef.disableClose;
    this.importStarts.next();
    this.importing.set(true);
    this.dialogRef.disableClose = true;
    this.failure.set(null);
    this.api
      .importOpml(this.selection.toRequest(this.data.opml, this.previewedPlacement()))
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
