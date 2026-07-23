// src/app/reader/subscriptions.store.ts
import { HttpErrorResponse } from '@angular/common/http';
import { Injectable, computed, inject, signal } from '@angular/core';
import { Problem, parseProblem } from '../core/problem';
import { ReaderApi } from './reader-api';
import { TagsStore } from './tags.store';
import { SubscriptionDto, TagDto } from './models';

export interface TagNode {
  tag: TagDto;
  subscriptions: SubscriptionDto[];
  unreadCount: number;
}

/** This feed's order within a given tag (the embedded join position); a large
 *  fallback keeps feeds the tag doesn't list after the ones it does. */
function feedPositionInTag(sub: SubscriptionDto, tagId: number): number {
  const t = sub.tags.find((x) => x.id === tagId);
  return t ? t.position : Number.MAX_SAFE_INTEGER;
}

/**
 * Build the sidebar tag tree. Tag NODES and their order come from `orderedTags`
 * (the full tag list, already in `tag.position` order) so every tag shows —
 * including empty ones, which you can drop feeds onto and reorder. Each node's
 * feeds come from `subs` that carry the tag, ordered by their per-tag position.
 *
 * With no `orderedTags` (before the tag list has loaded), fall back to the tags
 * embedded on subscriptions, name-ordered — so tagged feeds never briefly vanish.
 */
export function buildTagTree(subs: SubscriptionDto[], orderedTags: TagDto[] = []): TagNode[] {
  const tags = orderedTags.length > 0 ? orderedTags : embeddedTagsByName(subs);
  return tags.map((tag) => {
    const feeds = subs
      .filter((s) => s.tags.some((t) => t.id === tag.id))
      .sort(
        (a, b) =>
          feedPositionInTag(a, tag.id) - feedPositionInTag(b, tag.id) ||
          a.title.localeCompare(b.title),
      );
    return {
      tag,
      subscriptions: feeds,
      unreadCount: feeds.reduce((n, s) => n + s.unreadCount, 0),
    };
  });
}

/** Fallback tag set derived from subscriptions (only non-empty tags), by name. */
function embeddedTagsByName(subs: SubscriptionDto[]): TagDto[] {
  const byId = new Map<number, TagDto>();
  for (const s of subs) {
    for (const t of s.tags) {
      if (!byId.has(t.id)) {
        byId.set(t.id, { id: t.id, name: t.name, color: t.color, icon: t.icon, position: 0 });
      }
    }
  }
  return [...byId.values()].sort((a, b) => a.name.localeCompare(b.name));
}

export function untaggedSubs(subs: SubscriptionDto[]): SubscriptionDto[] {
  return subs.filter((s) => s.tags.length === 0).sort((a, b) => a.position - b.position);
}

export function sumUnread(subs: SubscriptionDto[]): number {
  return subs.reduce((n, s) => n + s.unreadCount, 0);
}

type ZeroTarget = 'all' | { tag: number } | { subscription: number };

@Injectable({ providedIn: 'root' })
export class SubscriptionsStore {
  private readonly api = inject(ReaderApi);
  private readonly tags = inject(TagsStore);

  readonly subscriptions = signal<SubscriptionDto[]>([]);
  readonly loading = signal(false);
  readonly error = signal<Problem | null>(null);

  readonly tagTree = computed(() => buildTagTree(this.subscriptions(), this.tags.tags()));
  readonly untagged = computed(() => untaggedSubs(this.subscriptions()));
  readonly totalUnread = computed(() => sumUnread(this.subscriptions()));

  load(): void {
    this.loading.set(true);
    this.error.set(null);
    this.api.subscriptions().subscribe({
      next: (r) => {
        this.subscriptions.set(r.subscriptions);
        this.loading.set(false);
      },
      error: (e: HttpErrorResponse) => {
        this.error.set(parseProblem(e));
        this.loading.set(false);
      },
    });
  }

  decrementUnread(subscriptionId: number, by = 1): void {
    this.subscriptions.update((subs) =>
      subs.map((s) =>
        s.id === subscriptionId ? { ...s, unreadCount: Math.max(0, s.unreadCount - by) } : s,
      ),
    );
  }

  incrementUnread(subscriptionId: number, by = 1): void {
    this.subscriptions.update((subs) =>
      subs.map((s) => (s.id === subscriptionId ? { ...s, unreadCount: s.unreadCount + by } : s)),
    );
  }

  zeroUnread(target: ZeroTarget): void {
    this.subscriptions.update((subs) =>
      subs.map((s) => {
        if (target === 'all') return { ...s, unreadCount: 0 };
        if ('tag' in target)
          return s.tags.some((t) => t.id === target.tag) ? { ...s, unreadCount: 0 } : s;
        return s.id === target.subscription ? { ...s, unreadCount: 0 } : s;
      }),
    );
  }
}
