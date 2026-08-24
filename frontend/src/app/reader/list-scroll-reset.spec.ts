// src/app/reader/list-scroll-reset.spec.ts
import { TestBed, fakeAsync, tick } from '@angular/core/testing';
import { Location } from '@angular/common';
import { provideLocationMocks } from '@angular/common/testing';
import { DefaultUrlSerializer, NavigationStart, Router, provideRouter } from '@angular/router';
import { Subject } from 'rxjs';
import { ListScrollMemory } from './list-scroll-memory';
import { ListScrollReset, ReaderPlace, forgetsPosition } from './list-scroll-reset';
import { Selection } from './query';

// A bare list URL (`/`, `/?tag=5`) parses to unread:false now that "all" is the
// default filter, so the constants the router-driven cases key the memory with
// must match that.
const ALL: Selection = { kind: 'all', id: null, unread: false };
const TAG: Selection = { kind: 'tag', id: 5, unread: false };
const SEARCH: Selection = { kind: 'search', id: null, unread: false, term: 'angular' };

/** A plain list: what is shown is the list itself. */
const at = (list: Selection): ReaderPlace => ({ list, shown: list });

/** A search layered over a list, the way the URL keeps both (#542). */
const searching = (list: Selection, shown: Selection = SEARCH): ReaderPlace => ({ list, shown });

describe('forgetsPosition', () => {
  it('keeps the position on the first navigation it sees, which is a boot or a resume-reload', () => {
    expect(forgetsPosition(null, at(TAG), 'imperative')).toBe(false);
  });

  it('keeps the position on back and on forward', () => {
    expect(forgetsPosition(at(TAG), at(ALL), 'popstate')).toBe(false);
    expect(forgetsPosition(at(TAG), at(ALL), 'hashchange')).toBe(false);
  });

  it('forgets the position when a click names a different list', () => {
    expect(forgetsPosition(at(TAG), at(ALL), 'imperative')).toBe(true);
  });

  it('forgets the position when a click only changes the unread filter', () => {
    expect(forgetsPosition(at(TAG), at({ ...TAG, unread: true }), 'imperative')).toBe(true);
  });

  it('keeps the position when a click names the list already open', () => {
    // Mark-all-read and opening or closing an article navigate imperatively
    // without changing the list. They must not move the user.
    expect(forgetsPosition(at(TAG), at({ ...TAG }), 'imperative')).toBe(false);
  });

  describe('a search layered over a list (#579)', () => {
    it('forgets the position when a search starts', () => {
      expect(forgetsPosition(at(TAG), searching(TAG), 'imperative')).toBe(true);
    });

    it('forgets the position when the term changes', () => {
      const refined = searching(TAG, { ...SEARCH, term: 'angular signals' });

      expect(forgetsPosition(searching(TAG), refined, 'imperative')).toBe(true);
    });

    it('keeps the position when the search clears, because the list underneath is returned to', () => {
      expect(forgetsPosition(searching(TAG), at(TAG), 'imperative')).toBe(false);
    });

    it('forgets the position when results are left for another list', () => {
      expect(forgetsPosition(searching(TAG), at(ALL), 'imperative')).toBe(true);
    });

    it('keeps the position when an article opens on top of the results', () => {
      expect(forgetsPosition(searching(TAG), searching(TAG), 'imperative')).toBe(false);
    });
  });
});

describe('ListScrollReset', () => {
  let events: Subject<unknown>;
  let memory: { forget: jest.Mock };
  const serializer = new DefaultUrlSerializer();

  const navigate = (url: string, trigger: 'imperative' | 'popstate' = 'imperative'): void => {
    events.next(new NavigationStart(1, url, trigger));
  };

  beforeEach(() => {
    events = new Subject<unknown>();
    memory = { forget: jest.fn() };
    TestBed.configureTestingModule({
      providers: [
        {
          provide: Router,
          useValue: { events, parseUrl: (url: string) => serializer.parse(url) },
        },
        { provide: ListScrollMemory, useValue: memory },
      ],
    });
    TestBed.inject(ListScrollReset);
  });

  it('leaves the first list it sees alone', () => {
    navigate('/?tag=5');

    expect(memory.forget).not.toHaveBeenCalled();
  });

  it('forgets the list a click opens', () => {
    navigate('/?tag=5');
    navigate('/');

    expect(memory.forget).toHaveBeenCalledWith(ALL);
  });

  it('leaves back and forward alone', () => {
    navigate('/?tag=5');
    navigate('/', 'popstate');

    expect(memory.forget).not.toHaveBeenCalled();
  });

  it('leaves an entry-only URL change alone', () => {
    navigate('/?tag=5');
    navigate('/?tag=5&entry=12-some-title');

    expect(memory.forget).not.toHaveBeenCalled();
  });

  it('ignores a URL outside the reader, and does not treat it as the list left behind', () => {
    navigate('/?tag=5');
    navigate('/settings');
    navigate('/?tag=5');

    expect(memory.forget).not.toHaveBeenCalled();
  });

  // Settings destroys the reader shell. Remembering the list left behind is why
  // this is root-provided rather than started by the shell: a listener that died
  // with the shell would come back knowing nothing and restore instead of reset.
  it('forgets the list a click opens after a trip outside the reader', () => {
    navigate('/?tag=5');
    navigate('/settings');
    navigate('/');

    expect(memory.forget).toHaveBeenCalledWith(ALL);
  });

  it('stops listening once the injector is destroyed', () => {
    navigate('/?tag=5');
    TestBed.resetTestingModule();
    navigate('/');

    expect(memory.forget).not.toHaveBeenCalled();
  });
});

// The fake event stream above cannot prove that the real router labels a real
// back gesture 'popstate' and a real click 'imperative'. These drive the router
// itself, against the real sessionStorage-backed memory.
describe('ListScrollReset, driven by the real router', () => {
  let router: Router;
  let location: Location;
  let memory: ListScrollMemory;

  beforeEach(() => {
    sessionStorage.clear();
    TestBed.configureTestingModule({
      providers: [
        provideRouter([
          { path: '', children: [] },
          { path: 'settings', children: [] },
        ]),
        provideLocationMocks(),
      ],
    });
    router = TestBed.inject(Router);
    location = TestBed.inject(Location);
    memory = TestBed.inject(ListScrollMemory);
    TestBed.inject(ListScrollReset);
  });

  it('drops the offset of a list the user clicks', fakeAsync(() => {
    router.navigateByUrl('/?tag=5');
    tick();
    memory.save(ALL, 300);

    router.navigateByUrl('/');
    tick();

    expect(memory.read(ALL)).toBe(0);
  }));

  it('keeps the offset of a list the user goes back to', fakeAsync(() => {
    router.navigateByUrl('/');
    tick();
    memory.save(ALL, 300);
    router.navigateByUrl('/?tag=5');
    tick();

    location.back();
    tick();

    expect(memory.read(ALL)).toBe(300);
  }));

  it('keeps the offset when a click opens an article in the same list', fakeAsync(() => {
    router.navigateByUrl('/?tag=5');
    tick();
    memory.save(TAG, 300);

    router.navigateByUrl('/?tag=5&entry=12-some-title');
    tick();

    expect(memory.read(TAG)).toBe(300);
  }));

  it('keeps the offset of the list a search was started from (#579)', fakeAsync(() => {
    router.navigateByUrl('/?tag=5');
    tick();
    memory.save(TAG, 300);
    router.navigateByUrl('/?tag=5&q=angular');
    tick();

    router.navigateByUrl('/?tag=5');
    tick();

    expect(memory.read(TAG)).toBe(300);
  }));

  it('drops the offset of a list clicked from inside search results (#579)', fakeAsync(() => {
    router.navigateByUrl('/?tag=5');
    tick();
    memory.save(ALL, 300);
    router.navigateByUrl('/?tag=5&q=angular');
    tick();

    router.navigateByUrl('/');
    tick();

    expect(memory.read(ALL)).toBe(0);
  }));
});
