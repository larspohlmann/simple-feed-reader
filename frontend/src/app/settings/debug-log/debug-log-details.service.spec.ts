import { TestBed } from '@angular/core/testing';
import { Subject, of } from 'rxjs';
import { SettingsApi } from '../settings-api';
import { DebugLogDetail, DebugLogEntry } from '../settings.models';
import { DebugLogDetails } from './debug-log-details.service';

const STREAMING: DebugLogEntry = {
  id: 3,
  runId: 7,
  phase: 'distill',
  batchNumber: null,
  attempt: 1,
  verdict: null,
  requestBytes: 512,
  responseBytes: 0,
  wireBytes: 0,
  streamingText: 'partial…',
  createdAt: '2026-08-08T10:02:00Z',
  finishedAt: null,
  errorDetail: null,
  finishReason: null,
};

const SETTLED: DebugLogEntry = { ...STREAMING, verdict: 'usable', streamingText: null };

function detail(responseText: string): DebugLogDetail {
  return {
    id: 3,
    phase: 'distill',
    batchNumber: null,
    attempt: 1,
    verdict: null,
    requestBody: '{}',
    responseText,
    wireBytes: 0,
    finishReason: null,
  };
}

describe('DebugLogDetails', () => {
  let debugLogEntry: jest.Mock;
  let details: DebugLogDetails;

  beforeEach(() => {
    debugLogEntry = jest.fn().mockReturnValue(of(detail('partial…')));
    TestBed.configureTestingModule({
      providers: [DebugLogDetails, { provide: SettingsApi, useValue: { debugLogEntry } }],
    });
    details = TestBed.inject(DebugLogDetails);
  });

  it('expands a row and fetches its detail once', () => {
    details.toggle(3);
    details.toggle(3);
    details.toggle(3);

    expect(details.isExpanded(3)).toBe(true);
    expect(details.detailFor(3)?.responseText).toBe('partial…');
    expect(debugLogEntry).toHaveBeenCalledTimes(1);
  });

  it('does not fetch again while the first request is still in flight', () => {
    const pending = new Subject<DebugLogDetail>();
    debugLogEntry.mockReturnValue(pending.asObservable());

    details.toggle(3);
    details.toggle(3);
    details.toggle(3);

    expect(debugLogEntry).toHaveBeenCalledTimes(1);
  });

  it('refetches an open row whose call settled after its detail was cached', () => {
    details.observe([STREAMING]);
    details.toggle(3);
    debugLogEntry.mockReturnValue(of(detail('final answer')));

    details.observe([SETTLED]);

    expect(debugLogEntry).toHaveBeenCalledTimes(2);
    expect(details.detailFor(3)?.responseText).toBe('final answer');
  });

  it('evicts a closed row’s mid-stream detail once its call settles', () => {
    details.observe([STREAMING]);
    details.toggle(3);
    details.toggle(3);

    details.observe([SETTLED]);

    expect(details.detailFor(3)).toBeNull();
    expect(debugLogEntry).toHaveBeenCalledTimes(1);
  });

  it('forgets every cached detail on clear', () => {
    details.toggle(3);

    details.clear();

    expect(details.detailFor(3)).toBeNull();
  });

  it('drops a detail requested mid-stream that lands after its call settled', () => {
    const midStream = new Subject<DebugLogDetail>();
    debugLogEntry.mockReturnValue(midStream.asObservable());
    details.observe([STREAMING]);
    details.toggle(3);
    debugLogEntry.mockReturnValue(of(detail('final answer')));

    details.observe([SETTLED]);
    midStream.next(detail('partial…'));
    midStream.complete();

    expect(debugLogEntry).toHaveBeenCalledTimes(2);
    expect(details.detailFor(3)?.responseText).toBe('final answer');
  });

  it('discards a detail still in flight when cleared', () => {
    const pending = new Subject<DebugLogDetail>();
    debugLogEntry.mockReturnValue(pending.asObservable());
    details.toggle(3);

    details.clear();
    pending.next(detail('from the old run'));

    expect(details.detailFor(3)).toBeNull();
    expect(details.isExpanded(3)).toBe(false);
  });
});
