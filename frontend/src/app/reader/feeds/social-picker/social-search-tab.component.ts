import { HttpErrorResponse } from '@angular/common/http';
import {
  ChangeDetectionStrategy,
  Component,
  DestroyRef,
  ElementRef,
  afterNextRender,
  computed,
  inject,
  input,
  output,
  signal,
  viewChild,
} from '@angular/core';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { TranslocoPipe } from '@jsverse/transloco';
import { LanguageService } from '../../../core/i18n/language.service';
import { pluralKey } from '../../../core/i18n/plural-key';
import { parseProblem, problemMessage } from '../../../core/problem';
import { SocialNetworkName } from '../../../core/social-network';
import { AvatarComponent } from '../../../shared/avatar/avatar.component';
import { ButtonComponent } from '../../../shared/button/button.component';
import { DisclosureComponent } from '../../../shared/disclosure/disclosure.component';
import { ErrorBannerComponent } from '../../../shared/error-banner/error-banner.component';
import { FieldComponent } from '../../../shared/field/field.component';
import { inputValue } from '../../../shared/input-value';
import { SkeletonComponent } from '../../../shared/skeleton/skeleton.component';
import { WarningBoxComponent } from '../../../shared/warning-box/warning-box.component';
import { relativeTime } from '../../format';
import { SearchPreview, SocialImportService } from './social-import.service';

type PreviewOutcome =
  | { readonly query: string; readonly preview: SearchPreview }
  | { readonly query: string; readonly failure: string };

const SHOWN_POSTS = 3;

interface SyntaxRule {
  readonly operator: string;
  readonly key: string;
}

const EXAMPLE_QUERY = '"rss reader" -gazette';

interface SearchSyntax {
  readonly rules: readonly SyntaxRule[];
  readonly combined: string | null;
}

const EXCLUDE_AUTHOR: SyntaxRule = {
  operator: '-from:handle',
  key: 'settings.social.search.syntaxExcludeAuthor',
};

const SEARCH_SYNTAX: Readonly<Record<SocialNetworkName, SearchSyntax>> = {
  bluesky: {
    rules: [
      { operator: '"rss reader"', key: 'settings.social.search.syntaxPhrase' },
      { operator: 'rss || atom', key: 'settings.social.search.syntaxEither' },
      { operator: '-gazette', key: 'settings.social.search.syntaxExclude' },
      EXCLUDE_AUTHOR,
      { operator: '( … )', key: 'settings.social.search.syntaxGroup' },
    ],
    combined: '("rss reader" || "feed reader" || rssreader) -gazette',
  },
  mastodon: {
    rules: [
      { operator: 'rss reader', key: 'settings.social.search.syntaxAllWords' },
      { operator: '"rss reader"', key: 'settings.social.search.syntaxPhrase' },
      { operator: '-gazette', key: 'settings.social.search.syntaxExclude' },
      EXCLUDE_AUTHOR,
    ],
    combined: null,
  },
};

@Component({
  selector: 'app-social-search-tab',
  imports: [
    AvatarComponent,
    ButtonComponent,
    DisclosureComponent,
    ErrorBannerComponent,
    FieldComponent,
    SkeletonComponent,
    TranslocoPipe,
    WarningBoxComponent,
  ],
  templateUrl: './social-search-tab.component.html',
  styleUrl: './social-search-tab.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class SocialSearchTabComponent {
  readonly network = input.required<SocialNetworkName>();
  readonly accountId = input.required<number>();
  readonly subscribe = output<string>();

  protected readonly importer = inject(SocialImportService);
  private readonly language = inject(LanguageService);
  private readonly destroyRef = inject(DestroyRef);
  private readonly queryInput = viewChild.required<ElementRef<HTMLInputElement>>('queryInput');

  protected readonly inputValue = inputValue;
  protected readonly query = signal('');
  protected readonly previewing = signal(false);
  protected readonly outcome = signal<PreviewOutcome | null>(null);

  private readonly trimmed = computed(() => this.query().trim());
  protected readonly exampleQuery = EXAMPLE_QUERY;
  protected readonly syntax = computed(() => SEARCH_SYNTAX[this.network()]);
  protected readonly canPreview = computed(() => this.trimmed() !== '' && !this.previewing());
  private readonly currentOutcome = computed(() => {
    const outcome = this.outcome();
    return outcome?.query === this.trimmed() ? outcome : null;
  });
  protected readonly preview = computed(() => {
    const outcome = this.currentOutcome();
    return outcome !== null && 'preview' in outcome ? outcome.preview : null;
  });
  protected readonly failure = computed(() => {
    const outcome = this.currentOutcome();
    return outcome !== null && 'failure' in outcome ? outcome.failure : null;
  });
  protected readonly canSubscribe = computed(() => this.preview() !== null);
  protected readonly shownPosts = computed(() => this.preview()?.posts.slice(0, SHOWN_POSTS) ?? []);
  protected readonly matchCountKey = computed(() =>
    pluralKey('settings.social.search.matchCount', this.preview()?.count ?? 0),
  );

  constructor() {
    afterNextRender(() => this.queryInput().nativeElement.focus());
  }

  protected runPreview(): void {
    if (!this.canPreview()) return;
    const query = this.trimmed();
    this.previewing.set(true);
    this.importer
      .preview(this.accountId(), query)
      .pipe(takeUntilDestroyed(this.destroyRef))
      .subscribe({
        next: (preview) => this.settle({ query, preview }),
        error: (error: HttpErrorResponse) =>
          this.settle({ query, failure: problemMessage(parseProblem(error)) }),
      });
  }

  protected subscribeToQuery(): void {
    this.subscribe.emit(this.trimmed());
  }

  protected when(publishedAt: string | null): string {
    return publishedAt === null ? '' : relativeTime(publishedAt, this.language.lang());
  }

  private settle(outcome: PreviewOutcome): void {
    this.outcome.set(outcome);
    this.previewing.set(false);
  }
}
