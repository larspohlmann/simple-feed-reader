import { signal } from '@angular/core';
import { ComponentFixture, TestBed } from '@angular/core/testing';
import { provideTranslocoTesting } from '../../../testing/transloco-testing';
import { RecommendationRunReport } from '../models';
import { RecommendationsService } from '../recommendations.service';
import { ForYouProgressComponent } from './for-you-progress.component';

describe('ForYouProgressComponent', () => {
  const running = signal(true);
  const report = signal<RecommendationRunReport | null>(null);
  const progress = signal(0);
  const etaSeconds = signal<number | null>(null);
  const etaState = signal<'hidden' | 'starting' | 'waiting' | 'eta'>('starting');

  const makeReport = (over: Partial<RecommendationRunReport>): RecommendationRunReport => ({
    status: 'running',
    batchesTotal: 24,
    batchesDone: 4,
    error: null,
    background: false,
    streamedChars: 0,
    elapsedSeconds: null,
    forYou: { itemCount: 0, generatedAt: null, newestRunId: null },
    ...over,
  });

  beforeEach(() => {
    running.set(true);
    report.set(makeReport({}));
    progress.set(4 / 24);
    etaSeconds.set(null);
    etaState.set('starting');
  });

  const build = (): ComponentFixture<ForYouProgressComponent> => {
    TestBed.configureTestingModule({
      imports: [ForYouProgressComponent, provideTranslocoTesting()],
      providers: [
        {
          provide: RecommendationsService,
          useValue: { running, report, progress, etaSeconds, etaState },
        },
      ],
    });
    const fixture = TestBed.createComponent(ForYouProgressComponent);
    fixture.detectChanges();
    return fixture;
  };

  it('shows the count and fills the bar to the run fraction', () => {
    const el = build().nativeElement as HTMLElement;
    expect(el.querySelector('.for-you-progress')!.textContent).toContain('4 of 24');
    const fill = el.querySelector('.track span') as HTMLElement;
    expect(fill.style.width).toBe('17%'); // round(4 / 24 * 100)
  });

  it('appends the ETA to the count line in the eta state', () => {
    etaState.set('eta');
    etaSeconds.set(90);
    const el = build().nativeElement as HTMLElement;
    expect(el.querySelector('.for-you-progress')!.textContent).toContain('4 of 24');
    expect(el.querySelector('.eta')!.textContent).toContain('~2 min left'); // ceil(90 / 60)
  });

  it('shows the starting phrase, with no number, before the first batch', () => {
    etaState.set('starting');
    const el = build().nativeElement as HTMLElement;
    expect(el.querySelector('.eta')!.textContent).toContain('Starting');
    expect(el.querySelector('.eta')!.textContent).not.toContain('min left');
  });

  it('shows the rate-limited phrase while waiting', () => {
    etaState.set('waiting');
    const el = build().nativeElement as HTMLElement;
    expect(el.querySelector('.eta')!.textContent).toContain('Waiting');
  });

  it('renders nothing when no run is in flight', () => {
    running.set(false);
    const el = build().nativeElement as HTMLElement;
    expect(el.querySelector('.for-you-progress')).toBeNull();
    expect(el.querySelector('.track')).toBeNull();
  });

  it('hides the ETA from assistive technology, so a per-tick estimate is not announced', () => {
    etaState.set('eta');
    etaSeconds.set(30);
    const el = build().nativeElement as HTMLElement;
    expect(el.querySelector('.eta')!.getAttribute('aria-hidden')).toBe('true');
  });

  it('declares no live region of its own — the toast shell it renders into owns that', () => {
    const el = build().nativeElement as HTMLElement;
    const line = el.querySelector('.for-you-progress')!;
    expect(line.getAttribute('role')).toBeNull();
    expect(line.getAttribute('aria-live')).toBeNull();
  });

  it('exposes the bar as a progressbar with the discrete batch fraction as aria-valuenow', () => {
    const el = build().nativeElement as HTMLElement;
    const track = el.querySelector('.track')!;
    expect(track.getAttribute('role')).toBe('progressbar');
    expect(track.getAttribute('aria-valuemin')).toBe('0');
    expect(track.getAttribute('aria-valuemax')).toBe('100');
    expect(track.getAttribute('aria-valuenow')).toBe('17'); // round(4 / 24 * 100)
  });

  it('does not move aria-valuenow between batches, unlike the creeping visual fill', () => {
    const fixture = build();
    const el = fixture.nativeElement as HTMLElement;
    const track = el.querySelector('.track')!;
    expect(track.getAttribute('aria-valuenow')).toBe('17');

    // The ticker creeps the visual fill without a fresh report -- report()
    // (and so batchesDone/batchesTotal) is unchanged.
    progress.set(0.3);
    fixture.detectChanges();

    const fill = el.querySelector('.track span') as HTMLElement;
    expect(fill.style.width).toBe('30%'); // the visual fill did move
    expect(track.getAttribute('aria-valuenow')).toBe('17'); // the announced value did not
  });

  it('reports aria-valuenow of 0 rather than dividing by zero when there is no total yet', () => {
    report.set(makeReport({ batchesTotal: 0, batchesDone: 0 }));
    const el = build().nativeElement as HTMLElement;
    expect(el.querySelector('.track')!.getAttribute('aria-valuenow')).toBe('0');
  });
});
