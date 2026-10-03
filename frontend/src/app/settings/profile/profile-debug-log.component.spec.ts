import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { ComponentFixture, TestBed } from '@angular/core/testing';
import { API_BASE_URL } from '../../core/api';
import { provideTranslocoTesting } from '../../../testing/transloco-testing';
import { DebugLogEntry } from '../settings.models';
import { ProfileDebugLogComponent } from './profile-debug-log.component';

const LOG = '/api/me/ai/profile/runs/current/log';

function entry(over: Partial<DebugLogEntry> = {}): DebugLogEntry {
  return {
    id: 31,
    runId: 9,
    phase: 'distill',
    batchNumber: null,
    attempt: 2,
    verdict: 'usable',
    requestBytes: 1200,
    responseBytes: 80,
    wireBytes: 90,
    streamingText: null,
    createdAt: '2026-10-03T09:00:00+00:00',
    finishedAt: '2026-10-03T09:00:04+00:00',
    errorDetail: null,
    finishReason: 'stop',
    ...over,
  };
}

describe('ProfileDebugLogComponent', () => {
  let http: HttpTestingController;

  function mount(running: boolean): ComponentFixture<ProfileDebugLogComponent> {
    TestBed.configureTestingModule({
      imports: [provideTranslocoTesting()],
      providers: [
        provideHttpClient(),
        provideHttpClientTesting(),
        { provide: API_BASE_URL, useValue: '' },
      ],
    });
    http = TestBed.inject(HttpTestingController);
    const fixture = TestBed.createComponent(ProfileDebugLogComponent);
    fixture.componentRef.setInput('running', running);
    fixture.componentRef.setInput('runId', 9);
    fixture.detectChanges();
    return fixture;
  }

  const text = (fixture: ComponentFixture<ProfileDebugLogComponent>): string =>
    (fixture.nativeElement as HTMLElement).textContent ?? '';

  beforeEach(() => jest.useFakeTimers());
  afterEach(() => {
    http.verify();
    jest.useRealTimers();
  });

  it("lists the newest profile run's calls", () => {
    const fixture = mount(false);
    http.expectOne(LOG).flush({ entries: [entry()] });
    fixture.detectChanges();

    expect(text(fixture)).toContain('attempt 2');
    expect(text(fixture)).toContain('usable');
  });

  it('polls while the run is active and stops once it is not', () => {
    const fixture = mount(true);
    http.expectOne(LOG).flush({ entries: [] });

    jest.advanceTimersByTime(2000);
    http.expectOne(LOG).flush({ entries: [entry({ verdict: null, streamingText: '{"prof' })] });
    fixture.componentRef.setInput('running', false);
    fixture.detectChanges();
    http.expectOne(LOG).flush({ entries: [entry()] });
    jest.advanceTimersByTime(4000);

    http.expectNone(LOG);
  });

  it('keeps polling after a failed read', () => {
    const fixture = mount(true);
    http.expectOne(LOG).flush('gateway', { status: 500, statusText: 'Server Error' });

    jest.advanceTimersByTime(2000);
    http.expectOne(LOG).flush({ entries: [entry()] });
    fixture.detectChanges();

    expect(text(fixture)).toContain('attempt 2');
    fixture.destroy();
  });

  it('stops polling when it is destroyed', () => {
    const fixture = mount(true);
    http.expectOne(LOG).flush({ entries: [] });

    fixture.destroy();
    jest.advanceTimersByTime(4000);

    http.expectNone(LOG);
  });

  it("opens a call's request and response", () => {
    const fixture = mount(false);
    http.expectOne(LOG).flush({ entries: [entry()] });
    fixture.detectChanges();

    (
      (fixture.nativeElement as HTMLElement).querySelector(
        '.profile-log__head',
      ) as HTMLButtonElement
    ).click();
    http.expectOne('/api/recommendations/runs/debug-log/31').flush({
      id: 31,
      phase: 'distill',
      batchNumber: null,
      attempt: 2,
      verdict: 'usable',
      requestBody: '{"messages":[]}',
      responseText: '{"profile":"Likes maps."}',
      wireBytes: 90,
      finishReason: 'stop',
    });
    fixture.detectChanges();

    expect(
      (fixture.nativeElement as HTMLElement).querySelector('[data-testid="profile-log-response"]')
        ?.textContent,
    ).toContain('Likes maps.');
  });
});
