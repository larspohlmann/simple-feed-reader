import { RecommendationRunReport } from '../models';

const clamp01 = (value: number): number => Math.min(1, Math.max(0, value));

/** Elapsed share of the predicted total run time (#668); 0 while no estimate stands. */
export const timeShare = (elapsedSeconds: number | null, etaSeconds: number | null): number =>
  elapsedSeconds === null || etaSeconds === null
    ? 0
    : clamp01(elapsedSeconds / (elapsedSeconds + etaSeconds));

/** The share of the run that is really done: phase-weighted when history allows, else the plain batch count. */
export const finishedShare = (report: RecommendationRunReport): number =>
  clamp01(
    report.finishedShare ?? (report.batchesTotal ? report.batchesDone / report.batchesTotal : 0),
  );
