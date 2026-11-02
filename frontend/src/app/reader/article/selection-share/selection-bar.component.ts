import { ChangeDetectionStrategy, Component, computed, inject, input, signal } from '@angular/core';
import { TranslocoPipe } from '@jsverse/transloco';
import { LayoutService } from '../../layout.service';
import { ArticleSelection } from './article-selection.service';
import { SelectedQuote } from './selected-quote';
import { SelectionActionsComponent } from './selection-actions.component';

/** The selection's actions docked at the foot of the article on a coarse pointer, while a quote is selected. */
@Component({
  selector: 'app-selection-bar',
  imports: [TranslocoPipe, SelectionActionsComponent],
  templateUrl: './selection-bar.component.html',
  styleUrl: './selection-bar.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
  host: {
    '(pointerdown)': 'pressing.set(true)',
    '(pointerup)': 'release()',
    '(pointercancel)': 'release()',
  },
})
export class SelectionBarComponent {
  readonly url = input.required<string | null>();
  readonly title = input('');

  private readonly selection = inject(ArticleSelection);
  private readonly screen = inject(LayoutService);
  /** A press may collapse the selection before its click; the bar stays for that click. */
  protected readonly pressing = signal(false);
  protected readonly quote = computed(() =>
    this.screen.isCoarse() && this.url() !== null ? this.shownQuote() : null,
  );

  /** The click follows the pointerup; the bar leaves only after it. */
  protected release(): void {
    setTimeout(() => this.pressing.set(false));
  }

  private shownQuote(): SelectedQuote | null {
    return this.selection.quote() ?? (this.pressing() ? this.selection.remembered() : null);
  }
}
