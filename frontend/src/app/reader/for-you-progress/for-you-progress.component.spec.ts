import { signal } from '@angular/core';
import { ComponentFixture, TestBed } from '@angular/core/testing';
import { TranslocoService } from '@jsverse/transloco';
import { RecommendationsService } from '../recommendations.service';
import { ForYouProgressComponent } from './for-you-progress.component';

describe('ForYouProgressComponent', () => {
  const running = signal(true);
  const progress = signal(0.25);
  const etaSeconds = signal<number | null>(90);
  const etaState = signal<'hidden' | 'starting' | 'waiting' | 'eta'>('eta');

  const build = (): ComponentFixture<ForYouProgressComponent> => {
    TestBed.configureTestingModule({
      imports: [ForYouProgressComponent],
      providers: [
        { provide: RecommendationsService, useValue: { running, progress, etaSeconds, etaState } },
        {
          provide: TranslocoService,
          useValue: {
            translate: (key: string, params?: Record<string, unknown>) =>
              `${key}:${params?.['count'] ?? ''}`,
          },
        },
      ],
    });
    const fixture = TestBed.createComponent(ForYouProgressComponent);
    fixture.detectChanges();
    return fixture;
  };

  it('shows the ETA label in the eta state', () => {
    etaState.set('eta');
    etaSeconds.set(90);
    const text = build().nativeElement.textContent as string;
    expect(text).toContain('reader.eta.minutes:2'); // 90s -> ceil(90/60)=2
  });

  it('shows the starting label with no number before batch 1', () => {
    etaState.set('starting');
    etaSeconds.set(null);
    const text = build().nativeElement.textContent as string;
    expect(text).toContain('reader.eta.starting');
    expect(text).not.toContain('reader.eta.minutes');
  });

  it('shows the rate-limited label while waiting', () => {
    etaState.set('waiting');
    const text = build().nativeElement.textContent as string;
    expect(text).toContain('reader.eta.rateLimited');
  });

  it('renders no label when hidden', () => {
    etaState.set('hidden');
    const text = build().nativeElement.textContent as string;
    expect(text.trim()).toBe('');
  });
});
