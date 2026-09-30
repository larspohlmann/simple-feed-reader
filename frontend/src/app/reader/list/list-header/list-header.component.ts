import {
  Component,
  ElementRef,
  TemplateRef,
  computed,
  inject,
  input,
  output,
  viewChild,
} from '@angular/core';
import { NgTemplateOutlet } from '@angular/common';
import { TranslocoPipe } from '@jsverse/transloco';
import { IconComponent } from '../../../shared/icon/icon.component';
import { ListActionDirective } from '../../../shared/list-action/list-action.directive';
import { FaviconComponent } from '../../../shared/favicon/favicon.component';
import { TagGlyphComponent } from '../../../shared/tag-glyph/tag-glyph.component';
import { ListOrder, TagDto } from '../../models';
import {
  Selection,
  canScopedRefresh,
  hasListOrder,
  hasUnreadFilter,
  isPhraseTerm,
  isSingleStreamView,
  isWholeWordTerm,
  listOrderOf,
} from '../../query/query';
import { relativeTime, relativeTimeUntil } from '../../format';
import { LanguageService } from '../../../core/i18n/language.service';

/** The heading icon for each fixed view, matching its sidebar row's glyph so the
 *  list a reader lands in reads as the row they clicked (#411). Tag and
 *  subscription are absent — their heading already carries a glyph/favicon. */
const FIXED_VIEW_ICON: Partial<Record<Selection['kind'], string>> = {
  all: 'inbox',
  favorites: 'star',
  kept: 'bookmark',
  viewed: 'history',
  'for-you': 'auto_awesome',
  'saved-searches': 'saved_search',
  search: 'search',
};

/** How much the list holds, and what that number counts — travel together since
 *  the pill needs the value and the heading's accessible name needs what it
 *  counts. The shell resolves both once, for tab title and heading (#709). */
export interface TitleCount {
  readonly value: number;
  readonly counts: 'unread' | 'items';
}

/** The inside of the list's floating header: the heading and the tools. The
 *  `<header>` itself stays in the entry list, which measures it. */
@Component({
  selector: 'app-list-header',
  imports: [
    NgTemplateOutlet,
    TranslocoPipe,
    IconComponent,
    ListActionDirective,
    FaviconComponent,
    TagGlyphComponent,
  ],
  templateUrl: './list-header.component.html',
  styleUrl: './list-header.component.scss',
  host: {
    '[class.collapsed]': 'collapsed()',
    '[class.is-search]': "selection().kind === 'search'",
  },
})
export class ListHeaderComponent {
  readonly selection = input.required<Selection>();
  readonly title = input.required<string>();
  readonly searchTitlePrefix = input<string>('');
  readonly searchTitleTerm = input<string>('');
  readonly searchCountLabel = input<string | null>(null);
  readonly titleCount = input<TitleCount>({ value: 0, counts: 'items' });
  readonly titleTag = input<TagDto | null>(null);
  readonly titleFaviconUrl = input<string | null>(null);
  readonly lastRefreshed = input<string | null>(null);
  readonly nextRefresh = input<string | null>(null);
  readonly titleLeading = input<TemplateRef<unknown> | null>(null);
  readonly leadingActions = input<TemplateRef<unknown> | null>(null);
  readonly headerActions = input<TemplateRef<unknown> | null>(null);
  readonly collapsed = input(false);
  readonly refreshing = input(false);
  readonly canMarkAllRead = input(false);

  readonly refresh = output<void>();
  readonly orderChange = output<ListOrder>();
  readonly unreadOnlyChange = output<boolean>();
  readonly markAllRead = output<void>();

  private readonly language = inject(LanguageService);
  private readonly titleElement = viewChild<ElementRef<HTMLElement>>('listTitle');

  /** The refresh button is hidden in the cross-feed saved views. */
  protected readonly canRefresh = computed(() => canScopedRefresh(this.selection()));

  /** Whether this list offers the All posts / only unread switch. The rule is
   *  the selection vocabulary's, not this header's — the shell asks the same
   *  question when it builds the list query. */
  readonly hasUnreadFilter = computed(() => hasUnreadFilter(this.selection()));

  protected readonly hasListOrder = computed(() => hasListOrder(this.selection()));
  protected readonly oldestFirst = computed(() => listOrderOf(this.selection()) === 'oldest');

  /** The number the heading shows, or 0 for the two cases that show none: a
   *  list with nothing in it, and a search — whose heading already carries its
   *  own result count, with its own rules about when it may be shown. */
  protected readonly headingCount = computed(() =>
    this.selection().kind === 'search' ? 0 : this.titleCount().value,
  );

  /** Whether the selection is a search whose trailing space puts it in
   *  whole-word mode. The badge is the only display of this — `punk` and
   *  `punk ` otherwise render identical titles for very different results (#408). */
  protected readonly showWholeWordBadge = computed(() => {
    const selection = this.selection();
    const term = selection.term ?? '';
    // A phrase overrides whole-word when both signals are present (#702), so a
    // phrase query shows only the phrase pill, never both.
    return selection.kind === 'search' && isWholeWordTerm(term) && !isPhraseTerm(term);
  });

  /** Whether the selection is a phrase search (quoted query). The pill is the
   *  only sign the words matched as one exact run rather than each anywhere —
   *  mirrors the whole-word badge (#702). */
  protected readonly showPhraseBadge = computed(() => {
    const selection = this.selection();
    return selection.kind === 'search' && isPhraseTerm(selection.term ?? '');
  });

  /** The heading's leading icon for a fixed view, or null for a tag or a
   *  subscription (their glyph and favicon already lead the heading) (#411). */
  protected readonly titleIcon = computed(() => FIXED_VIEW_ICON[this.selection().kind] ?? null);

  /** A localised "last refreshed 5 min ago" label for a single-feed selection
   *  or the for-you list, or null when it doesn't apply (neither, or never
   *  generated/fetched). */
  protected readonly lastRefreshedLabel = computed(() => {
    const iso = this.lastRefreshed();
    if (!isSingleStreamView(this.selection()) || !iso) return null;
    return relativeTime(iso, this.language.lang());
  });

  /** A localised "next refresh in 20 min" label beside the last-refreshed hint,
   *  for a single feed only — a ranking (for-you) has no next fetch, nor does a
   *  feed with no scheduled run. */
  protected readonly nextRefreshLabel = computed(() => {
    const iso = this.nextRefresh();
    if (this.selection().kind !== 'subscription' || !iso) return null;
    return relativeTimeUntil(iso, this.language.lang());
  });

  /** Land focus on the title. preventScroll avoids an outer-ancestor scroll,
   *  since the header sits outside the scroller. */
  focusTitle(): void {
    this.titleElement()?.nativeElement.focus({ preventScroll: true });
  }
}
