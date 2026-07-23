// src/app/reader/magazine/source-group.component.ts
import { Component, input, output } from '@angular/core';
import { RouterLink } from '@angular/router';
import { IconComponent } from '../../shared/icon/icon.component';
import { EntryCompactComponent } from './entry-compact.component';
import { EntryDto } from '../models';

@Component({
  selector: 'app-source-group',
  imports: [RouterLink, IconComponent, EntryCompactComponent],
  template: `
    <div class="group">
      <p class="ghead"><span class="dot" aria-hidden="true"></span>{{ source() }}</p>
      <div class="items">
        @for (item of entries(); track item.id) {
          <div class="item">
            <app-entry-compact [entry]="item" (open)="open.emit($event)" />
          </div>
        }
      </div>
      <a
        class="more"
        [routerLink]="[]"
        [queryParams]="{
          subscription: subscriptionId(),
          view: null,
          tag: null,
          entry: null,
          unread: '0',
        }"
        queryParamsHandling="merge"
      >
        {{ moreCount() > 0 ? moreCount() + ' more from ' + source() : 'More from ' + source() }}
        <app-icon name="arrow_forward" [size]="16" />
      </a>
    </div>
  `,
  styles: [
    `
      :host {
        display: block;
      }
      .group {
        background: var(--surface-2);
        border: 1px solid var(--border);
        border-radius: 12px;
        overflow: hidden;
      }
      .ghead {
        display: flex;
        align-items: center;
        gap: var(--space-2);
        margin: 0;
        padding: var(--space-3) var(--space-4);
        font-size: var(--fs-sm);
        font-weight: 500;
        color: var(--text-secondary);
        border-bottom: 1px solid var(--border);
      }
      .dot {
        width: 8px;
        height: 8px;
        border-radius: 50%;
        background: var(--accent);
      }
      .item:not(:last-child) {
        border-bottom: 1px solid var(--border);
      }
      .more {
        display: flex;
        align-items: center;
        gap: var(--space-1);
        padding: var(--space-3) var(--space-4);
        border-top: 1px solid var(--border);
        color: var(--accent);
        text-decoration: none;
        font-size: var(--fs-sm);
      }
      .more:hover {
        background: var(--surface-0);
      }
    `,
  ],
})
export class SourceGroupComponent {
  readonly source = input.required<string>();
  readonly subscriptionId = input.required<number>();
  readonly entries = input.required<EntryDto[]>();
  readonly moreCount = input.required<number>();
  readonly open = output<EntryDto>();
}
