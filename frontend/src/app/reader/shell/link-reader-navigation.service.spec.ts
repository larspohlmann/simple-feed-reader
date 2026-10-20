import { TestBed } from '@angular/core/testing';
import { ActivatedRoute, Router } from '@angular/router';
import { A_READER_TOKEN, aLinkCard } from '../../../testing/entry-testing';
import { LinkReaderNavigation } from './link-reader-navigation.service';

describe('LinkReaderNavigation', () => {
  const navigate = jest.fn().mockResolvedValue(true);
  const route = {} as ActivatedRoute;
  let navigation: LinkReaderNavigation;

  beforeEach(() => {
    navigate.mockClear();
    TestBed.configureTestingModule({
      providers: [
        LinkReaderNavigation,
        { provide: Router, useValue: { navigate } },
        { provide: ActivatedRoute, useValue: route },
      ],
    });
    navigation = TestBed.inject(LinkReaderNavigation);
  });

  const card = aLinkCard({ url: 'https://news.test/a' });
  const param = `${A_READER_TOKEN}~https://news.test/a`;

  it('layers the card over the open post and remembers it', () => {
    navigation.layer(card);

    expect(navigate).toHaveBeenCalledWith([], {
      relativeTo: route,
      queryParams: { link: param },
      queryParamsHandling: 'merge',
    });
    expect(navigation.opened()).toBe(card);
  });

  it('opens the card alone, dropping the post', () => {
    navigation.openAlone(card);

    expect(navigate).toHaveBeenCalledWith([], {
      relativeTo: route,
      queryParams: { link: param, entry: null },
      queryParamsHandling: 'merge',
    });
    expect(navigation.opened()).toBe(card);
  });

  it('closes the card', () => {
    navigation.close();

    expect(navigate).toHaveBeenCalledWith([], {
      relativeTo: route,
      queryParams: { link: null },
      queryParamsHandling: 'merge',
    });
  });
});
