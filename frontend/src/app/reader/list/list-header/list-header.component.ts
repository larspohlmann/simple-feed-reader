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
import { NetworkLogoComponent } from '../../../shared/network-logo/network-logo.component';
import { ListOrder, TagDto } from '../../models';
import {
  Selection,
  SelectionKind,
  canScopedRefresh,
  hasListOrder,
  hasUnreadFilter,
  isSingleStreamView,
  isUnreadOnly,
  listOrderOf,
} from '../../query/query';
import { isPhraseTerm, isWholeWordTerm } from '../../query/search-term';
import { relativeTime, relativeTimeUntil } from '../../format';
import { LanguageService } from '../../../core/i18n/language.service';

/** Each list's heading icon; null means the heading leads with its own glyph, favicon or logo. */
const LIST_ICON: Record<SelectionKind, string | null> = {
  all: 'inbox',
  tag: null,
  subscription: null,
  network: null,
  group: null,
  favorites: 'star',
  kept: 'bookmark',
  shared: 'share',
  viewed: 'history',
  'for-you': 'auto_awesome',
  trending: 'trending_up',
  'saved-searches': 'saved_search',
  'saved-search': null,
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
    NetworkLogoComponent,
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
  readonly titleAvatarUrl = input<string | null>(null);
  readonly lastRefreshed = input<string | null>(null);
  readonly nextRefresh = input<string | null>(null);
  readonly headerLeading = input<TemplateRef<unknown> | null>(null);
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

  protected readonly unreadOnly = computed(() => isUnreadOnly(this.selection()));

  protected readonly titleNetwork = computed(() => {
    const selection = this.selection();
    return selection.kind === 'network' ? selection.network : null;
  });

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
    // A phrase overrides whole-word when both signals are present (#702), so a
    // phrase query shows only the phrase pill, never both.
    return (
      selection.kind === 'search' &&
      isWholeWordTerm(selection.term) &&
      !isPhraseTerm(selection.term)
    );
  });

  /** Whether the selection is a phrase search (quoted query). The pill is the
   *  only sign the words matched as one exact run rather than each anywhere —
   *  mirrors the whole-word badge (#702). */
  protected readonly showPhraseBadge = computed(() => {
    const selection = this.selection();
    return selection.kind === 'search' && isPhraseTerm(selection.term);
  });

  /** The leading icon; null means the heading leads with its own glyph, favicon or logo. */
  protected readonly titleIcon = computed(() => LIST_ICON[this.selection().kind]);

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
