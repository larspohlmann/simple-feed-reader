import { ChangeDetectionStrategy, Component, computed, inject, signal } from '@angular/core';
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
import { parseProblem } from '../../core/problem';
import { TagPickerComponent } from '../../reader/feeds/tag-picker/tag-picker.component';
import { TagsStore } from '../../reader/state/tags.store';
import { ButtonComponent } from '../../shared/button/button.component';
import { ErrorBannerComponent } from '../../shared/error-banner/error-banner.component';
import { FieldComponent } from '../../shared/field/field.component';
import { inputValue } from '../../shared/input-value';
import { OverlayPanelComponent } from '../../shared/overlay-panel/overlay-panel.component';
import { hostOf, parseUrl } from '../../shared/url-host';
import { WarningBoxComponent } from '../../shared/warning-box/warning-box.component';
import { SettingsApi } from '../settings-api';
import { OpmlImportResult } from '../settings.models';
import { OpmlFeedState, OpmlPreview } from './opml-import.models';
import { OpmlImportSelection } from './opml-import-selection';

export interface OpmlImportDialogData {
  opml: string;
  preview: OpmlPreview;
}

const PREVIEW_DEBOUNCE_MS = 300;

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
    OverlayPanelComponent,
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
  protected readonly parent = signal('');
  protected readonly importing = signal(false);
  protected readonly failure = signal<string | null>(null);
  protected readonly counterKey = computed(() => {
    const one = this.selection.freeSlots() === 1;
    if (!this.selection.overLimit()) {
      return one ? 'settings.opml.counterOne' : 'settings.opml.counter';
    }
    return one ? 'settings.opml.overLimitOne' : 'settings.opml.overLimit';
  });
  private readonly previewedParent = signal('');
  protected readonly canImport = computed(
    () =>
      this.selection.selectedCount() > 0 &&
      !this.selection.overLimit() &&
      this.parent().trim() === this.previewedParent(),
  );

  private readonly typedParents = new Subject<string>();
  private readonly importStarts = new Subject<void>();

  constructor() {
    this.selection.load(this.data.preview);
    if (this.tags().length === 0) this.tagsStore.load();
    this.typedParents
      .pipe(
        debounceTime(PREVIEW_DEBOUNCE_MS),
        filter(() => !this.importing()),
        map((parent) => parent.trim()),
        switchMap((parent) =>
          this.api.previewOpml(this.data.opml, parent || null).pipe(
            map((preview) => ({ parent, preview })),
            takeUntil(this.importStarts),
            catchError((error: HttpErrorResponse) => this.fail(error)),
          ),
        ),
        takeUntilDestroyed(),
      )
      .subscribe(({ parent, preview }) => {
        this.failure.set(null);
        this.selection.load(preview);
        this.previewedParent.set(parent);
      });
    toObservable(this.tags)
      .pipe(
        map((tags) => tags.length),
        pairwise(),
        filter(([before, after]) => after > before),
        takeUntilDestroyed(),
      )
      .subscribe(() => this.typedParents.next(this.parent()));
  }

  protected typeParent(event: Event): void {
    this.parent.set(inputValue(event));
    this.typedParents.next(this.parent());
  }

  protected hostLabel(url: string): string {
    const parsed = parseUrl(url);
    return parsed === null ? url : hostOf(parsed);
  }

  protected apply(): void {
    const closeWasDisabled = this.dialogRef.disableClose;
    this.importStarts.next();
    this.importing.set(true);
    this.dialogRef.disableClose = true;
    this.failure.set(null);
    this.api
      .importOpml(this.selection.toRequest(this.data.opml, this.previewedParent()))
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
