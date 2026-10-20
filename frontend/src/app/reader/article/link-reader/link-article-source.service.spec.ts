import { signal } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { Subject } from 'rxjs';
import { A_READER_TOKEN, aLinkCard } from '../../../../testing/entry-testing';
import { aReaderArticle } from '../../../../testing/reader-content-testing';
import { LinkCardDto } from '../../link-card/link-card';
import { ReaderContent } from '../../models';
import { LinkCardRef } from '../../query/link-param';
import { ReaderRouteState } from '../../shell/reader-route-state.service';
import { ReaderContentService } from '../content/reader-content.service';
import { LinkArticleSource } from './link-article-source.service';

const LINK: LinkCardRef = { url: 'https://news.test/story', token: A_READER_TOKEN };
const story = aReaderArticle({ title: 'The extracted story' });

describe('LinkArticleSource', () => {
  let replies: Subject<ReaderContent>;
  const linkCard = signal<LinkCardRef | null>(null);
  const openLinkCard = signal<LinkCardDto | null>(null);
  let source: LinkArticleSource;

  beforeEach(() => {
    replies = new Subject<ReaderContent>();
    linkCard.set(null);
    openLinkCard.set(null);
    TestBed.configureTestingModule({
      providers: [
        LinkArticleSource,
        { provide: ReaderRouteState, useValue: { linkCard, openLinkCard } },
        { provide: ReaderContentService, useValue: { load: () => replies } },
      ],
    });
    source = TestBed.inject(LinkArticleSource);
  });

  function openTheLink(): void {
    linkCard.set(LINK);
    TestBed.tick();
    replies.next(story);
  }

  it('is named after the card while the card is in view', () => {
    openLinkCard.set(aLinkCard({ title: 'A shared story' }));
    openTheLink();

    expect(source.title()).toBe('A shared story');
  });

  it('is named after the extracted page when the card is not in view', () => {
    openTheLink();

    expect(source.title()).toBe('The extracted story');
  });

  it('lets go of the page when the link closes', () => {
    openTheLink();

    linkCard.set(null);
    TestBed.tick();

    expect(source.article()).toBeNull();
    expect(source.title()).toBe('');
  });
});
