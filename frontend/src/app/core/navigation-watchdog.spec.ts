// src/app/core/navigation-watchdog.spec.ts
import { TestBed } from '@angular/core/testing';
import { Router } from '@angular/router';
import { NavigationCancel, NavigationEnd, NavigationError, NavigationStart } from '@angular/router';
import { Subject } from 'rxjs';
import { NavigationFailureReporter } from './navigation-failure';
import { NAVIGATION_DEADLINE_MS, startNavigationWatchdog } from './navigation-watchdog';

describe('startNavigationWatchdog', () => {
  let events: Subject<unknown>;
  let reporter: { report: jest.Mock; noteNavigationSucceeded: jest.Mock };

  beforeEach(() => {
    jest.useFakeTimers();
    events = new Subject<unknown>();
    reporter = { report: jest.fn(), noteNavigationSucceeded: jest.fn() };
    TestBed.configureTestingModule({
      providers: [
        { provide: Router, useValue: { events } },
        { provide: NavigationFailureReporter, useValue: reporter },
      ],
    });
    TestBed.runInInjectionContext(() => startNavigationWatchdog());
  });

  afterEach(() => {
    jest.useRealTimers();
  });

  it('reports a navigation that never terminates', () => {
    events.next(new NavigationStart(1, '/settings'));

    jest.advanceTimersByTime(NAVIGATION_DEADLINE_MS);

    expect(reporter.report).toHaveBeenCalledTimes(1);
  });

  it('stays silent while the deadline has not passed', () => {
    events.next(new NavigationStart(1, '/settings'));

    jest.advanceTimersByTime(NAVIGATION_DEADLINE_MS - 1);

    expect(reporter.report).not.toHaveBeenCalled();
  });

  it('cancels on a completed navigation, and records the success', () => {
    events.next(new NavigationStart(1, '/settings'));
    events.next(new NavigationEnd(1, '/settings', '/settings'));

    jest.advanceTimersByTime(NAVIGATION_DEADLINE_MS * 2);

    expect(reporter.report).not.toHaveBeenCalled();
    expect(reporter.noteNavigationSucceeded).toHaveBeenCalledTimes(1);
  });

  it('cancels on a redirected navigation', () => {
    // Guards redirect: setupRedirectGuard and guestGuard both do, so a cancel
    // must not be mistaken for a stall.
    events.next(new NavigationStart(1, '/login'));
    events.next(new NavigationCancel(1, '/login', 'guard redirect'));

    jest.advanceTimersByTime(NAVIGATION_DEADLINE_MS * 2);

    expect(reporter.report).not.toHaveBeenCalled();
  });

  it('cancels on a failed navigation, leaving the error handler to report it', () => {
    events.next(new NavigationStart(1, '/settings'));
    events.next(new NavigationError(1, '/settings', new Error('chunk failed')));

    jest.advanceTimersByTime(NAVIGATION_DEADLINE_MS * 2);

    expect(reporter.report).not.toHaveBeenCalled();
  });

  it('restarts the deadline for a second navigation instead of stacking timers', () => {
    events.next(new NavigationStart(1, '/settings'));
    jest.advanceTimersByTime(NAVIGATION_DEADLINE_MS - 1_000);
    events.next(new NavigationStart(2, '/discover'));

    jest.advanceTimersByTime(1_000);
    expect(reporter.report).not.toHaveBeenCalled();

    jest.advanceTimersByTime(NAVIGATION_DEADLINE_MS);
    expect(reporter.report).toHaveBeenCalledTimes(1);
  });
});
