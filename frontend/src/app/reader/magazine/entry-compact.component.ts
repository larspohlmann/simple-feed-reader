// src/app/reader/magazine/entry-compact.component.ts
import { Component, computed, input, output } from '@angular/core';
import { EntryDto } from '../models';
import { relativeTime } from '../format';

@Component({
  selector: 'app-entry-compact',
  template: `
    <article
      class="compact"
      role="button"
      tabindex="0"
      [class.read]="entry().isRead"
      (click)="open.emit(entry())"
      (keydown.enter)="open.emit(entry())"
      (keydown.space)="$event.preventDefault(); open.emit(entry())"
    >
      <span class="dot" [class.on]="!entry().isRead" aria-hidden="true"></span>
      <div class="body">
        <p class="kicker">{{ entry().source }} · {{ when() }}</p>
        <p class="title">{{ entry().title }}</p>
      </div>
    </article>
  `,
  styles: [
    `
      :host {
        display: block;
      }
      .compact {
        display: flex;
        gap: var(--space-3);
        align-items: baseline;
        padding: var(--space-3) var(--space-4);
        cursor: pointer;
      }
      .compact:hover {
        background: var(--surface-0);
      }
      .dot {
        width: 7px;
        height: 7px;
        border-radius: 50%;
        flex: 0 0 auto;
        border: 1px solid var(--border-strong);
      }
      .dot.on {
        background: var(--accent);
        border-color: var(--accent);
      }
      .body {
        min-width: 0;
      }
      .kicker {
        margin: 0;
        font-size: var(--fs-sm);
        color: var(--text-muted);
      }
      .title {
        margin: 0;
        color: var(--text-primary);
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
      }
      .compact.read .title {
        color: var(--text-secondary);
      }
    `,
  ],
})
export class EntryCompactComponent {
  readonly entry = input.required<EntryDto>();
  readonly open = output<EntryDto>();
  readonly when = computed(() => relativeTime(this.entry().publishedAt ?? this.entry().createdAt));
}
