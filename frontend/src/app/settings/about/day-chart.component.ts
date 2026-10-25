import { ChangeDetectionStrategy, Component, computed, inject, input, signal } from '@angular/core';
import { TranslocoPipe } from '@jsverse/transloco';
import { LanguageService } from '../../core/i18n/language.service';
import { formatDayInMonth } from '../../reader/format';
import { ChartColumn, ChartDay, buildDayChart } from './day-chart';

const CHART_WIDTH = 300;
const CHART_HEIGHT = 96;

/** A 30-day area chart with one hover/focus target per day; unknown days are gaps. */
@Component({
  selector: 'app-day-chart',
  imports: [TranslocoPipe],
  templateUrl: './day-chart.component.html',
  styleUrl: './day-chart.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class DayChartComponent {
  private readonly language = inject(LanguageService);
  private readonly hovered = signal<number | null>(null);

  readonly days = input.required<ChartDay[]>();

  readonly chart = computed(() => buildDayChart(this.days(), CHART_WIDTH, CHART_HEIGHT));

  /** The hovered day, or the busiest day at rest so the chart always names one number. */
  readonly active = computed(() => {
    const index = this.hovered();
    const columns = this.chart().columns;

    return index === null ? this.busiestColumn(columns) : (columns[index] ?? null);
  });

  hover(index: number): void {
    this.hovered.set(index);
  }

  clearHover(): void {
    this.hovered.set(null);
  }

  dayLabel(iso: string): string {
    // A UTC calendar day: anchored to local midnight so a negative-offset browser keeps the same date.
    return formatDayInMonth(`${iso}T00:00:00`, this.language.lang());
  }

  private busiestColumn(columns: ChartColumn[]): ChartColumn | null {
    return columns.reduce<ChartColumn | null>(
      (best, column) =>
        best === null || (column.count ?? -1) > (best.count ?? -1) ? column : best,
      null,
    );
  }
}
