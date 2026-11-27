import { LabelPill } from './labels.models';

/** The one label order, case and accents aside: the API's binary `LOWER()` puts "À" after "W". */
export function byLabelName(left: LabelPill, right: LabelPill): number {
  return left.name.toLowerCase().localeCompare(right.name.toLowerCase());
}
