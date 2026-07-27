// src/app/discover/category-rail.component.ts
import { ChangeDetectionStrategy, Component, input, output } from '@angular/core';
import { TranslocoPipe } from '@jsverse/transloco';
import { TagGlyphComponent } from '../shared/tag-glyph/tag-glyph.component';
import { CatalogCategoryDto } from './catalog.models';

/**
 * Desktop navigation for the picker. Two jobs: jump to a category, and show how
 * many feeds have been picked from each one so "what have I chosen so far" is
 * answerable without scrolling back.
 *
 * Navigation only — clicking a row never selects a feed. The leading tinted
 * glyph mirrors the reader sidebar's tag treatment so the two read as one app.
 */
@Component({
  selector: 'app-category-rail',
  standalone: true,
  imports: [TranslocoPipe, TagGlyphComponent],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <nav class="rail" [attr.aria-label]="'discover.categories' | transloco">
      <ul>
        @for (category of categories(); track category.id) {
          <li>
            <button
              type="button"
              [class.active]="category.id === activeId()"
              [attr.aria-current]="category.id === activeId() ? 'true' : null"
              (click)="jump.emit(category.id)"
            >
              <app-tag-glyph [name]="category.icon" [color]="category.color" size="sm" />
              <span class="name">{{ category.name }}</span>
              <span class="count" [class.picked]="picked()[category.id] > 0">
                {{ picked()[category.id] || category.feeds.length }}
              </span>
            </button>
          </li>
        }
      </ul>
    </nav>
  `,
  styleUrl: './category-rail.component.scss',
})
export class CategoryRailComponent {
  readonly categories = input.required<CatalogCategoryDto[]>();
  readonly activeId = input<number | null>(null);
  /** categoryId -> how many of its feeds are picked. */
  readonly picked = input.required<Record<number, number>>();
  readonly jump = output<number>();
}
