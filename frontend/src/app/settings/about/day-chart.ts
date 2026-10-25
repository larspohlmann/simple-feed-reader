/** One day of a 30-day chart; `count` is null when the day can no longer be known. */
export interface ChartDay {
  date: string;
  count: number | null;
}

/** The SVG geometry of a 30-day area chart (#896, #1783). `line` and `area` are paths in the
 *  viewBox coordinate space, one subpath per run of known days; markers and tooltips are placed
 *  by the percentage fields on `peak` and `columns`, so a round dot stays round however the SVG
 *  is stretched. */
export interface DayChart {
  width: number;
  height: number;
  line: string;
  area: string;
  peak: ChartPoint | null;
  columns: ChartColumn[];
}

/** A point on the line as percentages of the chart box, for an HTML overlay. */
export interface ChartPoint {
  xPercent: number;
  yPercent: number;
}

/** One day's hover/focus target: the band it occupies, the point on the line above it, and its datum. */
export interface ChartColumn extends ChartPoint {
  date: string;
  count: number | null;
  leftPercent: number;
  widthPercent: number;
}

interface PlottedDay {
  day: ChartDay;
  x: number;
  y: number;
  xPercent: number;
  yPercent: number;
}

const PAD_TOP = 8;
const PAD_BOTTOM = 4;

export function hasActivity(days: ChartDay[]): boolean {
  return days.some((day) => (day.count ?? 0) > 0);
}

export function buildDayChart(days: ChartDay[], width: number, height: number): DayChart {
  const baseline = height - PAD_BOTTOM;
  const plot = baseline - PAD_TOP;
  const max = Math.max(1, ...days.map((day) => day.count ?? 0));

  const points = days.map((day, index): PlottedDay => {
    const x = xFor(index, days.length, width);
    const y = baseline - ((day.count ?? 0) / max) * plot;

    return { day, x, y, xPercent: percent(x, width), yPercent: percent(y, height) };
  });
  const runs = knownRuns(points);

  return {
    width,
    height,
    line: runs.map(linePath).join(' '),
    area: runs.map((run) => areaPath(run, baseline)).join(' '),
    peak: hasActivity(days) ? peakOf(points) : null,
    columns: columnsFor(points, width),
  };
}

function knownRuns(points: PlottedDay[]): PlottedDay[][] {
  const runs: PlottedDay[][] = [];
  let current: PlottedDay[] = [];
  for (const point of points) {
    if (point.day.count === null) {
      current = [];
      continue;
    }
    if (current.length === 0) {
      runs.push(current);
    }
    current.push(point);
  }

  return runs;
}

function linePath(run: PlottedDay[]): string {
  return run
    .map((point, index) => `${index === 0 ? 'M' : 'L'}${point.x} ${round(point.y)}`)
    .join(' ');
}

function areaPath(run: PlottedDay[], baseline: number): string {
  const first = run[0];
  const last = run[run.length - 1];
  const line = run.map((point) => `L${point.x} ${round(point.y)}`).join(' ');

  return `M${first.x} ${baseline} ${line} L${last.x} ${baseline} Z`;
}

function peakOf(points: PlottedDay[]): ChartPoint {
  const peak = points.reduce((best, point) =>
    (point.day.count ?? -1) > (best.day.count ?? -1) ? point : best,
  );

  return { xPercent: peak.xPercent, yPercent: peak.yPercent };
}

function xFor(index: number, count: number, width: number): number {
  return count <= 1 ? width / 2 : round((index / (count - 1)) * width);
}

function columnsFor(points: PlottedDay[], width: number): ChartColumn[] {
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
