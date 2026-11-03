import { TestBed } from '@angular/core/testing';
import { Observable, Subject, of } from 'rxjs';
import { CurrentUser } from '../auth/auth.service';
import { SHARING_KEY, SharingSettings } from './sharing';
import { SharingService } from './sharing.service';
import { SHARING_WRITER } from './sharing-writer';

function userWith(sharing: SharingSettings): CurrentUser {
  return { preferences: { sharing } } as CurrentUser;
}

describe('SharingService', () => {
  let written: SharingSettings[];
  let result: Observable<boolean>;

  function make(): SharingService {
    TestBed.configureTestingModule({
      providers: [
        {
          provide: SHARING_WRITER,
          useValue: {
            write: (settings: SharingSettings) => {
              written.push(settings);
              return result;
            },
          },
        },
      ],
    });
    return TestBed.inject(SharingService);
  }

  beforeEach(() => {
    localStorage.clear();
    written = [];
    result = of(true);
    TestBed.resetTestingModule();
  });

  it('shares everything when nothing is cached', () => {
    const service = make();

    expect(service.enabled()).toBe(true);
    expect([...service.hidden()]).toEqual([]);
  });

  it('starts from the cache, so the first frame is never wrong', () => {
    localStorage.setItem(
      SHARING_KEY,
      JSON.stringify({ enabled: false, hiddenTargets: ['x'], rememberShares: true }),
    );
    const service = make();

    expect(service.enabled()).toBe(false);
    expect([...service.hidden()]).toEqual(['x']);
  });

  it.each([
    'not json',
    '{"enabled":"yes","hiddenTargets":[]}',
    '{"enabled":true,"hiddenTargets":[1]}',
  ])('ignores a cached value it cannot read: %s', (cached) => {
    localStorage.setItem(SHARING_KEY, cached);

    expect(make().enabled()).toBe(true);
  });

  it('switches sharing off locally and writes the whole settings through', () => {
    localStorage.setItem(
      SHARING_KEY,
      JSON.stringify({ enabled: true, hiddenTargets: ['x'], rememberShares: true }),
    );
    const service = make();

    service.setEnabled(false);

    expect(service.enabled()).toBe(false);
    expect(written).toEqual([{ enabled: false, hiddenTargets: ['x'], rememberShares: true }]);
    expect(JSON.parse(localStorage.getItem(SHARING_KEY)!)).toEqual({
      enabled: false,
      hiddenTargets: ['x'],
      rememberShares: true,
    });
  });

  it('remembers shares until told otherwise', () => {
    expect(make().rememberShares()).toBe(true);
  });

  it('records shares only while sharing is on and remembering is on', () => {
    const service = make();
    expect(service.recordsShares()).toBe(true);

    service.setRememberShares(false);
    expect(service.recordsShares()).toBe(false);

    service.setRememberShares(true);
    service.setEnabled(false);
    expect(service.recordsShares()).toBe(false);
  });

  it('reads a cache written before remembering shares existed as remembering', () => {
    localStorage.setItem(SHARING_KEY, JSON.stringify({ enabled: false, hiddenTargets: ['x'] }));

    const service = make();

    expect(service.enabled()).toBe(false);
    expect(service.rememberShares()).toBe(true);
  });

  it('stops remembering locally and writes the whole settings through', () => {
    localStorage.setItem(
      SHARING_KEY,
      JSON.stringify({ enabled: true, hiddenTargets: ['x'], rememberShares: true }),
    );
    const service = make();

    service.setRememberShares(false);

    expect(service.rememberShares()).toBe(false);
    expect(written).toEqual([{ enabled: true, hiddenTargets: ['x'], rememberShares: false }]);
  });

  it('hides and shows one target and keeps ids it does not know', () => {
    localStorage.setItem(
      SHARING_KEY,
      JSON.stringify({ enabled: true, hiddenTargets: ['retired-app'], rememberShares: true }),
    );
    const service = make();

    service.setTargetShown('x', false);
    service.setTargetShown('x', false);
    service.setTargetShown('retired-app', true);
    service.setTargetShown('reddit', false);

    expect(written.at(-1)).toEqual({
      enabled: true,
      hiddenTargets: ['x', 'reddit'],
      rememberShares: true,
    });
    expect(written[0]).toEqual({
      enabled: true,
      hiddenTargets: ['retired-app', 'x'],
      rememberShares: true,
    });
  });

  it('reports a failed account write without reverting, and clears it on the next', () => {
    const service = make();
    result = of(false);
    service.setEnabled(false);

    expect(service.enabled()).toBe(false);
    expect(service.saveFailed()).toBe(true);

    result = of(true);
    service.setEnabled(true);
    expect(service.saveFailed()).toBe(false);
  });

  it('sends the writes in order and lets a later success clear an earlier failure', () => {
    const first = new Subject<boolean>();
    const service = make();
    result = first;
    service.setEnabled(false);
    result = of(true);
    service.setEnabled(true);

    expect(written).toEqual([{ enabled: false, hiddenTargets: [], rememberShares: true }]);

    first.next(false);
    first.complete();

    expect(written).toEqual([
      { enabled: false, hiddenTargets: [], rememberShares: true },
      { enabled: true, hiddenTargets: [], rememberShares: true },
    ]);
    expect(service.saveFailed()).toBe(false);
  });

  it('adopts the account without writing it back', () => {
    const service = make();

    service.adopt(userWith({ enabled: false, hiddenTargets: ['facebook'], rememberShares: true }));

    expect(service.enabled()).toBe(false);
    expect([...service.hidden()]).toEqual(['facebook']);
    expect(written).toEqual([]);
  });

  it('leaves the cache alone when the account already matches it', () => {
    localStorage.setItem(
      SHARING_KEY,
      JSON.stringify({ enabled: false, hiddenTargets: ['x'], rememberShares: true }),
    );
    const service = make();
    const setItem = jest.spyOn(Storage.prototype, 'setItem');

    service.adopt(userWith({ enabled: false, hiddenTargets: ['x'], rememberShares: true }));

    expect(setItem).not.toHaveBeenCalled();
    setItem.mockRestore();
  });

  it('resets to sharing everything and forgets the cache', () => {
    const service = make();
    service.setEnabled(false);

    service.reset();

    expect(service.enabled()).toBe(true);
    expect(localStorage.getItem(SHARING_KEY)).toBeNull();
  });
});
