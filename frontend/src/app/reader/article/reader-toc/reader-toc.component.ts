import { Component, computed, input, model, output } from '@angular/core';
import { TranslocoPipe } from '@jsverse/transloco';
import { TocEntry } from '../reading/reading-toc';
import { ReaderPanelComponent } from '../reader-panel/reader-panel.component';

/** Below this many headings an article is too short to warrant a contents list. */
const TOC_MIN_HEADINGS = 3;

/** The article's collapsible table of contents. Collapsed by default; only
 *  shown once an article has enough headings. */
@Component({
  selector: 'app-reader-toc',
  imports: [TranslocoPipe, ReaderPanelComponent],
  templateUrl: './reader-toc.component.html',
  styleUrl: './reader-toc.component.scss',
})
export class ReaderTocComponent {
  readonly entries = input.required<TocEntry[]>();
  readonly open = model(false);
  readonly jump = output<string>();

  protected readonly visible = computed(() => this.entries().length >= TOC_MIN_HEADINGS);
}
