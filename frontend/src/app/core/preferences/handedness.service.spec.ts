import { TestBed } from '@angular/core/testing';
import { Observable, of } from 'rxjs';
import { CurrentUser } from '../auth/auth.service';
import { HANDEDNESS_KEY, Handedness } from './handedness';
import { HandednessService } from './handedness.service';
import { HANDEDNESS_WRITER } from './handedness-writer';

function userWith(handedness: Handedness): CurrentUser {
  return { preferences: { handedness } } as CurrentUser;
}

describe('HandednessService', () => {
  let written: Handedness[];
  let result: Observable<boolean>;

  function make(): HandednessService {
    TestBed.configureTestingModule({
      providers: [
        {
          provide: HANDEDNESS_WRITER,
          useValue: {
            write: (hand: Handedness) => {
              written.push(hand);
              return result;
            },
          },
        },
      ],
    });
    return TestBed.inject(HandednessService);
  }

  beforeEach(() => {
    localStorage.clear();
    written = [];
    result = of(true);
    TestBed.resetTestingModule();
  });

  it('starts right-handed when nothing is cached', () => {
    expect(make().hand()).toBe('right');
  });

  it('starts from the cache, so the first frame is never wrong', () => {
    localStorage.setItem(HANDEDNESS_KEY, 'left');

    expect(make().hand()).toBe('left');
  });

  it('ignores a cached value it does not know', () => {
    localStorage.setItem(HANDEDNESS_KEY, 'both');

    expect(make().hand()).toBe('right');
  });

  it('applies locally and writes through', () => {
    const service = make();

    service.set('left');

    expect(service.hand()).toBe('left');
    expect(localStorage.getItem(HANDEDNESS_KEY)).toBe('left');
    expect(written).toEqual(['left']);
  });

  it('reports a failed account write without reverting the local value', () => {
    result = of(false);
    const service = make();

    service.set('left');

    expect(service.hand()).toBe('left');
    expect(service.saveFailed()).toBe(true);
  });

  it('clears a previous failure on the next write', () => {
    const service = make();
    result = of(false);
    service.set('left');
    result = of(true);

    service.set('right');

    expect(service.saveFailed()).toBe(false);
  });

  it('adopts the account value without writing it back', () => {
    const service = make();

    service.adopt(userWith('left'));

    expect(service.hand()).toBe('left');
    expect(localStorage.getItem(HANDEDNESS_KEY)).toBe('left');
    expect(written).toEqual([]);
  });

  it('drops the signed-out account handedness, cache included', () => {
    const service = make();
    service.adopt(userWith('left'));

    service.reset();

    expect(service.hand()).toBe('right');
    expect(localStorage.getItem(HANDEDNESS_KEY)).toBeNull();
  });
});
