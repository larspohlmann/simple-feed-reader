import { ComponentFixture, TestBed } from '@angular/core/testing';
import { provideTranslocoTesting } from '../../../testing/transloco-testing';
import { DebugLogDetail, DebugLogEntry } from '../settings.models';
import { DebugLogEntryComponent } from './debug-log-entry.component';

const SETTLED: DebugLogEntry = {
  id: 5,
  runId: 7,
  phase: 'batch',
  batchNumber: 2,
  attempt: 3,
  verdict: 'usable',
  requestBytes: 421903,
  responseBytes: 1024,
  wireBytes: 16384,
  streamingText: null,
  createdAt: '2026-08-08T10:01:00Z',
  finishedAt: '2026-08-08T10:01:05Z',
  errorDetail: null,
  finishReason: 'stop',
};

const DETAIL: DebugLogDetail = {
  id: 5,
  phase: 'batch',
  batchNumber: 2,
  attempt: 3,
  verdict: 'usable',
  requestBody: '{"prompt":"x"}',
  responseText: 'final answer',
  wireBytes: 16384,
  finishReason: 'stop',
};

describe('DebugLogEntryComponent', () => {
  function mount(
    entry: DebugLogEntry,
    expanded = false,
    detail: DebugLogDetail | null = null,
  ): ComponentFixture<DebugLogEntryComponent> {
    TestBed.configureTestingModule({
      imports: [DebugLogEntryComponent, provideTranslocoTesting()],
    });
    const fixture = TestBed.createComponent(DebugLogEntryComponent);
    fixture.componentRef.setInput('entry', entry);
    fixture.componentRef.setInput('expanded', expanded);
    fixture.componentRef.setInput('detail', detail);
    fixture.detectChanges();
    return fixture;
  }

  const element = (fixture: ComponentFixture<DebugLogEntryComponent>): HTMLElement =>
    fixture.nativeElement as HTMLElement;

  it('renders the row: call, attempt, duration, verdict and sizes', () => {
    const text = element(mount(SETTLED)).textContent ?? '';

    expect(text).toContain('Batch 2');
    expect(text).toContain('attempt 3');
    expect(text).toContain('5 s');
    expect(text).toContain('usable');
    expect(text).toContain('412/1 KB');
  });

  it('names a dedup call', () => {
    const text = element(mount({ ...SETTLED, phase: 'dedup', batchNumber: null })).textContent;

    expect(text).toContain('Dedup');
  });

  it('names a profile distill call', () => {
    const text = element(mount({ ...SETTLED, phase: 'distill', batchNumber: null })).textContent;

    expect(text).toContain('Distill');
    expect(text).not.toContain('Dedup');
  });

  it('emits a toggle from its expander and reflects the expanded state', () => {
    const fixture = mount(SETTLED);
    const toggled = jest.fn();
    fixture.componentInstance.toggled.subscribe(toggled);
    const expander = element(fixture).querySelector('.debug-entry__expander') as HTMLElement;

    expander.click();

    expect(toggled).toHaveBeenCalledTimes(1);
    expect(expander.getAttribute('aria-expanded')).toBe('false');
  });

  it('shows the request and response once expanded with the detail', () => {
    const fixture = mount(SETTLED, true, DETAIL);
    const pres = Array.from(element(fixture).querySelectorAll('.debug-entry__body pre'));

    expect(pres.map((pre) => pre.textContent)).toEqual(['{"prompt":"x"}', 'final answer']);
    expect(
      element(fixture).querySelector('.debug-entry__expander')!.getAttribute('aria-expanded'),
    ).toBe('true');
  });

  it('shows no body while collapsed, detail or not', () => {
    expect(element(mount(SETTLED, false, DETAIL)).querySelector('.debug-entry__body')).toBeNull();
  });

  it('streams the live text of a call that has not settled', () => {
    const fixture = mount({
      ...SETTLED,
      verdict: null,
      finishedAt: null,
      streamingText: 'partial…',
    });

    expect(element(fixture).querySelector('.debug-entry__stream')!.textContent).toContain(
      'partial…',
    );
  });

  it.each([
    ['usable', 'debug-entry__verdict--usable'],
    ['unusable', 'debug-entry__verdict--unusable'],
    ['transport-failed', 'debug-entry__verdict--transport-failed'],
  ] as const)('marks a %s verdict with its modifier', (verdict, modifier) => {
    const pill = element(mount({ ...SETTLED, verdict })).querySelector('.debug-entry__verdict');

    expect(pill!.classList).toContain(modifier);
  });

  describe('copy', () => {
    const original = Object.getOwnPropertyDescriptor(navigator, 'clipboard');

    afterEach(() => {
      if (original) Object.defineProperty(navigator, 'clipboard', original);
      else Reflect.deleteProperty(navigator, 'clipboard');
    });

    it('copies a body to the clipboard', () => {
      const writeText = jest.fn().mockResolvedValue(undefined);
      Object.defineProperty(navigator, 'clipboard', { value: { writeText }, configurable: true });
      const fixture = mount(SETTLED, true, DETAIL);

      (element(fixture).querySelector('.debug-entry__section button') as HTMLElement).click();

      expect(writeText).toHaveBeenCalledWith('{"prompt":"x"}');
    });
  });
});
