/** A selection in the article body that can be shared as a quote; `anchorable` when a text fragment can point at it. */
export interface SelectedQuote {
  readonly text: string;
  readonly words: number;
  readonly anchorable: boolean;
}
