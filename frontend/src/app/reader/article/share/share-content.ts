import { SharedLinkDto } from '../../entry-social';
import { SelectedQuote } from '../selection-share/selected-quote';
import { quoteShareUrl } from './quote-share-url';

/** The longest quote the composer is sent; `LinkTargetQuery::QUOTE_MAX` refuses more. */
export const QUOTE_MAX = 1000;

export interface SharedPage {
  readonly url: string;
  readonly title: string;
}

/** What a share target is filled with. `quote` is the selection in typographic quotes, null when sharing the page. */
export interface ShareContent {
  readonly url: string;
  readonly title: string;
  readonly text: string;
  readonly quote: string | null;
}

export function linkContent(page: SharedPage): ShareContent {
  const text = page.title === '' ? page.url : `${page.title} ${page.url}`;
  return { url: page.url, title: page.title, text, quote: null };
}

export function quoteContent(page: SharedPage, quote: SelectedQuote): ShareContent {
  const url = quoteShareUrl(page.url, quote);
  const quoted = `“${quote.text}”`;
  return { url, title: page.title, text: `${quoted} ${url}`, quote: quoted };
}

export function clipboardText(content: ShareContent): string {
  return content.quote === null ? content.url : `${content.quote}\n\n${content.url}`;
}

export function sharedLink(page: SharedPage, quote: SelectedQuote | null): SharedLinkDto {
  if (quote === null) return { url: page.url, title: page.title, quote: null };
  return {
    url: quoteShareUrl(page.url, quote),
    title: page.title,
    quote: clippedAtWord(quote.text, QUOTE_MAX),
  };
}

/** The text, or its head cut before a space and closed by an ellipsis, at most `max` characters in all. */
export function clippedAtWord(text: string, max: number): string {
  if (text.length <= max) return text;
  const head = text.slice(0, max - 1);
  const lastSpace = head.lastIndexOf(' ');
  return `${lastSpace > 0 ? head.slice(0, lastSpace) : head}…`;
}
