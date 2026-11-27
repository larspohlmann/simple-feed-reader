import { LabelPill } from './labels.models';

/** Pills read alphabetically, case aside, as the API lists them. */
export function byLabelName(left: LabelPill, right: LabelPill): number {
  return left.name.toLowerCase().localeCompare(right.name.toLowerCase());
}
