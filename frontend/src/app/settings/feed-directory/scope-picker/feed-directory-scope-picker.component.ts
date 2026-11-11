import {
  ChangeDetectionStrategy,
  Component,
  DestroyRef,
  computed,
  inject,
  input,
  output,
  signal,
} from '@angular/core';
import { takeUntilDestroyed, toSignal } from '@angular/core/rxjs-interop';
import { TranslocoPipe, TranslocoService } from '@jsverse/transloco';
import { ButtonComponent } from '../../../shared/button/button.component';
import { ErrorBannerComponent } from '../../../shared/error-banner/error-banner.component';
import { IconComponent } from '../../../shared/icon/icon.component';
import { inputValue } from '../../../shared/input-value';
import { FeedDirectoryApi } from '../feed-directory-api';
import { FeedDirectoryScopeService } from '../feed-directory-scope.service';
import { FeedDirectoryIndex, FeedDirectoryScope } from '../feed-directory.models';
import { ScopeChecklist } from './scope-checklist';
import { ScopeOption, countryOptions, languageOptions } from './scope-options';

@Component({
  selector: 'app-feed-directory-scope-picker',
  imports: [ButtonComponent, ErrorBannerComponent, IconComponent, TranslocoPipe],
  templateUrl: './feed-directory-scope-picker.component.html',
  styleUrl: './feed-directory-scope-picker.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class FeedDirectoryScopePickerComponent {
  private readonly scopes = inject(FeedDirectoryScopeService);
  private readonly transloco = inject(TranslocoService);

  readonly initial = input.required<FeedDirectoryScope>();
  readonly saved = output<FeedDirectoryScope>();

  private readonly api = inject(FeedDirectoryApi);
  private readonly destroyRef = inject(DestroyRef);
  private readonly index = signal<FeedDirectoryIndex | null>(null);
  protected readonly loadFailed = signal(false);
  private readonly lang = toSignal(this.transloco.langChanges$, {
    initialValue: this.transloco.getActiveLang(),
  });

  protected readonly countries = new ScopeChecklist(
    {
      id: 'countries',
      heading: 'settings.feedDirectory.scope.countries',
      filter: 'settings.feedDirectory.scope.filterCountries',
    },
    computed(() => this.optionsFrom(countryOptions)),
    () => this.initial().countries,
  );
  protected readonly languages = new ScopeChecklist(
    {
      id: 'languages',
      heading: 'settings.feedDirectory.scope.languages',
      filter: 'settings.feedDirectory.scope.filterLanguages',
    },
    computed(() => this.optionsFrom(languageOptions)),
    () => this.initial().languages,
  );
  protected readonly checklists = [this.countries, this.languages];

  protected readonly canSave = computed(() => this.countries.hasPick() && this.languages.hasPick());
  protected readonly saving = signal(false);
  protected readonly saveFailed = signal(false);
  protected readonly siteCount = computed(() => new Intl.NumberFormat(this.lang()));
  protected readonly inputValue = inputValue;

  constructor() {
    this.loadIndex();
  }

  protected loadIndex(): void {
    this.loadFailed.set(false);
    this.api
      .index()
      .pipe(takeUntilDestroyed(this.destroyRef))
      .subscribe({
        next: (index) => this.index.set(index),
        error: () => this.loadFailed.set(true),
      });
  }

  protected save(): void {
    const scope: FeedDirectoryScope = {
      countries: this.countries.codes(),
      languages: this.languages.codes(),
    };
    this.saving.set(true);
    this.saveFailed.set(false);
    this.scopes.save(scope).subscribe((saved) => {
      this.saving.set(false);
      if (!saved) {
        this.saveFailed.set(true);
        return;
      }
      this.saved.emit(scope);
    });
  }

  private optionsFrom(
    build: (index: FeedDirectoryIndex, lang: string) => ScopeOption[],
  ): ScopeOption[] {
    const index = this.index();

    return index === null ? [] : build(index, this.lang());
  }
}
