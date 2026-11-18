import { LinkCardDto } from '../link-card/link-card';
import { entryDek, entryDisplayTitle, entryHeadline } from '../entry/entry-text';
import { hostOf, parseUrl } from '../../shared/url-host';
import { StoryArticleDto } from './trending.models';

/** What a mini card shows of an article; `card` and its `site` are null for an outlet's own row. */
export interface ArticleFace {
  title: string;
  dek: string;
  picture: string | null;
  site: string | null;
  card: LinkCardDto | null;
}

const BARE_URL = /^https?:\/\/\S+$/;

/**
 * The card a Trending row stands for when it is a post sharing an article no followed outlet carries (#2229): an
 * outlet's own row carries no card, and a link post's title of its own (Lemmy's) still leads with the card.
 */
export function sharedArticleCard(article: StoryArticleDto): LinkCardDto | null {
  return article.shares.length > 0 ? (article.linkCards[0] ?? null) : null;
}

/** What a mini card shows of an article: its shared card when it stands for one, else the row's own text and picture. */
export function articleFaceOf(article: StoryArticleDto): ArticleFace {
  const card = sharedArticleCard(article);
  if (card) {
    return {
      title: card.title,
      dek: card.description ?? '',
      picture: card.imageUrl,
      site: card.siteName || siteOf(card.url),
      card,
    };
  }
  return {
    title: entryHeadline(article) || entryDisplayTitle(article),
    dek: withoutBareUrl(entryDek(article)),
    picture: article.imageUrl,
    site: null,
    card: null,
  };
}

function withoutBareUrl(dek: string): string {
  return BARE_URL.test(dek.trim()) ? '' : dek;
}

function siteOf(url: string): string {
  const parsed = parseUrl(url);
  return parsed ? hostOf(parsed) : url;
}
