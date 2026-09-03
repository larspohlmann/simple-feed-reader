import { Injectable, computed, signal } from '@angular/core';
import { CatalogCategoryDto } from './catalog.models';

/**
 * Client-side picker state. Nothing is written until Subscribe, so this is the
 * whole model of "what have I chosen".
 *
 * Already-subscribed feeds are LOCKED: they render selected and disabled, and
 * they are excluded from selectedIds() because re-submitting them would only
 * produce skips.
 */
@Injectable()
export class CatalogSelection {
  private readonly categories = signal<CatalogCategoryDto[]>([]);
  private readonly picked = signal<ReadonlySet<number>>(new Set());

  /** feedId -> categoryId, rebuilt whenever the catalog changes. */
  private readonly categoryOf = computed(() => {
    const map = new Map<number, number>();
    for (const category of this.categories()) {
      for (const feed of category.feeds) {
        map.set(feed.id, category.id);
      }
    }
    return map;
  });

  private readonly locked = computed(() => {
    const ids = new Set<number>();
    for (const category of this.categories()) {
      for (const feed of category.feeds) {
        if (feed.subscribed) {
          ids.add(feed.id);
        }
      }
    }
    return ids;
  });

  readonly selectedIds = computed(() => [...this.picked()]);
  readonly selectedCount = computed(() => this.picked().size);

  readonly selectedCategoryCount = computed(() => {
    const of = this.categoryOf();
    const categories = new Set<number>();
    for (const id of this.picked()) {
      const categoryId = of.get(id);
      if (categoryId !== undefined) {
        categories.add(categoryId);
      }
    }
    return categories.size;
  });

  setCategories(categories: CatalogCategoryDto[]): void {
    this.categories.set(categories);
    this.picked.set(new Set());
  }

  isLocked(feedId: number): boolean {
    return this.locked().has(feedId);
  }

  /** Locked feeds read as selected so the card renders ticked and disabled. */
  isSelected(feedId: number): boolean {
    return this.locked().has(feedId) || this.picked().has(feedId);
  }

  selectedInCategory(categoryId: number): number {
    const of = this.categoryOf();
    let count = 0;
    for (const id of this.picked()) {
      if (of.get(id) === categoryId) {
        count++;
      }
    }
    return count;
  }

  toggle(feedId: number): void {
    if (this.locked().has(feedId)) {
      return;
    }
    const next = new Set(this.picked());
    if (!next.delete(feedId)) {
      next.add(feedId);
    }
    this.picked.set(next);
  }

  selectAll(categoryId: number): void {
    const category = this.categories().find((c) => c.id === categoryId);
    if (!category) {
      return;
    }
    const next = new Set(this.picked());
    for (const feed of category.feeds) {
      if (!feed.subscribed) {
        next.add(feed.id);
      }
    }
    this.picked.set(next);
  }

  clearCategory(categoryId: number): void {
    const category = this.categories().find((c) => c.id === categoryId);
    if (!category) {
      return;
    }
    const next = new Set(this.picked());
    for (const feed of category.feeds) {
      next.delete(feed.id);
    }
    this.picked.set(next);
  }
}
