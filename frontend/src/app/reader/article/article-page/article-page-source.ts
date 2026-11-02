import { InjectionToken, Signal } from '@angular/core';
import { AudioTrack } from '../../audio-player.service';
import { EmbedTrackOrigin } from '../../audio/embed-track';
import { ChapterDto, HeroImageDto } from '../../models';

export interface ArticlePageBody {
  readonly html: string;
  readonly pageUrl: string | null;
}

export interface ArticlePageBodyAudio {
  readonly enclosureUrl: string | null;
  readonly chapters: ChapterDto[];
  readonly track: AudioTrack | null;
  readonly origin: EmbedTrackOrigin | null;
}

export interface ArticlePageFailure {
  readonly messageKey: string;
  readonly quiet: boolean;
  readonly detail: string | null;
}

export interface ArticlePageFavicon {
  readonly url: string | null;
  readonly avatarUrl: string | null;
}

export interface ArticlePagePaywall {
  readonly url: string | null;
}

/** What an article page shows, whichever view fills it. */
export interface ArticlePageSource {
  readonly key: Signal<string>;
  readonly url: Signal<string | null>;
  readonly title: Signal<string>;
  readonly shareTitle: Signal<string>;
  readonly titleHidden: Signal<boolean>;
  readonly nameplate: Signal<string>;
  readonly favicon: Signal<ArticlePageFavicon | null>;
  readonly body: Signal<ArticlePageBody | null>;
  readonly bodyAudio: Signal<ArticlePageBodyAudio | null>;
  readonly lead: Signal<HeroImageDto | null>;
  readonly paywall: Signal<ArticlePagePaywall | null>;
  readonly loading: Signal<boolean>;
  readonly canRefresh: Signal<boolean>;
  readonly failure: Signal<ArticlePageFailure | null>;
  readonly socialPost: Signal<boolean>;
  dropLead(): void;
  reload(): void;
}

export const ARTICLE_PAGE_SOURCE = new InjectionToken<ArticlePageSource>('ArticlePageSource');
