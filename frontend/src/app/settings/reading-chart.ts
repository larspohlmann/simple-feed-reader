import { ReadingDay } from '../reader/models';

/** The SVG geometry of the reading-activity area chart (#896). `line` and
 *  `area` are paths in the viewBox coordinate space; markers and tooltips are
 *  placed by the percentage fields on `peak` and `columns`, so a round dot
 *  stays round however the SVG is stretched. `hasData` is false for a window
 *  with no opens, which the component draws as an empty state. */
export interface ReadingChart {
  width: number;
  height: number;
  line: string;
  area: string;
  peak: ReadingPoint | null;
  columns: ReadingColumn[];
  hasData: boolean;
}

/** A point on the line as percentages of the chart box, for an HTML overlay. */
export interface ReadingPoint {
  xPercent: number;
  yPercent: number;
}

/** One day's hover/focus target: the band it occupies, the point on the line
 *  above it, and the datum a tooltip shows. */
export interface ReadingColumn extends ReadingPoint {
  date: string;
  count: number;
  leftPercent: number;
  widthPercent: number;
}

const PAD_TOP = 8;
const PAD_BOTTOM = 4;

/** Builds the chart geometry for one window of days. A single day is centred
 *  rather than drawn as a zero-width line; an empty window reports
 *  `hasData: false` so the component can skip the plot entirely. */
export function buildReadingChart(days: ReadingDay[], width: number, height: number): ReadingChart {
  const baseline = height - PAD_BOTTOM;
  const plot = baseline - PAD_TOP;
  const max = Math.max(1, ...days.map((day) => day.count));
  const total = days.reduce((sum, day) => sum + day.count, 0);

  const points = days.map((day, index) => {
    const x = xFor(index, days.length, width);
    const y = baseline - (day.count / max) * plot;

    return { day, x, y, xPercent: percent(x, width), yPercent: percent(y, height) };
  });

  return {
    width,
    height,
    line: points.map((p, index) => `${index === 0 ? 'M' : 'L'}${p.x} ${round(p.y)}`).join(' '),
    area: areaPath(points, baseline, width),
    peak: peakOf(points, total),
    columns: columnsFor(points, width),
    hasData: total > 0,
  };
}

function xFor(index: number, count: number, width: number): number {
  return count <= 1 ? width / 2 : round((index / (count - 1)) * width);
}

function areaPath(points: { x: number; y: number }[], baseline: number, width: number): string {
  if (points.length === 0) {
    return '';
  }
  const line = points.map((point) => `L${point.x} ${round(point.y)}`).join(' ');

  return `M${points[0].x} ${baseline} ${line} L${width} ${baseline} Z`;
}

function peakOf(
  points: { day: ReadingDay; xPercent: number; yPercent: number }[],
  total: number,
): ReadingPoint | null {
  if (total === 0) {
    return null;
  }
  const peak = points.reduce((best, point) => (point.day.count > best.day.count ? point : best));

  return { xPercent: peak.xPercent, yPercent: peak.yPercent };
}

function columnsFor(
  points: { day: ReadingDay; xPercent: number; yPercent: number }[],
  width: number,
): ReadingColumn[] {
  const bandWidth = points.length === 0 ? width : width / points.length;

  return points.map((point, index) => ({
    date: point.day.date,
    count: point.day.count,
    xPercent: point.xPercent,
    yPercent: point.yPercent,
    leftPercent: percent(index * bandWidth, width),
    widthPercent: percent(bandWidth, width),
  }));
}

function percent(value: number, extent: number): number {
  return round((value / extent) * 100);
}

function round(value: number): number {
  return Math.round(value * 100) / 100;
}
