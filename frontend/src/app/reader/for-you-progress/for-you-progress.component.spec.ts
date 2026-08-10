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
});
