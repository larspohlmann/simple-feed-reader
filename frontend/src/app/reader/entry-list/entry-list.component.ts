// src/app/reader/entry-list/entry-list.component.ts
import {
  Component,
  ElementRef,
  OnDestroy,
  computed,
  effect,
  inject,
  input,
  output,
  signal,
  viewChild,
} from '@angular/core';
import { RouterLink } from '@angular/router';
import { IconComponent } from '../../shared/icon/icon.component';
import { EntryRowComponent } from '../entry-row/entry-row.component';
import { EntryHeroComponent } from '../magazine/entry-hero.component';
import { EntryCompactComponent } from '../magazine/entry-compact.component';
import { SourceGroupComponent } from '../magazine/source-group.component';
import { MagazineBlock, planMagazine } from '../magazine/magazine-planner';
import { ReadingLayout } from '../reading-layout.service';
import { EntryDto, SubscriptionTagDto } from '../models';
import { Selection } from '../query';
import { Problem } from '../../core/problem';
import { LayoutService } from '../layout.service';
import { ListScrollMemory } from '../list-scroll-memory';
import { nextHeaderHidden } from '../header-scroll';

// Scroll-restore settle window: re-assert the target for at most this many frames,
// stopping early once the content height has held steady for this many in a row.
const MAX_SETTLE_FRAMES = 30;
const SETTLE_STABLE_FRAMES = 3;

@Component({
  selector: 'app-entry-list',
  imports: [
    RouterLink,
    IconComponent,
    EntryRowComponent,
    EntryHeroComponent,
    EntryCompactComponent,
    SourceGroupComponent,
  ],
  templateUrl: './entry-list.component.html',
  styleUrl: './entry-list.component.scss',
})
export class EntryListComponent implements OnDestroy {
  readonly title = input.required<string>();
  readonly entries = input.required<EntryDto[]>();
  readonly loading = input.required<boolean>();
  readonly loadingMore = input.required<boolean>();
  readonly error = input.required<Problem | null>();
  readonly hasMore = input.required<boolean>();
  readonly canMarkAllRead = input.required<boolean>();
  readonly selection = input.required<Selection>();
  readonly openEntryId = input.required<number | null>();
  readonly layout = input<ReadingLayout>('list');
  /** Feed tags keyed by subscription id, used to render each entry's tag pills. */
  readonly feedTags = input<Map<number, SubscriptionTagDto[]>>(new Map());

  readonly loadMore = output<void>();
  readonly markAllRead = output<void>();
  readonly favorite = output<EntryDto>();
  readonly keep = output<EntryDto>();
  readonly read = output<EntryDto>();
  readonly open = output<EntryDto>();

  readonly blocks = computed<MagazineBlock[]>(() =>
    planMagazine(this.entries(), this.selection().kind !== 'subscription', !this.hasMore()),
  );

  private readonly screen = inject(LayoutService);
  private readonly scroll = inject(ListScrollMemory);
  private readonly host = inject(ElementRef<HTMLElement>);

  constructor() {
    // Capture so we hear the gesture even though scroll events fire on inner .rows;
    // passive so we never block scrolling. Both cancel an in-flight scroll restore.
    const host = this.host.nativeElement;
    host.addEventListener('wheel', this.onUserScrollIntent, { passive: true, capture: true });
    host.addEventListener('touchmove', this.onUserScrollIntent, { passive: true, capture: true });
  }
  // On a narrow layout the list header collapses to a slim tag-name-only bar as
  // you scroll down the list, expanding again on scroll up (same direction logic
  // as the app header's hide-on-scroll). Always expanded on wide screens.
  readonly collapsed = signal(false);
  private lastScrollTop = 0;

  // A new selection reloads the list from the top, and a resize past the wide
  // breakpoint restores the full-size header — expand the bar in both cases.
  private readonly _resetCollapse = effect(() => {
    this.selection();
    this.screen.isWide();
    this.collapsed.set(false);
    this.lastScrollTop = 0;
  });

  onRowsScroll(e: Event): void {
    const el = e.target as HTMLElement | null;
    if (!el || typeof el.scrollTop !== 'number') return;
    const top = el.scrollTop;
    this.collapsed.set(
      nextHeaderHidden(this.collapsed(), this.lastScrollTop, top, this.screen.isWide()),
    );
    this.lastScrollTop = top;
    // Remember where the user is so a browser resume-reload (iOS/Brave discard the
    // tab and reload it) can drop them back here rather than at the top.
    this.scroll.save(this.selection(), top);
  }

  tagsFor(subscriptionId: number): SubscriptionTagDto[] {
    return this.feedTags().get(subscriptionId) ?? [];
  }

  blockKey(b: MagazineBlock): string {
    return b.kind === 'group'
      ? `group-${b.subscriptionId}-${b.entries[0].id}`
      : `${b.kind}-${b.entry.id}`;
  }
  hero(b: MagazineBlock) {
    return b as Extract<MagazineBlock, { kind: 'hero' }>;
  }
  feat(b: MagazineBlock) {
    return b as Extract<MagazineBlock, { kind: 'feature' }>;
  }
  compact(b: MagazineBlock) {
    return b as Extract<MagazineBlock, { kind: 'compact' }>;
  }
  grp(b: MagazineBlock) {
    return b as Extract<MagazineBlock, { kind: 'group' }>;
  }

  private readonly rows = viewChild<ElementRef<HTMLElement>>('rows');
  private readonly sentinel = viewChild<ElementRef<HTMLElement>>('sentinel');
  private observer?: IntersectionObserver;

  // Re-observe whenever the sentinel appears/disappears (hasMore toggles it).
  private readonly _wire = effect(() => {
    const node = this.sentinel()?.nativeElement;
    const root = this.rows()?.nativeElement ?? null;
    this.observer?.disconnect();
    if (node && typeof IntersectionObserver !== 'undefined') {
      this.observer = new IntersectionObserver(
        (es) => {
          if (es.some((e) => e.isIntersecting) && this.hasMore() && !this.loadingMore())
            this.loadMore.emit();
        },
        { root, rootMargin: '300px' },
      );
      this.observer.observe(node);
    }
  });

  // Restore the remembered scroll offset when a fresh load finishes. Gated on the
  // loading edge (true -> false) so it fires once per genuine reload/selection —
  // never on "load more" (which toggles loadingMore, not loading) and never on
  // opening/closing an article (the list stays mounted beneath the overlay, so no
  // remount and no reload). That gating is what keeps the return-from-article
  // position exactly native, avoiding the earlier restore-glitch.
  private wasLoading = false;
  private readonly _restoreScroll = effect(() => {
    const loading = this.loading();
    const el = this.rows()?.nativeElement;
    if (loading) {
      this.wasLoading = true;
      return;
    }
    // Wait for the scroll container to render (it only exists once entries show),
    // then land the user back where they were before the page was reloaded.
    if (this.wasLoading && el) {
      this.wasLoading = false;
      this.applyScroll(el, this.scroll.read(this.selection()));
    }
  });

  private applyScroll(el: HTMLElement, top: number): void {
    this.cancelSettle();
    // Seed the hide-on-scroll baseline so the very next scroll compares against
    // the restored position, not 0.
    if (top <= 0) {
      this.lastScrollTop = el.scrollTop;
      return;
    }
    el.scrollTop = top; // immediate rough landing so the list never flashes at the top
    this.lastScrollTop = el.scrollTop;
    this.settleTo(el, top);
  }

  // A resume-reload re-renders the whole list from scratch, and block heights firm
  // up over the next few frames (fonts, images, magazine planning). A single early
  // scrollTop set gets nudged off by the browser's scroll-anchoring as that happens,
  // so re-assert the target each frame until the content height stops changing —
  // then the final landing is exact. Aborts the moment the user scrolls (see the
  // wheel/touch listeners) so it never fights a real gesture.
  private settleRaf = 0;
  private settleAbort = false;
  private settleTo(el: HTMLElement, target: number): void {
    if (typeof requestAnimationFrame === 'undefined') return;
    this.settleAbort = false;
    let frames = 0;
    let stableFrames = 0;
    let lastHeight = -1;
    const step = (): void => {
      if (this.settleAbort) return;
      el.scrollTop = target;
      this.lastScrollTop = el.scrollTop;
      const height = el.scrollHeight;
      stableFrames = height === lastHeight ? stableFrames + 1 : 0;
      lastHeight = height;
      if (++frames < MAX_SETTLE_FRAMES && stableFrames < SETTLE_STABLE_FRAMES) {
        this.settleRaf = requestAnimationFrame(step);
      }
    };
    this.settleRaf = requestAnimationFrame(step);
  }

  private cancelSettle(): void {
    this.settleAbort = true;
    if (this.settleRaf && typeof cancelAnimationFrame !== 'undefined') {
      cancelAnimationFrame(this.settleRaf);
    }
    this.settleRaf = 0;
  }

  /** A real scroll gesture during the settle window wins over the restore. */
  private readonly onUserScrollIntent = (): void => this.cancelSettle();

  ngOnDestroy(): void {
    this.observer?.disconnect();
    this.cancelSettle();
    const host = this.host.nativeElement;
    host.removeEventListener('wheel', this.onUserScrollIntent, { capture: true });
    host.removeEventListener('touchmove', this.onUserScrollIntent, { capture: true });
  }
}
