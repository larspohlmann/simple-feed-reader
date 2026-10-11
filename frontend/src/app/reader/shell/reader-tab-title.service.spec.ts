import { signal } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { PageTitleService } from '../../core/i18n/page-title.service';
import { EntryDto } from '../models';
import { ListHeading } from './list-heading.service';
import { ReaderRouteState } from './reader-route-state.service';
import { ReaderTabTitle } from './reader-tab-title.service';

function setup(openEntry: Pick<EntryDto, 'title' | 'source'> | null) {
  const pageTitle = { useText: jest.fn() };
  TestBed.configureTestingModule({
    providers: [
      ReaderTabTitle,
      { provide: PageTitleService, useValue: pageTitle },
      {
        provide: ListHeading,
        useValue: { title: () => 'Unread', titleCount: () => ({ value: 3 }) },
      },
      { provide: ReaderRouteState, useValue: { openEntry: signal(openEntry) } },
    ],
  });
  TestBed.inject(ReaderTabTitle);
  TestBed.tick();
  return pageTitle;
}

describe('ReaderTabTitle', () => {
  it('names the tab after the open article', () => {
    const pageTitle = setup({ title: 'Headline', source: 'Feed' });

    expect(pageTitle.useText).toHaveBeenLastCalledWith('Headline');
  });

  it('names the tab after the source when the open article has no title', () => {
    const pageTitle = setup({ title: '', source: 'Feed' });

    expect(pageTitle.useText).toHaveBeenLastCalledWith('Feed');
  });

  it('names the tab after the list when no article is open', () => {
    const pageTitle = setup(null);

    expect(pageTitle.useText).toHaveBeenLastCalledWith('Unread', 3);
  });
});
