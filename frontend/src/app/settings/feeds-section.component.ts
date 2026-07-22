// src/app/settings/feeds-section.component.ts
import { Component, computed, inject } from '@angular/core';
import { IconComponent } from '../shared/icon/icon.component';
import { SubscriptionsStore } from '../reader/subscriptions.store';
import { ManageActions } from '../reader/manage/manage-actions.service';
import { SubscriptionDto } from '../reader/models';

@Component({
  selector: 'app-feeds-section',
  imports: [IconComponent],
  template: `
    <section>
      <h2>Feeds</h2>
      @if (feeds().length === 0) {
        <p class="muted">No feeds yet — add one from the reader.</p>
      } @else {
        <ul class="list">
          @for (s of feeds(); track s.id) {
            <li class="feed">
              <div class="info">
                <span class="title">{{ s.title }}</span>
                <span class="sub">
                  <span class="badge" [attr.data-s]="s.status" [title]="statusHint(s.status)">{{
                    s.status
                  }}</span>
                  @for (t of s.tags; track t.id) {
                    <span class="chip">
                      <span class="dot" [style.background]="t.color || 'var(--text-muted)'"></span>
                      {{ t.name }}
                    </span>
                  }
                  @if (s.unreadCount > 0) {
                    <span class="count">{{ s.unreadCount }} unread</span>
                  }
                </span>
              </div>
              <div class="acts">
                <button (click)="manage.editSubscription(s)">
                  <app-icon name="edit" [size]="16" /> Rename &amp; tags
                </button>
                <button class="danger" (click)="manage.unsubscribe(s)">
                  <app-icon name="delete" [size]="16" /> Unsubscribe
                </button>
              </div>
            </li>
          }
        </ul>
      }
    </section>
  `,
  styles: [
    `
      h2 {
        font-size: var(--fs-lg);
        margin: 0 0 var(--space-3);
      }
      .muted {
        color: var(--text-muted);
      }
      .list {
        list-style: none;
        margin: 0;
        padding: 0;
        display: flex;
        flex-direction: column;
        gap: var(--space-1);
      }
      .feed {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: var(--space-3);
        padding: var(--space-3);
        border: 1px solid var(--border);
        border-radius: var(--radius);
        background: var(--surface-1);
      }
      .info {
        display: flex;
        flex-direction: column;
        gap: 2px;
        min-width: 0;
      }
      .title {
        color: var(--text-primary);
      }
      .sub {
        display: flex;
        flex-wrap: wrap;
        align-items: center;
        gap: var(--space-2);
        font-size: var(--fs-sm);
        color: var(--text-muted);
      }
      .badge {
        padding: 0 var(--space-2);
        border-radius: var(--radius);
        background: var(--surface-0);
        color: var(--text-secondary);
        text-transform: capitalize;
      }
      .badge[data-s='erroring'] {
        background: var(--bg-danger);
        color: var(--danger);
      }
      .badge[data-s='gone'] {
        background: var(--bg-danger);
        color: var(--danger);
      }
      .chip {
        display: inline-flex;
        align-items: center;
        gap: var(--space-1);
      }
      .dot {
        width: 8px;
        height: 8px;
        border-radius: 50%;
      }
      .acts {
        display: flex;
        gap: var(--space-2);
        flex: 0 0 auto;
      }
      .acts button {
        display: inline-flex;
        align-items: center;
        gap: var(--space-1);
        padding: var(--space-1) var(--space-3);
        border: 1px solid var(--border-strong);
        border-radius: var(--radius);
        background: var(--surface-1);
        color: var(--text-primary);
        cursor: pointer;
      }
      .acts button.danger {
        color: var(--danger);
        border-color: var(--danger);
      }
    `,
  ],
})
export class FeedsSectionComponent {
  readonly subs = inject(SubscriptionsStore);
  readonly manage = inject(ManageActions);

  readonly feeds = computed(() =>
    [...this.subs.subscriptions()].sort((a, b) => a.title.localeCompare(b.title)),
  );

  statusHint(status: SubscriptionDto['status']): string {
    if (status === 'erroring') return 'This feed last failed to fetch. A refresh will retry it.';
    if (status === 'gone') return 'This feed appears gone. A refresh will retry it.';
    return 'This feed is healthy.';
  }
}
