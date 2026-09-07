import { buildReadingChart } from './reading-chart';
import { ReadingDay } from '../reader/models';

function days(...counts: number[]): ReadingDay[] {
  return counts.map((count, index) => ({
    date: `2026-09-${String(index + 1).padStart(2, '0')}`,
    count,
  }));
}

describe('buildReadingChart', () => {
  it('reports no data and no peak for an all-zero window', () => {
    const chart = buildReadingChart(days(0, 0, 0), 300, 100);

    expect(chart.hasData).toBe(false);
    expect(chart.peak).toBeNull();
  });

  it('puts the peak on the busiest day', () => {
    const chart = buildReadingChart(days(1, 5, 2), 300, 100);

    expect(chart.hasData).toBe(true);
    // Busiest day is index 1 of three, so at the horizontal midpoint.
    expect(chart.peak?.xPercent).toBe(50);
  });

  it('gives one hover column per day spanning the full width', () => {
    const chart = buildReadingChart(days(1, 2), 300, 100);

    expect(chart.columns).toHaveLength(2);
    expect(chart.columns[0]).toMatchObject({
      date: '2026-09-01',
      count: 1,
      leftPercent: 0,
      widthPercent: 50,
    });
    expect(chart.columns[1].leftPercent).toBe(50);
  });

  it('centres a single day rather than drawing a zero-width line', () => {
    const chart = buildReadingChart(days(3), 300, 100);

    expect(chart.columns[0].xPercent).toBe(50);
    expect(chart.line).toContain('M150');
  });

  it('draws a closed area path from the baseline', () => {
    const chart = buildReadingChart(days(1, 2, 1), 300, 100);

    expect(chart.area.startsWith('M0 96')).toBe(true);
    expect(chart.area.endsWith('Z')).toBe(true);
  });
});
