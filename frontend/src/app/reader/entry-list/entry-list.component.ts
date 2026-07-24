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
import { nextHeaderHidden } from '../header-scroll';

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

  ngOnDestroy(): void {
    this.observer?.disconnect();
  }
}
