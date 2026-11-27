/** A label as an entry row carries it. */
export interface LabelPill {
  id: number;
  name: string;
}

/** A label with how many of the reader's favorites, kept posts and saved articles carry it. */
export interface LabelTally extends LabelPill {
  favoriteCount: number;
  keptCount: number;
  articleCount: number;
}

/** The favorites and kept posts that carry no label. */
export interface UnlabeledTally {
  favoriteCount: number;
  keptCount: number;
}

/** What a favorites or kept list is narrowed to: one label, or the posts without any. */
export type LabelFilter = number | 'unlabeled';

/** The saved collections a label sorts. */
export type LabelCollection = 'favorites' | 'kept';

export interface LabelTalliesResponse {
  labels: LabelTally[];
  unlabeled: UnlabeledTally;
}
