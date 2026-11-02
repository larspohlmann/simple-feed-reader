import { SelectedQuote } from '../selection-share/selected-quote';
import { wordsOf } from '../selection-share/selection-text';

/** Past this many words a text fragment names the quote by its edges, which keeps the link short. */
const WHOLE_QUOTE_WORDS = 8;
const EDGE_WORDS = 4;

/** The URL with a text fragment that opens the page scrolled to the quote, when one can anchor it. */
export function quoteShareUrl(url: string, quote: SelectedQuote): string {
  if (!quote.anchorable) return url;
  const words = wordsOf(quote.text);
  const directive =
    words.length > WHOLE_QUOTE_WORDS
      ? `${fragmentText(words.slice(0, EDGE_WORDS))},${fragmentText(words.slice(-EDGE_WORDS))}`
      : fragmentText(words);
  return `${url}${url.includes('#') ? '' : '#'}:~:text=${directive}`;
}

/** The fragment syntax reserves '-', which encodeURIComponent leaves as it is. */
function fragmentText(words: readonly string[]): string {
  return encodeURIComponent(words.join(' ')).replaceAll('-', '%2D');
}
