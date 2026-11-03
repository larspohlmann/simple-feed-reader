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
    localStorage.setItem(SHARING_KEY, JSON.stringify({ enabled: false, hiddenTargets: ['x'] }));
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
    localStorage.setItem(SHARING_KEY, JSON.stringify({ enabled: true, hiddenTargets: ['x'] }));
    const service = make();

    service.setEnabled(false);

    expect(service.enabled()).toBe(false);
    expect(written).toEqual([{ enabled: false, hiddenTargets: ['x'] }]);
    expect(JSON.parse(localStorage.getItem(SHARING_KEY)!)).toEqual({
      enabled: false,
      hiddenTargets: ['x'],
    });
  });

  it('hides and shows one target and keeps ids it does not know', () => {
    localStorage.setItem(
      SHARING_KEY,
      JSON.stringify({ enabled: true, hiddenTargets: ['retired-app'] }),
    );
    const service = make();

    service.setTargetShown('x', false);
    service.setTargetShown('x', false);
    service.setTargetShown('retired-app', true);
    service.setTargetShown('reddit', false);

    expect(written.at(-1)).toEqual({ enabled: true, hiddenTargets: ['x', 'reddit'] });
    expect(written[0]).toEqual({ enabled: true, hiddenTargets: ['retired-app', 'x'] });
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

    expect(written).toEqual([{ enabled: false, hiddenTargets: [] }]);

    first.next(false);
    first.complete();

    expect(written).toEqual([
      { enabled: false, hiddenTargets: [] },
      { enabled: true, hiddenTargets: [] },
    ]);
    expect(service.saveFailed()).toBe(false);
  });

  it('adopts the account without writing it back', () => {
    const service = make();

    service.adopt(userWith({ enabled: false, hiddenTargets: ['facebook'] }));

    expect(service.enabled()).toBe(false);
    expect([...service.hidden()]).toEqual(['facebook']);
    expect(written).toEqual([]);
  });

  it('resets to sharing everything and forgets the cache', () => {
    const service = make();
    service.setEnabled(false);

    service.reset();

    expect(service.enabled()).toBe(true);
    expect(localStorage.getItem(SHARING_KEY)).toBeNull();
  });
});
