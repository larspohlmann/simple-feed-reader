import { TestBed } from '@angular/core/testing';
import { Observable, of } from 'rxjs';
import { CurrentUser } from './auth.service';
import { MAGAZINE_STYLE_KEY, MagazineStyle } from './magazine-style';
import { MagazineStyleService } from './magazine-style.service';
import { MAGAZINE_STYLE_WRITER } from './magazine-style-writer';

function userWith(magazineStyle: MagazineStyle): CurrentUser {
  return { preferences: { magazineStyle } } as CurrentUser;
}

describe('MagazineStyleService', () => {
  let written: MagazineStyle[];
  let result: Observable<boolean>;

  function make(): MagazineStyleService {
    TestBed.configureTestingModule({
      providers: [
        {
          provide: MAGAZINE_STYLE_WRITER,
          useValue: {
            write: (style: MagazineStyle) => {
              written.push(style);
              return result;
            },
          },
        },
      ],
    });
    return TestBed.inject(MagazineStyleService);
  }

  beforeEach(() => {
    localStorage.clear();
    written = [];
    result = of(true);
    TestBed.resetTestingModule();
  });

  it('starts boxed when nothing is cached', () => {
    expect(make().style()).toBe('boxed');
  });

  it('starts from the cache, so the first frame is never wrong', () => {
    localStorage.setItem(MAGAZINE_STYLE_KEY, 'airy');

    expect(make().style()).toBe('airy');
  });

  it('ignores a cached value it does not know', () => {
    localStorage.setItem(MAGAZINE_STYLE_KEY, 'cards');

    expect(make().style()).toBe('boxed');
  });

  it('applies locally and writes through', () => {
    const service = make();

    service.set('airy');

    expect(service.style()).toBe('airy');
    expect(localStorage.getItem(MAGAZINE_STYLE_KEY)).toBe('airy');
    expect(written).toEqual(['airy']);
  });

  it('reports a failed account write without reverting the local value', () => {
    result = of(false);
    const service = make();

    service.set('airy');

    expect(service.style()).toBe('airy');
    expect(service.saveFailed()).toBe(true);
  });

  it('clears a previous failure on the next write', () => {
    const service = make();
    result = of(false);
    service.set('airy');
    result = of(true);

    service.set('boxed');

    expect(service.saveFailed()).toBe(false);
  });

  it('adopts the account value without writing it back', () => {
    const service = make();

    service.adopt(userWith('airy'));

    expect(service.style()).toBe('airy');
    expect(localStorage.getItem(MAGAZINE_STYLE_KEY)).toBe('airy');
    expect(written).toEqual([]);
  });

  it('drops the signed-out account style, cache included', () => {
    const service = make();
    service.adopt(userWith('airy'));

    service.reset();

    expect(service.style()).toBe('boxed');
    expect(localStorage.getItem(MAGAZINE_STYLE_KEY)).toBeNull();
  });
});
