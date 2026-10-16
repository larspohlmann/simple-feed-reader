/** Whether anything is still working on a pending entry's link card (#1516), and the hurry-up
 *  when one comes into view (#1646). No default: a placeholder outside the reader would
 *  otherwise claim progress nothing makes. */
export abstract class PreviewProgress {
  abstract isLoading(entryId: number): boolean;
  abstract sighted(entryId: number): void;
}
