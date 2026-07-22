// src/app/settings/tags-section.component.ts
import { Component, computed, inject } from '@angular/core';
import { IconComponent } from '../shared/icon/icon.component';
import { TagsStore } from '../reader/tags.store';
import { SubscriptionsStore } from '../reader/subscriptions.store';
import { ManageActions } from '../reader/manage/manage-actions.service';

@Component({
  selector: 'app-tags-section',
  imports: [IconComponent],
  template: `
    <section>
      <div class="head">
        <h2>Tags</h2>
        <button class="new" (click)="manage.createTag()">
          <app-icon name="add" [size]="16" /> New tag
        </button>
      </div>
      @if (tagsStore.tags().length === 0) {
        <p class="muted">No tags yet — create one to group your feeds.</p>
      } @else {
        <ul class="list">
          @for (t of tagsStore.tags(); track t.id) {
            <li class="tag">
              <span class="ident">
                <span class="dot" [style.background]="t.color || 'var(--text-muted)'"></span>
                @if (t.icon) {
                  <app-icon [name]="t.icon" [size]="18" />
                }
                <span class="name">{{ t.name }}</span>
                <span class="count"
                  >{{ usage()[t.id] || 0 }}
                  {{ (usage()[t.id] || 0) === 1 ? 'feed' : 'feeds' }}</span
                >
              </span>
              <span class="acts">
                <button (click)="manage.editTag(t)">
                  <app-icon name="edit" [size]="16" /> Edit
                </button>
                <button class="danger" (click)="manage.deleteTag(t)">
                  <app-icon name="delete" [size]="16" /> Delete
                </button>
              </span>
            </li>
          }
        </ul>
      }
    </section>
  `,
  styles: [
    `
      .head {
        display: flex;
        align-items: center;
        justify-content: space-between;
        margin-bottom: var(--space-3);
      }
      h2 {
        font-size: var(--fs-lg);
        margin: 0;
      }
      .new {
        display: inline-flex;
        align-items: center;
        gap: var(--space-1);
        padding: var(--space-1) var(--space-3);
        border: 1px solid var(--accent);
        border-radius: var(--radius);
        background: var(--accent);
        color: var(--on-accent);
        cursor: pointer;
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
      .tag {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: var(--space-3);
        padding: var(--space-3);
        border: 1px solid var(--border);
        border-radius: var(--radius);
        background: var(--surface-1);
      }
      .ident {
        display: flex;
        align-items: center;
        gap: var(--space-2);
        min-width: 0;
      }
      .dot {
        width: 10px;
        height: 10px;
        border-radius: 50%;
        flex: 0 0 auto;
      }
      .name {
        color: var(--text-primary);
      }
      .count {
        font-size: var(--fs-sm);
        color: var(--text-muted);
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
export class TagsSectionComponent {
  readonly tagsStore = inject(TagsStore);
  private readonly subs = inject(SubscriptionsStore);
  readonly manage = inject(ManageActions);

  /** feed count per tag id, derived from the subscription list. */
  readonly usage = computed<Record<number, number>>(() => {
    const map: Record<number, number> = {};
    for (const s of this.subs.subscriptions()) {
      for (const t of s.tags) map[t.id] = (map[t.id] ?? 0) + 1;
    }
    return map;
  });
}
