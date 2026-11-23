import { OwnInstanceHrefDirective } from '../../own-instance/own-instance-href.directive';
import { HttpErrorResponse } from '@angular/common/http';
import {
  ChangeDetectionStrategy,
  Component,
  DestroyRef,
  ElementRef,
  computed,
  inject,
  input,
  linkedSignal,
  signal,
  viewChild,
} from '@angular/core';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { TranslocoPipe } from '@jsverse/transloco';
import { LanguageService } from '../../../core/i18n/language.service';
import { pluralKey } from '../../../core/i18n/plural-key';
import { parseProblem, problemMessage } from '../../../core/problem';
import { SOCIAL_NETWORK_NAMES, SocialNetworkName } from '../../../core/social-network';
import { AvatarComponent } from '../../../shared/avatar/avatar.component';
import { ButtonComponent } from '../../../shared/button/button.component';
import { DisclosureComponent } from '../../../shared/disclosure/disclosure.component';
import { ErrorBannerComponent } from '../../../shared/error-banner/error-banner.component';
import { FieldComponent } from '../../../shared/field/field.component';
import { inputValue } from '../../../shared/input-value';
import { SkeletonComponent } from '../../../shared/skeleton/skeleton.component';
import { WarningBoxComponent } from '../../../shared/warning-box/warning-box.component';
import { relativeTime } from '../../format';
import { SearchPreview, SocialSearchService } from './social-search.service';

interface PreviewOutcome {
  readonly query: string;
  readonly preview: SearchPreview | null;
  readonly failure: string | null;
}

interface SyntaxRule {
  readonly operator: string;
  readonly key: string;
}

const OPERATOR_EXAMPLE = '"rss reader" -gazette';
const COMBINED_EXAMPLE = '("rss reader" || "feed reader" || rssreader) -gazette';

const PHRASE: SyntaxRule = { operator: '"rss reader"', key: 'settings.social.search.syntaxPhrase' };
const EITHER: SyntaxRule = { operator: 'rss || atom', key: 'settings.social.search.syntaxEither' };
const EXCLUDE: SyntaxRule = { operator: '-gazette', key: 'settings.social.search.syntaxExclude' };
const EXCLUDE_AUTHOR: SyntaxRule = {
  operator: '-from:handle',
  key: 'settings.social.search.syntaxExcludeAuthor',
};
const GROUP: SyntaxRule = { operator: '( … )', key: 'settings.social.search.syntaxGroup' };

const ALL_WORDS_SYNTAX: readonly SyntaxRule[] = [
  { operator: 'rss reader', key: 'settings.social.search.syntaxAllWords' },
  PHRASE,
  EITHER,
  EXCLUDE,
  EXCLUDE_AUTHOR,
  GROUP,
];

const SEARCH_SYNTAX: Readonly<Record<SocialNetworkName, readonly SyntaxRule[]>> = {
  bluesky: [PHRASE, EITHER, EXCLUDE, EXCLUDE_AUTHOR, GROUP],
  mastodon: ALL_WORDS_SYNTAX,
  lemmy: ALL_WORDS_SYNTAX,
};

/** A search query with its syntax help and a preview of what it finds; the host projects its own action beside Preview. */
@Component({
  selector: 'app-social-search-form',
  imports: [
    OwnInstanceHrefDirective,
    AvatarComponent,
    ButtonComponent,
    DisclosureComponent,
    ErrorBannerComponent,
    FieldComponent,
    SkeletonComponent,
    TranslocoPipe,
    WarningBoxComponent,
  ],
  templateUrl: './social-search-form.component.html',
  styleUrl: './social-search-form.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class SocialSearchFormComponent {
  readonly network = input.required<SocialNetworkName>();
  readonly accountId = input.required<number>();
  readonly initialQuery = input('');

  private readonly socialSearch = inject(SocialSearchService);
  private readonly language = inject(LanguageService);
  private readonly destroyRef = inject(DestroyRef);
  private readonly queryInput = viewChild.required<ElementRef<HTMLTextAreaElement>>('queryInput');

  protected readonly inputValue = inputValue;
  protected readonly query = linkedSignal(() => this.initialQuery());
  protected readonly previewing = signal(false);
  protected readonly outcome = signal<PreviewOutcome | null>(null);

  readonly normalizedQuery = computed(() => this.query().trim().replace(/\s+/g, ' '));
  protected readonly exampleQuery = OPERATOR_EXAMPLE;
  protected readonly combinedExample = COMBINED_EXAMPLE;
  protected readonly networkName = computed(() => SOCIAL_NETWORK_NAMES[this.network()]);
  protected readonly syntaxRules = computed(() => SEARCH_SYNTAX[this.network()]);
  protected readonly canPreview = computed(
    () => this.normalizedQuery() !== '' && !this.previewing(),
  );
  private readonly currentOutcome = computed(() => {
    const outcome = this.outcome();
    return outcome?.query === this.normalizedQuery() ? outcome : null;
  });
  readonly preview = computed(() => this.currentOutcome()?.preview ?? null);
  protected readonly failure = computed(() => this.currentOutcome()?.failure ?? null);
  protected readonly matchCountKey = computed(() =>
    pluralKey('settings.social.search.matchCount', this.preview()?.count ?? 0),
  );

  focus(): void {
    this.queryInput().nativeElement.focus();
  }

  protected previewOnShortcut(event: Event): void {
    event.preventDefault();
    this.runPreview();
  }

  protected runPreview(): void {
    if (!this.canPreview()) return;
    const query = this.normalizedQuery();
    this.previewing.set(true);
    this.socialSearch
      .preview(this.accountId(), query)
      .pipe(takeUntilDestroyed(this.destroyRef))
      .subscribe({
        next: (preview) => this.settle({ query, preview, failure: null }),
        error: (error: HttpErrorResponse) =>
          this.settle({ query, preview: null, failure: problemMessage(parseProblem(error)) }),
      });
  }

  protected when(publishedAt: string | null): string {
    return publishedAt === null ? '' : relativeTime(publishedAt, this.language.lang());
  }

  private settle(outcome: PreviewOutcome): void {
    this.outcome.set(outcome);
    this.previewing.set(false);
  }
}
