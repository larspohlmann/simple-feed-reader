// src/app/reader/subscriptions.store.ts
import { HttpErrorResponse } from '@angular/common/http';
import { Injectable, computed, inject, signal } from '@angular/core';
import { Problem, parseProblem } from '../core/problem';
import { ReaderApi } from './reader-api';
import { SubscriptionDto, TagDto } from './models';

export interface TagNode {
  tag: TagDto;
  subscriptions: SubscriptionDto[];
  unreadCount: number;
}

export function buildTagTree(subs: SubscriptionDto[]): TagNode[] {
  const byId = new Map<number, TagNode>();
  for (const s of subs) {
    for (const t of s.tags) {
      let node = byId.get(t.id);
      if (!node) {
        node = { tag: t, subscriptions: [], unreadCount: 0 };
        byId.set(t.id, node);
      }
      node.subscriptions.push(s);
      node.unreadCount += s.unreadCount;
    }
  }
  return [...byId.values()].sort((a, b) => a.tag.name.localeCompare(b.tag.name));
}

export function untaggedSubs(subs: SubscriptionDto[]): SubscriptionDto[] {
  return subs.filter((s) => s.tags.length === 0);
}

export function sumUnread(subs: SubscriptionDto[]): number {
  return subs.reduce((n, s) => n + s.unreadCount, 0);
}

type ZeroTarget = 'all' | { tag: number } | { subscription: number };

@Injectable({ providedIn: 'root' })
export class SubscriptionsStore {
  private readonly api = inject(ReaderApi);

  readonly subscriptions = signal<SubscriptionDto[]>([]);
  readonly loading = signal(false);
  readonly error = signal<Problem | null>(null);

  readonly tagTree = computed(() => buildTagTree(this.subscriptions()));
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
