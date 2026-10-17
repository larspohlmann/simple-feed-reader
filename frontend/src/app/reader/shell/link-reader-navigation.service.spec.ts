import { TestBed } from '@angular/core/testing';
import { ActivatedRoute, Router } from '@angular/router';
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

  it('layers the card over the open post', () => {
    navigation.layer({ entryId: 7, position: 1 });

    expect(navigate).toHaveBeenCalledWith([], {
      relativeTo: route,
      queryParams: { link: '7.1' },
      queryParamsHandling: 'merge',
    });
  });

  it('opens the card alone, dropping the post', () => {
    navigation.openAlone({ entryId: 7, position: 1 });

    expect(navigate).toHaveBeenCalledWith([], {
      relativeTo: route,
      queryParams: { link: '7.1', entry: null },
      queryParamsHandling: 'merge',
    });
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
