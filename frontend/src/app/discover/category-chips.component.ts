// src/app/discover/category-chips.component.ts
import { ChangeDetectionStrategy, Component, input, output } from '@angular/core';
import { TranslocoPipe } from '@jsverse/transloco';
import { IconComponent } from '../shared/icon/icon.component';
import { CatalogCategoryDto } from './catalog.models';

/**
 * The rail, laid on its side, for viewports too narrow to carry it. Same state,
 * same jump behaviour — only the rendering differs, which is why ActiveCategory
 * lives outside both components. The leading tinted glyph matches the rail and
 * the reader sidebar.
 */
@Component({
  selector: 'app-category-chips',
  standalone: true,
  imports: [TranslocoPipe, IconComponent],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <nav class="chips" [attr.aria-label]="'discover.categories' | transloco">
      @for (category of categories(); track category.id) {
        <button
          type="button"
          [class.active]="category.id === activeId()"
          [attr.aria-current]="category.id === activeId() ? 'true' : null"
          (click)="jump.emit(category.id)"
        >
          <app-icon
            [name]="category.icon"
            [size]="16"
            [style.color]="
              category.id === activeId() ? 'currentColor' : category.color || 'var(--text-muted)'
            "
          />
          {{ category.name }}
          @if (picked()[category.id] > 0) {
            <span class="n">{{ picked()[category.id] }}</span>
          }
        </button>
      }
    </nav>
  `,
  styles: `
    .chips {
      display: none;
      position: sticky;
      top: 0;
      z-index: 2;
      gap: var(--space-1);
      padding: var(--space-2) var(--space-3);
      overflow-x: auto;
      border-bottom: 1px solid var(--border);
      background: var(--surface-1);
      scrollbar-width: none;
    }
    .chips::-webkit-scrollbar {
      display: none;
    }
    button {
      display: inline-flex;
      flex: none;
      gap: var(--space-1);
      align-items: center;
      padding: var(--space-1) var(--space-3);
      border: 1px solid var(--border);
      border-radius: 999px;
      background: var(--surface-1);
      color: var(--text-secondary);
      font-size: var(--fs-sm);
      white-space: nowrap;
      cursor: pointer;
    }
    button.active {
      border-color: var(--accent);
      background: var(--accent);
      color: var(--on-accent);
      font-weight: 600;
    }
    .n {
      margin-left: var(--space-1);
      font-weight: 700;
    }
    @media (max-width: 800px) {
      .chips {
        display: flex;
      }
    }
  `,
})
export class CategoryChipsComponent {
  readonly categories = input.required<CatalogCategoryDto[]>();
  readonly activeId = input<number | null>(null);
  readonly picked = input.required<Record<number, number>>();
  readonly jump = output<number>();
}
