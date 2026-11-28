import { TestBed } from '@angular/core/testing';
import { Observable, of } from 'rxjs';
import { CurrentUser } from '../auth/auth.service';
import { READING_WIDTH_KEY, ReadingWidth } from './reading-width';
import { ReadingWidthService } from './reading-width.service';
import { PREFERENCES_WRITER, ReadingPreferences } from './preferences-writer';

function userWith(readingWidth: ReadingWidth): CurrentUser {
  return { preferences: { readingWidth } } as CurrentUser;
}

describe('ReadingWidthService', () => {
  let written: Partial<ReadingPreferences>[];
  let result: Observable<boolean>;

  function make(): ReadingWidthService {
    TestBed.configureTestingModule({
      providers: [
        {
          provide: PREFERENCES_WRITER,
          useValue: {
            write: (change: Partial<ReadingPreferences>) => {
              written.push(change);
              return result;
            },
          },
        },
      ],
    });
    return TestBed.inject(ReadingWidthService);
  }

  beforeEach(() => {
    localStorage.clear();
    written = [];
    result = of(true);
    TestBed.resetTestingModule();
  });

  it('starts at the standard width when nothing is cached', () => {
    expect(make().width()).toBe('standard');
  });

  it('starts from the cache, so the first frame is never wrong', () => {
    localStorage.setItem(READING_WIDTH_KEY, 'wide');

    expect(make().width()).toBe('wide');
  });

  it('ignores a cached value it does not know', () => {
    localStorage.setItem(READING_WIDTH_KEY, 'huge');

    expect(make().width()).toBe('standard');
  });

  it('applies locally and writes through', () => {
    const service = make();

    service.set('full');

    expect(service.width()).toBe('full');
    expect(localStorage.getItem(READING_WIDTH_KEY)).toBe('full');
    expect(written).toEqual([{ readingWidth: 'full' }]);
  });

  it('reports a failed account write without reverting the local value', () => {
    result = of(false);
    const service = make();

    service.set('full');

    expect(service.width()).toBe('full');
    expect(service.saveFailed()).toBe(true);
  });

  it('adopts the account value without writing it back', () => {
    const service = make();

    service.adopt(userWith('wide'));

    expect(service.width()).toBe('wide');
    expect(localStorage.getItem(READING_WIDTH_KEY)).toBe('wide');
    expect(written).toEqual([]);
  });

  it('drops the signed-out account width, cache included', () => {
    const service = make();
    service.adopt(userWith('full'));

    service.reset();

    expect(service.width()).toBe('standard');
    expect(localStorage.getItem(READING_WIDTH_KEY)).toBeNull();
  });
});
