// src/app/discover/discover.component.ts
import { HttpErrorResponse } from '@angular/common/http';
import {
  ChangeDetectionStrategy,
  Component,
  ElementRef,
  HostListener,
  OnDestroy,
  computed,
  effect,
  inject,
  signal,
  viewChildren,
} from '@angular/core';
import { Router } from '@angular/router';
import { TranslocoPipe } from '@jsverse/transloco';
import { Problem, parseProblem } from '../core/problem';
import { IconComponent } from '../shared/icon/icon.component';
import { TagGlyphComponent } from '../shared/tag-glyph/tag-glyph.component';
import { OverlayPanelComponent } from '../shared/overlay-panel/overlay-panel.component';
import { SubscriptionsStore } from '../reader/subscriptions.store';
import { TagsStore } from '../reader/tags.store';
import { ActiveCategory } from './active-category';
import { CatalogApi } from './catalog-api';
import { CatalogStore } from './catalog.store';
import { CatalogSelection } from './catalog-selection.store';
import { CategoryChipsComponent } from './category-chips.component';
import { CategoryRailComponent } from './category-rail.component';
import { OnboardingSkip } from './onboarding-skip';
import { ButtonComponent } from '../shared/button/button.component';

/** How long a smooth scroll is given to settle before observations count again. */
const JUMP_SETTLE_MS = 700;

@Component({
  selector: 'app-discover',
  standalone: true,
  imports: [
    TranslocoPipe,
    IconComponent,
    TagGlyphComponent,
    ButtonComponent,
    OverlayPanelComponent,
    CategoryRailComponent,
    CategoryChipsComponent,
  ],
  changeDetection: ChangeDetectionStrategy.OnPush,
  providers: [CatalogSelection, ActiveCategory],
  templateUrl: './discover.component.html',
  styleUrl: './discover.component.scss',
})
export class DiscoverComponent implements OnDestroy {
  private readonly api = inject(CatalogApi);
  private readonly router = inject(Router);
  private readonly subs = inject(SubscriptionsStore);
  private readonly tags = inject(TagsStore);
  private readonly skip = inject(OnboardingSkip);
  private readonly catalog = inject(CatalogStore);

  readonly selection = inject(CatalogSelection);
  readonly active = inject(ActiveCategory);

  readonly categories = this.catalog.categories;
  readonly loading = this.catalog.loading;
  readonly submitting = signal(false);
  readonly error = signal<Problem | null>(null);

  /** Resolved and there is genuinely nothing to pick — nobody has imported a
   *  catalog yet. The shell will not redirect into this state, but the route
   *  stays reachable from Settings, so it has to say something honest. */
  readonly catalogEmpty = computed(() => this.catalog.resolved() && !this.catalog.hasEntries());

  private readonly sections = viewChildren<ElementRef<HTMLElement>>('section');
  private observer: IntersectionObserver | null = null;
  private settleTimer: ReturnType<typeof setTimeout> | null = null;

  /** categoryId -> picked count, recomputed for the rail and the chips. */
  readonly pickedByCategory = computed(() => {
    const counts: Record<number, number> = {};
    for (const category of this.categories()) {
      counts[category.id] = this.selection.selectedInCategory(category.id);
    }
    return counts;
  });

  /**
   * The footer sentence, as a translation key rather than an assembled string,
   * so each language keeps its own word order and inflection.
   *
   * Only four states are reachable: a feed belongs to exactly one category, so
   * "1 feed in 2 categories" cannot happen and needs no key. The zero case is
   * its own sentence because a count there says nothing about the selection —
   * "0 feeds in 0 categories" sits under a full picker and reads as a claim
   * that the catalog is empty (#146).
   */
  readonly summaryKey = computed(() => {
    const feeds = this.selection.selectedCount();
    if (feeds === 0) return 'discover.summaryNone';
    if (feeds === 1) return 'discover.summaryOne';

    return this.selection.selectedCategoryCount() === 1
      ? 'discover.summaryOneCategory'
      : 'discover.summaryManyCategories';
  });

  /** Selection state follows whatever the store holds, including a reload after
   *  an invalidate. */
  private readonly syncSelection = effect(() => {
    this.selection.setCategories(this.catalog.categories());
  });

  /**
   * (Re)wire the scroll-spy whenever the rendered sections change. The catalog
   * loads async (constructor → load() → HTTP), so `sections()` is empty on the
   * first frame and fills once the response lands. An `ngAfterViewInit` would
   * observe that empty first frame and never re-observe; an effect re-runs on
   * every change to the sections signal. Disconnect the prior observer before
   * building a new one so we never leak or double-observe.
   */
  private readonly wireObserver = effect(() => {
    const sections = this.sections();
    this.observer?.disconnect();
    if (typeof IntersectionObserver === 'undefined') return;

    // rootMargin pins the "active" band near the top of the viewport, so the
    // active category is the one whose header you just scrolled past — not
    // whichever section happens to occupy the most pixels.
    this.observer = new IntersectionObserver(
      (entries) => {
        for (const entry of entries) {
          if (!entry.isIntersecting) continue;
          const id = Number((entry.target as HTMLElement).dataset['categoryId']);
          if (!Number.isNaN(id)) this.active.observed(id);
        }
      },
      { rootMargin: '0px 0px -70% 0px', threshold: 0 },
    );
    for (const section of sections) this.observer.observe(section.nativeElement);
  });

  constructor() {
    this.load();
  }

  load(): void {
    this.error.set(null);
    this.catalog.load();
  }

  ngOnDestroy(): void {
    this.observer?.disconnect();
    if (this.settleTimer) clearTimeout(this.settleTimer);
  }

  onJump(categoryId: number): void {
    this.active.jumpTo(categoryId);
    const target = this.sections().find(
      (s) => Number(s.nativeElement.dataset['categoryId']) === categoryId,
    );
    target?.nativeElement.scrollIntoView({ behavior: 'smooth', block: 'start' });

    if (this.settleTimer) clearTimeout(this.settleTimer);
    this.settleTimer = setTimeout(() => this.active.settled(), JUMP_SETTLE_MS);
  }

  onSkip(): void {
    this.skip.remember();
    void this.router.navigate(['/']);
  }

  /** Escape closes the picker the same way the X and "Skip for now" do — the
   *  modal convention, and the reader's own dialogs behave this way too. */
  @HostListener('document:keydown.escape')
  onEscape(): void {
    this.onSkip();
  }

  /**
   * Subscribe, then navigate — and nothing else. The sweep is NOT started here:
   * the reader shell owns it, driven by "subscriptions exist that have never
   * been fetched", so there is no ordering between two components to get wrong.
   */
  onSubscribe(): void {
    const ids = this.selection.selectedIds();
    if (ids.length === 0 || this.submitting()) return;

    this.submitting.set(true);
    this.error.set(null);
    this.api.subscribe(ids).subscribe({
      next: () => {
        this.subs.load();
        this.tags.load();
        // Every picked feed is now `subscribed`, so the cached catalog is stale.
        this.catalog.invalidate();
        this.submitting.set(false);
        void this.router.navigate(['/']);
      },
      error: (e: HttpErrorResponse) => {
        this.error.set(parseProblem(e));
        this.submitting.set(false);
      },
    });
  }
}
