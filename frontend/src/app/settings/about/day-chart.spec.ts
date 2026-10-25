import { ChartDay, buildDayChart, hasActivity } from './day-chart';

function days(...counts: (number | null)[]): ChartDay[] {
  return counts.map((count, index) => ({
    date: `2026-09-${String(index + 1).padStart(2, '0')}`,
    count,
  }));
}

describe('buildDayChart', () => {
  it('reports no peak for an all-zero window', () => {
    const chart = buildDayChart(days(0, 0, 0), 300, 100);

    expect(chart.peak).toBeNull();
  });

  it('puts the peak on the busiest day', () => {
    const chart = buildDayChart(days(1, 5, 2), 300, 100);

    // Busiest day is index 1 of three, so at the horizontal midpoint.
    expect(chart.peak?.xPercent).toBe(50);
  });

  it('gives one hover column per day spanning the full width', () => {
    const chart = buildDayChart(days(1, 2), 300, 100);

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
    const chart = buildDayChart(days(3), 300, 100);

    expect(chart.columns[0].xPercent).toBe(50);
    expect(chart.line).toContain('M150');
  });

  it('draws a closed area path from the baseline', () => {
    const chart = buildDayChart(days(1, 2, 1), 300, 100);

    expect(chart.area.startsWith('M0 96')).toBe(true);
    expect(chart.area.endsWith('Z')).toBe(true);
  });

  it('breaks the line and the area around an unknown day', () => {
    const chart = buildDayChart(days(1, 2, null, 3, 4), 300, 100);

    expect(chart.line.match(/M/g)).toHaveLength(2);
    expect(chart.area.match(/Z/g)).toHaveLength(2);
  });

  it('keeps a hover column for an unknown day with no count', () => {
    const chart = buildDayChart(days(null, 2), 300, 100);

    expect(chart.columns[0]).toMatchObject({ date: '2026-09-01', count: null });
  });

  it('ignores unknown days for the scale and the peak', () => {
    const chart = buildDayChart(days(null, 2, 4), 300, 100);

    expect(chart.peak?.xPercent).toBe(100);
  });
});

describe('hasActivity', () => {
  it('only counts known non-zero days as activity', () => {
    expect(hasActivity(days(null, 0))).toBe(false);
    expect(hasActivity(days(null, 1))).toBe(true);
  });
});
