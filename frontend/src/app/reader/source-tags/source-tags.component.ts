import { Component, input } from '@angular/core';
import { RouterLink } from '@angular/router';
import { IconComponent } from '../../shared/icon/icon.component';
import { SubscriptionTagDto } from '../models';

/**
 * The tag pills shown in front of / below a source name across the reading UI
 * (entry cards, source groups, the article view). Each pill is a link that
 * filters the list to that tag. Clicks stop propagating so a pill inside a
 * clickable entry card filters instead of opening the entry. Renders nothing
 * when the feed carries no tags.
 */
@Component({
  selector: 'app-source-tags',
  imports: [RouterLink, IconComponent],
  template: `
    @if (tags().length) {
      <span class="pills">
        @for (t of tags(); track t.id) {
          <a
            class="pill"
            [routerLink]="[]"
            [queryParams]="{ tag: t.id, view: null, subscription: null, entry: null }"
            queryParamsHandling="merge"
            [attr.title]="'Filter by ' + t.name"
            (click)="$event.stopPropagation()"
          >
            @if (t.icon) {
              <app-icon
                [name]="t.icon"
                [size]="12"
                [style.color]="t.color || 'var(--text-muted)'"
              />
            } @else {
              <span class="dot" [style.background]="t.color || 'var(--text-muted)'"></span>
            }
            {{ t.name }}
          </a>
        }
      </span>
    }
  `,
  styles: `
    .pills {
      display: inline-flex;
      flex-wrap: wrap;
      gap: var(--space-1);
      vertical-align: middle;
    }
    .pill {
      display: inline-flex;
      align-items: center;
      gap: 4px;
      padding: 1px var(--space-2);
      border: 1px solid var(--border);
      border-radius: 999px;
      background: var(--surface-1);
      color: var(--text-secondary);
      font-size: var(--fs-xs, 0.72rem);
      line-height: 1.5;
      text-decoration: none;
      cursor: pointer;
    }
    .pill:hover {
      border-color: var(--border-strong);
      color: var(--text-primary);
    }
    .dot {
      width: 8px;
      height: 8px;
      border-radius: 50%;
      flex: none;
    }
  `,
})
export class SourceTagsComponent {
  readonly tags = input.required<SubscriptionTagDto[]>();
}
