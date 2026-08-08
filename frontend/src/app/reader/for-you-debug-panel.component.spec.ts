import { TestBed } from '@angular/core/testing';
import { signal } from '@angular/core';
import { Subject, of } from 'rxjs';
import { provideTranslocoTesting } from '../../testing/transloco-testing';
import { ForYouDebugPanelComponent } from './for-you-debug-panel.component';
import { ReaderApi } from './reader-api';
import { RecommendationsService } from './recommendations.service';
import { DebugLogDetail, DebugLogEntry } from './models';

const BATCH_ENTRY: DebugLogEntry = {
  id: 1,
  phase: 'batch',
  batchNumber: 2,
  attempt: 1,
  verdict: null,
  requestBytes: 421903,
  responseBytes: 1024,
  streamingText: null,
};

const DEDUP_ENTRY: DebugLogEntry = {
  id: 2,
  phase: 'dedup',
  batchNumber: null,
  attempt: 2,
  verdict: 'usable',
  requestBytes: 2048,
  responseBytes: 4096,
  streamingText: null,
};

const STREAMING_ENTRY: DebugLogEntry = {
  id: 3,
  phase: 'batch',
  batchNumber: 1,
  attempt: 1,
  verdict: null,
  requestBytes: 512,
  responseBytes: 0,
  streamingText: 'partial…',
};

const DETAIL: DebugLogDetail = {
  id: 1,
  phase: 'batch',
  batchNumber: 2,
  attempt: 1,
  verdict: null,
  requestBody: '{"prompt":"x"}',
  responseText: 'response body',
};

describe('ForYouDebugPanelComponent', () => {
  let debugLog: jest.Mock;
  let debugLogEntry: jest.Mock;
  let running: ReturnType<typeof signal<boolean>>;
  let completedStamp: ReturnType<typeof signal<number>>;

  function mount() {
    const f = TestBed.createComponent(ForYouDebugPanelComponent);
    f.detectChanges();
    return f;
  }

  beforeEach(() => {
    debugLog = jest.fn().mockReturnValue(of({ entries: [] }));
    debugLogEntry = jest.fn().mockReturnValue(of(DETAIL));
    running = signal(false);
    completedStamp = signal(0);

    TestBed.configureTestingModule({
      imports: [ForYouDebugPanelComponent, provideTranslocoTesting()],
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
    debugLog.mockReturnValue(of({ entries: [BATCH_ENTRY, DEDUP_ENTRY] }));
    const f = mount();
    const text = (f.nativeElement as HTMLElement).textContent ?? '';
    expect(text).toContain('Batch 2');
    expect(text).toContain('412 KB');
    expect(text).toContain('Dedup');
    expect(text).toContain('attempt 2');
  });

  it('polls debugLog every 2s while a run is running, and stops once it flips false', () => {
    jest.useFakeTimers();
    debugLog.mockReturnValue(of({ entries: [BATCH_ENTRY] }));
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
    debugLog.mockReturnValue(of({ entries: [BATCH_ENTRY] }));
    const f = mount();

    const el = f.nativeElement as HTMLElement;
    const toggle = el.querySelector('.debug-panel__toggle') as HTMLButtonElement;
    toggle.click();
    f.detectChanges();

    expect(debugLogEntry).toHaveBeenCalledWith(1);
    expect(debugLogEntry).toHaveBeenCalledTimes(1);
    expect(el.querySelector('pre')!.textContent).toContain('{"prompt":"x"}');

    toggle.click();
    f.detectChanges();
    expect(debugLogEntry).toHaveBeenCalledTimes(1);
    expect(el.querySelector('pre')).toBeNull();
  });

  it('does not re-fetch a request body still in flight from an earlier toggle', () => {
    debugLog.mockReturnValue(of({ entries: [BATCH_ENTRY] }));
    const pending = new Subject<DebugLogDetail>();
    debugLogEntry.mockReturnValue(pending.asObservable());
    const f = mount();

    const el = f.nativeElement as HTMLElement;
    const toggle = el.querySelector('.debug-panel__toggle') as HTMLButtonElement;

    toggle.click(); // opens; request still unresolved
    f.detectChanges();
    toggle.click(); // collapses without waiting for the response
    f.detectChanges();
    toggle.click(); // re-opens before the first response has landed
    f.detectChanges();

    expect(debugLogEntry).toHaveBeenCalledTimes(1);

    pending.next(DETAIL);
    pending.complete();
    f.detectChanges();

    expect(el.querySelector('pre')!.textContent).toContain('{"prompt":"x"}');
  });

  it('shows the streaming row text without any detail fetch', () => {
    debugLog.mockReturnValue(of({ entries: [STREAMING_ENTRY] }));
    const f = mount();
    const el = f.nativeElement as HTMLElement;
    expect(el.querySelector('.debug-panel__stream')!.textContent).toContain('partial…');
    expect(debugLogEntry).not.toHaveBeenCalled();
  });

  it('replaces a cached mid-stream detail with the finished text once the poll settles the verdict', () => {
    jest.useFakeTimers();
    running.set(true);
    debugLog.mockReturnValue(of({ entries: [STREAMING_ENTRY] }));
    debugLogEntry.mockReturnValue(
      of({ ...DETAIL, id: 3, verdict: null, responseText: 'partial…' }),
    );
    const f = mount();
    const el = f.nativeElement as HTMLElement;

    // Expand the response while the call is still streaming: the live
    // branch renders `streamingText`, but ensureDetail() still fetches and
    // caches a partial detail underneath it.
    const toggleButtons = el.querySelectorAll('.debug-panel__toggle');
    (toggleButtons[1] as HTMLButtonElement).click();
    f.detectChanges();
    expect(debugLogEntry).toHaveBeenCalledTimes(1);

    // The call finishes: the next poll reports a settled verdict and the
    // real final text.
    debugLog.mockReturnValue(
      of({
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
    expect(el.querySelector('pre')!.textContent).toContain('final answer');
    expect(el.querySelector('pre')!.textContent).not.toContain('partial…');
  });
});
