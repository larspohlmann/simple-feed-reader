import { TestBed } from '@angular/core/testing';
import { signal } from '@angular/core';
import { Subject, of } from 'rxjs';
import { provideTranslocoTesting } from '../../testing/transloco-testing';
import { RecommendationDebugLogComponent } from './recommendation-debug-log.component';
import { ReaderApi } from '../reader/reader-api';
import { RecommendationsService } from '../reader/recommendations.service';
import { DebugLogDetail, DebugLogEntry, DebugLogRunSummary } from '../reader/models';

const BATCH_ENTRY: DebugLogEntry = {
  id: 1,
  phase: 'batch',
  batchNumber: 2,
  attempt: 1,
  verdict: null,
  requestBytes: 421903,
  responseBytes: 1024,
  wireBytes: 8192,
  streamingText: null,
  createdAt: '2026-08-08T10:00:00Z',
  finishedAt: null,
  errorDetail: null,
};

const DEDUP_ENTRY: DebugLogEntry = {
  id: 2,
  phase: 'dedup',
  batchNumber: null,
  attempt: 2,
  verdict: 'usable',
  requestBytes: 2048,
  responseBytes: 4096,
  wireBytes: 16384,
  streamingText: null,
  createdAt: '2026-08-08T10:01:00Z',
  finishedAt: '2026-08-08T10:01:05Z',
  errorDetail: null,
};

const STREAMING_ENTRY: DebugLogEntry = {
  id: 3,
  phase: 'batch',
  batchNumber: 1,
  attempt: 1,
  verdict: null,
  requestBytes: 512,
  responseBytes: 0,
  wireBytes: 0,
  streamingText: 'partial…',
  createdAt: '2026-08-08T10:02:00Z',
  finishedAt: null,
  errorDetail: null,
};

const TRANSPORT_FAILED_ENTRY: DebugLogEntry = {
  id: 4,
  phase: 'batch',
  batchNumber: 3,
  attempt: 1,
  verdict: 'transport-failed',
  requestBytes: 1024,
  responseBytes: 0,
  wireBytes: 0,
  streamingText: null,
  createdAt: '2026-08-08T10:03:00Z',
  finishedAt: '2026-08-08T10:03:02Z',
  errorDetail: 'cURL error 28: Operation timed out',
};

const DETAIL: DebugLogDetail = {
  id: 1,
  phase: 'batch',
  batchNumber: 2,
  attempt: 1,
  verdict: null,
  requestBody: '{"prompt":"x"}',
  responseText: 'response body',
  wireBytes: 8192,
};

const RUN_SUMMARY: DebugLogRunSummary = {
  status: 'completed',
  error: null,
  attempts: 1,
  maxAttempts: 3,
  transportFailures: 0,
  maxTransportFailures: 5,
  createdAt: '2026-08-08T10:00:00Z',
  completedAt: '2026-08-08T10:05:00Z',
};

const FAILED_RUN_SUMMARY: DebugLogRunSummary = {
  status: 'failed',
  error: 'Too many transport failures',
  attempts: 3,
  maxAttempts: 3,
  transportFailures: 5,
  maxTransportFailures: 5,
  createdAt: '2026-08-08T10:00:00Z',
  completedAt: '2026-08-08T10:10:00Z',
};

const RUNNING_RUN_SUMMARY: DebugLogRunSummary = {
  status: 'running',
  error: null,
  attempts: 1,
  maxAttempts: 3,
  transportFailures: 0,
  maxTransportFailures: 5,
  createdAt: '2026-08-08T10:00:00Z',
  completedAt: null,
};

describe('RecommendationDebugLogComponent', () => {
  let debugLog: jest.Mock;
  let debugLogEntry: jest.Mock;
  let running: ReturnType<typeof signal<boolean>>;
  let completedStamp: ReturnType<typeof signal<number>>;

  function mount() {
    const f = TestBed.createComponent(RecommendationDebugLogComponent);
    f.detectChanges();
    return f;
  }

  function expanderFor(el: HTMLElement, index = 0): HTMLButtonElement {
    return el.querySelectorAll('.debug-panel__expander')[index] as HTMLButtonElement;
  }

  beforeEach(() => {
    debugLog = jest.fn().mockReturnValue(of({ run: null, entries: [] }));
    debugLogEntry = jest.fn().mockReturnValue(of(DETAIL));
    running = signal(false);
    completedStamp = signal(0);

    TestBed.configureTestingModule({
      imports: [RecommendationDebugLogComponent, provideTranslocoTesting()],
      providers: [
        { provide: ReaderApi, useValue: { debugLog, debugLogEntry } },
        { provide: RecommendationsService, useValue: { running, completedStamp } },
      ],
    });
  });

  afterEach(() => {
    jest.useRealTimers();
  });

  it('renders nothing when debugLog answers no entries', () => {
    const f = mount();
    expect((f.nativeElement as HTMLElement).querySelector('details')).toBeNull();
  });

  it('renders one row per entry with the composed label', () => {
    debugLog.mockReturnValue(of({ run: null, entries: [BATCH_ENTRY, DEDUP_ENTRY] }));
    const f = mount();
    const text = (f.nativeElement as HTMLElement).textContent ?? '';
    expect(text).toContain('Batch 2');
    expect(text).toContain('412/1 KB');
    expect(text).toContain('Dedup');
    expect(text).toContain('attempt 2');
  });

  it('polls debugLog every 2s while a run is running, and stops once it flips false', () => {
    jest.useFakeTimers();
    debugLog.mockReturnValue(of({ run: RUNNING_RUN_SUMMARY, entries: [BATCH_ENTRY] }));
    running.set(true);
    const f = mount();
    expect(debugLog).toHaveBeenCalledTimes(1);

    jest.advanceTimersByTime(2000);
    expect(debugLog).toHaveBeenCalledTimes(2);

    jest.advanceTimersByTime(2000);
    expect(debugLog).toHaveBeenCalledTimes(3);

    // Mirrors real completion: running flips false and completedStamp bumps
    // together, so one final fetch on the flip (via the completion effect)
    // is expected and asserted here rather than treated as a bug.
    running.set(false);
    completedStamp.update((n) => n + 1);
    f.detectChanges();
    expect(debugLog).toHaveBeenCalledTimes(4);

    jest.advanceTimersByTime(2000);
    jest.advanceTimersByTime(2000);
    expect(debugLog).toHaveBeenCalledTimes(4);
  });

  it("lazily loads a row's request body on toggle, once", () => {
    debugLog.mockReturnValue(of({ run: null, entries: [BATCH_ENTRY] }));
    const f = mount();

    const el = f.nativeElement as HTMLElement;
    const expander = expanderFor(el);
    expander.click();
    f.detectChanges();

    expect(debugLogEntry).toHaveBeenCalledWith(1);
    expect(debugLogEntry).toHaveBeenCalledTimes(1);
    expect(el.querySelector('pre')!.textContent).toContain('{"prompt":"x"}');

    expander.click();
    f.detectChanges();
    expect(debugLogEntry).toHaveBeenCalledTimes(1);
    expect(el.querySelector('.debug-panel__body')).toBeNull();
  });

  it('does not re-fetch a request body still in flight from an earlier toggle', () => {
    debugLog.mockReturnValue(of({ run: null, entries: [BATCH_ENTRY] }));
    const pending = new Subject<DebugLogDetail>();
    debugLogEntry.mockReturnValue(pending.asObservable());
    const f = mount();

    const el = f.nativeElement as HTMLElement;
    const expander = expanderFor(el);

    expander.click(); // opens; request still unresolved
    f.detectChanges();
    expander.click(); // collapses without waiting for the response
    f.detectChanges();
    expander.click(); // re-opens before the first response has landed
    f.detectChanges();

    expect(debugLogEntry).toHaveBeenCalledTimes(1);

    pending.next(DETAIL);
    pending.complete();
    f.detectChanges();

    expect(el.querySelector('pre')!.textContent).toContain('{"prompt":"x"}');
  });

  it('shows the streaming row text without any detail fetch', () => {
    debugLog.mockReturnValue(of({ run: null, entries: [STREAMING_ENTRY] }));
    const f = mount();
    const el = f.nativeElement as HTMLElement;
    expect(el.querySelector('.debug-panel__stream')!.textContent).toContain('partial…');
    expect(debugLogEntry).not.toHaveBeenCalled();
  });

  it('replaces a cached mid-stream detail with the finished text once the poll settles the verdict', () => {
    jest.useFakeTimers();
    running.set(true);
    debugLog.mockReturnValue(of({ run: null, entries: [STREAMING_ENTRY] }));
    debugLogEntry.mockReturnValue(
      of({ ...DETAIL, id: 3, verdict: null, responseText: 'partial…' }),
    );
    const f = mount();
    const el = f.nativeElement as HTMLElement;

    // Expand the row while the call is still streaming: the live branch
    // renders `streamingText` unconditionally, but the expander still
    // fetches and caches a partial detail underneath it.
    expanderFor(el).click();
    f.detectChanges();
    expect(debugLogEntry).toHaveBeenCalledTimes(1);

    // The call finishes: the next poll reports a settled verdict and the
    // real final text.
    debugLog.mockReturnValue(
      of({
        run: null,
        entries: [
          { ...STREAMING_ENTRY, verdict: 'usable', streamingText: null, responseBytes: 11 },
        ],
      }),
    );
    debugLogEntry.mockReturnValue(
      of({ ...DETAIL, id: 3, verdict: 'usable', responseText: 'final answer' }),
    );
    jest.advanceTimersByTime(2000);
    f.detectChanges();

    expect(debugLogEntry).toHaveBeenCalledTimes(2);
    const preTexts = Array.from(el.querySelectorAll('pre')).map((pre) => pre.textContent);
    expect(preTexts.some((text) => text?.includes('final answer'))).toBe(true);
    expect(preTexts.some((text) => text?.includes('partial…'))).toBe(false);
  });

  /**
   * The #320 story the panel exists to tell: a reasoning model streams
   * megabytes and answers nothing, which without this line looks exactly
   * like a provider that never spoke.
   */
  it('reports bytes streamed without an answer', () => {
    debugLog.mockReturnValue(
      of({
        run: null,
        entries: [
          {
            ...STREAMING_ENTRY,
            verdict: 'transport-failed',
            streamingText: null,
            responseBytes: 0,
            wireBytes: 1_900_000,
          },
        ],
      }),
    );
    const f = TestBed.createComponent(RecommendationDebugLogComponent);
    f.detectChanges();

    const wire = (f.nativeElement as HTMLElement).querySelector('.debug-panel__wire');
    expect(wire!.textContent).toContain('1855');
    expect(wire!.textContent).toContain('no answer');
  });

  it('reports bytes streamed alongside an answer', () => {
    debugLog.mockReturnValue(of({ run: null, entries: [DEDUP_ENTRY] }));
    const f = TestBed.createComponent(RecommendationDebugLogComponent);
    f.detectChanges();

    const wire = (f.nativeElement as HTMLElement).querySelector('.debug-panel__wire');
    expect(wire!.textContent).toContain('16');
    expect(wire!.textContent).not.toContain('no answer');
  });

  it('hides the wire line when nothing was streamed', () => {
    debugLog.mockReturnValue(of({ run: null, entries: [STREAMING_ENTRY] }));
    const f = TestBed.createComponent(RecommendationDebugLogComponent);
    f.detectChanges();

    expect((f.nativeElement as HTMLElement).querySelector('.debug-panel__wire')).toBeNull();
  });

  it('renders the summary strip from a completed run', () => {
    debugLog.mockReturnValue(of({ run: RUN_SUMMARY, entries: [DEDUP_ENTRY] }));
    const f = mount();
    const el = f.nativeElement as HTMLElement;

    const status = el.querySelector('.debug-panel__status');
    expect(status!.textContent).toContain('completed');
    expect(status!.className).toContain('debug-panel__status--completed');

    const summaryText = el.querySelector('.debug-panel__summary')!.textContent ?? '';
    expect(summaryText).toContain('1/3');
    expect(summaryText).toContain('0/5');

    const timeline = el.querySelector('.debug-panel__timeline')!.textContent ?? '';
    expect(timeline.trim()).toMatch(/^\d{2}:\d{2} → \d{2}:\d{2}$/);
  });

  it('shows the run-level error on a failed run, styled as danger', () => {
    debugLog.mockReturnValue(of({ run: FAILED_RUN_SUMMARY, entries: [TRANSPORT_FAILED_ENTRY] }));
    const f = mount();
    const el = f.nativeElement as HTMLElement;

    const status = el.querySelector('.debug-panel__status');
    expect(status!.className).toContain('debug-panel__status--failed');
    expect(el.querySelector('.debug-panel__run-error')!.textContent).toContain(
      'Too many transport failures',
    );
  });

  it('does not render a half-empty summary strip when the user has never run', () => {
    debugLog.mockReturnValue(of({ run: null, entries: [BATCH_ENTRY] }));
    const f = mount();
    expect((f.nativeElement as HTMLElement).querySelector('.debug-panel__summary')).toBeNull();
  });

  it('shows an in-progress timeline without a completion time while the run is still going', () => {
    debugLog.mockReturnValue(of({ run: RUNNING_RUN_SUMMARY, entries: [BATCH_ENTRY] }));
    const f = mount();
    const timeline = (f.nativeElement as HTMLElement).querySelector('.debug-panel__timeline');
    expect(timeline!.textContent!.trim()).toMatch(/^\d{2}:\d{2} → …$/);
  });

  it("renders a transport-failed row's errorDetail as a full-width danger line", () => {
    debugLog.mockReturnValue(of({ run: null, entries: [TRANSPORT_FAILED_ENTRY] }));
    const f = mount();
    const el = f.nativeElement as HTMLElement;

    expect(el.querySelector('.debug-panel__error')!.textContent).toContain(
      'cURL error 28: Operation timed out',
    );
  });

  it('shows no error line for a completed call', () => {
    debugLog.mockReturnValue(of({ run: null, entries: [DEDUP_ENTRY] }));
    const f = mount();
    expect((f.nativeElement as HTMLElement).querySelector('.debug-panel__error')).toBeNull();
  });

  it('renders a settled call’s duration, in seconds, once expanded', () => {
    debugLog.mockReturnValue(of({ run: null, entries: [DEDUP_ENTRY] }));
    const f = mount();
    const el = f.nativeElement as HTMLElement;

    expanderFor(el).click();
    f.detectChanges();

    expect(el.querySelector('.debug-panel__duration')!.textContent).toContain('5 s');
  });

  it('never renders a duration for the row still streaming (no NaN, no negative)', () => {
    debugLog.mockReturnValue(of({ run: null, entries: [BATCH_ENTRY] }));
    const f = mount();
    const el = f.nativeElement as HTMLElement;

    expanderFor(el).click();
    f.detectChanges();

    expect(el.querySelector('.debug-panel__duration')).toBeNull();
  });
});
