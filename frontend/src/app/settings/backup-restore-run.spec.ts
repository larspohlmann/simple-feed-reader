import { HttpErrorResponse } from '@angular/common/http';
import { TestBed } from '@angular/core/testing';
import { of, throwError } from 'rxjs';
import { RestoreCounts, RestoreResult } from '../reader/models';
import { ReaderApi } from '../reader/reader-api';
import { BackupArchive } from './backup-archive';
import { BackupRestoreRun, RESTORE_WAIT } from './backup-restore-run';

function counts(overrides: Partial<RestoreCounts> = {}): RestoreCounts {
  return {
    tags: 0,
    savedSearches: 0,
    feeds: 0,
    subscriptions: 0,
    entries: 0,
    entryStates: 0,
    ...overrides,
  };
}

function result(loaded: Partial<RestoreCounts>): RestoreResult {
  return { loaded: counts(loaded) };
}

function problemResponse(status: number, type = 'about:blank'): HttpErrorResponse {
  return new HttpErrorResponse({ status, error: { type, title: 'failed', status } });
}

function fakeArchive(entryPartCount: number): BackupArchive {
  return {
    entryPartCount,
    foundation: jest.fn().mockResolvedValue(new Blob(['foundation'])),
    entryPart: jest.fn((index: number) => Promise.resolve(new Blob([`part-${index}`]))),
    close: jest.fn().mockResolvedValue(undefined),
  };
}

describe('BackupRestoreRun', () => {
  let api: { startAccountRestore: jest.Mock; restoreEntryPart: jest.Mock };
  let wait: jest.Mock;
  let run: BackupRestoreRun;
  let log: string[];

  beforeEach(() => {
    log = [];
    api = {
      startAccountRestore: jest.fn(() => {
        log.push('start');
        return of(result({ tags: 1 }));
      }),
      restoreEntryPart: jest.fn(() => {
        log.push('entries');
        return of(result({ entries: 1 }));
      }),
    };
    wait = jest.fn().mockResolvedValue(undefined);

    TestBed.configureTestingModule({
      providers: [
        { provide: ReaderApi, useValue: api },
        { provide: RESTORE_WAIT, useValue: wait },
      ],
    });
    run = TestBed.inject(BackupRestoreRun);
  });

  it('completes a happy-path run over the foundation and every entry part, strictly sequentially', async () => {
    api.startAccountRestore.mockImplementation(() => {
      log.push('start');
      return of(result({ tags: 1, savedSearches: 2 }));
    });
    api.restoreEntryPart
      .mockImplementationOnce(() => {
        log.push('part-1');
        return of(result({ entries: 3 }));
      })
      .mockImplementationOnce(() => {
        log.push('part-2');
        return of(result({ entries: 4 }));
      })
      .mockImplementationOnce(() => {
        log.push('part-3');
        return of(result({ entries: 5 }));
      });

    const outcome = await run.run(fakeArchive(3));

    expect(outcome).toEqual({
      kind: 'completed',
      loaded: counts({ tags: 1, savedSearches: 2, entries: 12 }),
    });
    expect(log).toEqual(['start', 'part-1', 'part-2', 'part-3']);
    expect(run.progress()).toEqual({ done: 4, total: 4 });
    expect(run.canContinue()).toBe(false);
  });

  it('retries a transient failure on a part and succeeds on the 2nd attempt', async () => {
    api.restoreEntryPart
      .mockImplementationOnce(() => throwError(() => problemResponse(502)))
      .mockImplementationOnce(() => of(result({ entries: 1 })));

    const outcome = await run.run(fakeArchive(1));

    expect(outcome.kind).toBe('completed');
    expect(wait).toHaveBeenCalledTimes(1);
    expect(wait).toHaveBeenNthCalledWith(1, 1000);
  });

  it('stops after exhausting all retries on a part, wiped and continuable', async () => {
    api.restoreEntryPart.mockImplementation(() => throwError(() => problemResponse(502)));

    const outcome = await run.run(fakeArchive(1));

    expect(outcome).toEqual({
      kind: 'stopped',
      problem: expect.objectContaining({ status: 502 }),
      wiped: true,
    });
    expect(run.canContinue()).toBe(true);
    expect(wait).toHaveBeenCalledTimes(3);
    expect(wait).toHaveBeenNthCalledWith(1, 1000);
    expect(wait).toHaveBeenNthCalledWith(2, 2000);
    expect(wait).toHaveBeenNthCalledWith(3, 4000);
  });

  it('continue() resumes at the failed part with a fresh retry budget, without calling start again', async () => {
    api.restoreEntryPart
      .mockImplementationOnce(() => of(result({ entries: 1 })))
      .mockImplementationOnce(() => throwError(() => problemResponse(502)))
      .mockImplementationOnce(() => throwError(() => problemResponse(502)))
      .mockImplementationOnce(() => throwError(() => problemResponse(502)))
      .mockImplementationOnce(() => throwError(() => problemResponse(502)))
      .mockImplementationOnce(() => of(result({ entries: 2 })));

    const stopped = await run.run(fakeArchive(2));
    expect(stopped.kind).toBe('stopped');
    expect(api.startAccountRestore).toHaveBeenCalledTimes(1);
    expect(wait).toHaveBeenCalledTimes(3);

    const outcome = await run.continue();

    expect(outcome).toEqual({ kind: 'completed', loaded: counts({ tags: 1, entries: 3 }) });
    expect(api.startAccountRestore).toHaveBeenCalledTimes(1);
    expect(api.restoreEntryPart).toHaveBeenCalledTimes(6);
    expect(wait).toHaveBeenCalledTimes(3);
  });

  it('does not retry a 422 on a part', async () => {
    api.restoreEntryPart.mockImplementation(() => throwError(() => problemResponse(422)));

    const outcome = await run.run(fakeArchive(1));

    expect(outcome).toEqual({
      kind: 'stopped',
      problem: expect.objectContaining({ status: 422 }),
      wiped: true,
    });
    expect(wait).not.toHaveBeenCalled();
    expect(api.restoreEntryPart).toHaveBeenCalledTimes(1);
  });

  it('a start refusal with invalid_backup stops without wiping and cannot continue', async () => {
    api.startAccountRestore.mockImplementation(() =>
      throwError(() => problemResponse(422, 'invalid_backup')),
    );

    const outcome = await run.run(fakeArchive(2));

    expect(outcome).toEqual({
      kind: 'stopped',
      problem: expect.objectContaining({ type: 'invalid_backup', status: 422 }),
      wiped: false,
    });
    expect(run.canContinue()).toBe(false);
    expect(api.restoreEntryPart).not.toHaveBeenCalled();
  });

  it('reset() clears the archive, index, counts and progress', async () => {
    api.restoreEntryPart.mockImplementation(() => throwError(() => problemResponse(502)));
    await run.run(fakeArchive(1));
    expect(run.canContinue()).toBe(true);
    expect(run.progress()).not.toBeNull();

    run.reset();

    expect(run.progress()).toBeNull();
    expect(run.canContinue()).toBe(false);
    await expect(run.continue()).rejects.toThrow();
  });
});
