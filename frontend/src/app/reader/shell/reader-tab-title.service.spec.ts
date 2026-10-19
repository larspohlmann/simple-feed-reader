import { signal } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { PageTitleService } from '../../core/i18n/page-title.service';
import { EntryDto } from '../models';
import { LinkArticleSource } from '../article/link-reader/link-article-source.service';
import { ListHeading } from './list-heading.service';
import { ReaderRouteState } from './reader-route-state.service';
import { ReaderTabTitle } from './reader-tab-title.service';

function setup({
  openEntry = null,
  linkTitle = '',
}: { openEntry?: Pick<EntryDto, 'title' | 'source'> | null; linkTitle?: string } = {}) {
  const pageTitle = { useText: jest.fn() };
  const link = { title: signal(linkTitle) };
  TestBed.configureTestingModule({
    providers: [
      ReaderTabTitle,
      { provide: PageTitleService, useValue: pageTitle },
      {
        provide: ListHeading,
        useValue: { title: () => 'Unread', titleCount: () => ({ value: 3 }) },
      },
      { provide: ReaderRouteState, useValue: { openEntry: signal(openEntry) } },
      { provide: LinkArticleSource, useValue: link },
    ],
  });
  TestBed.inject(ReaderTabTitle);
  TestBed.tick();
  return { pageTitle, link };
}

describe('ReaderTabTitle', () => {
  it('names the tab after the open article', () => {
    const { pageTitle } = setup({ openEntry: { title: 'Headline', source: 'Feed' } });

    expect(pageTitle.useText).toHaveBeenLastCalledWith('Headline');
  });

  it('names the tab after the source when the open article has no title', () => {
    const { pageTitle } = setup({ openEntry: { title: '', source: 'Feed' } });

    expect(pageTitle.useText).toHaveBeenLastCalledWith('Feed');
  });

  it('names the tab after the list when no article is open', () => {
    const { pageTitle } = setup();

    expect(pageTitle.useText).toHaveBeenLastCalledWith('Unread', 3);
  });

  it('names the tab after a link page opened over the list', () => {
    const { pageTitle } = setup({ linkTitle: 'A shared story' });

    expect(pageTitle.useText).toHaveBeenLastCalledWith('A shared story');
  });

  it('names the tab after a link page layered over an open article', () => {
    const { pageTitle } = setup({
      openEntry: { title: 'Headline', source: 'Feed' },
      linkTitle: 'A shared story',
    });

    expect(pageTitle.useText).toHaveBeenLastCalledWith('A shared story');
  });

  it('restores the article title when the link closes', () => {
    const { pageTitle, link } = setup({
      openEntry: { title: 'Headline', source: 'Feed' },
      linkTitle: 'A shared story',
    });

    link.title.set('');
    TestBed.tick();

    expect(pageTitle.useText).toHaveBeenLastCalledWith('Headline');
  });
});
