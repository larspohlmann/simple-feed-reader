import { TestBed } from '@angular/core/testing';
import { signal } from '@angular/core';
import { DialogRef } from '@angular/cdk/dialog';
import { provideTranslocoTesting } from '../../testing/transloco-testing';
import { ForYouInfoDialogComponent } from './for-you-info-dialog.component';
import { RecommendationsService } from './recommendations.service';
import { RecommendationRunReport } from './models';

const report = (over: Partial<RecommendationRunReport> = {}): RecommendationRunReport => ({
  status: 'running',
  batchesTotal: 3,
  batchesDone: 1,
  error: null,
  background: false,
  streamedChars: 0,
  forYou: { itemCount: 0, generatedAt: null },
  ...over,
});

describe('ForYouInfoDialogComponent', () => {
  const close = jest.fn();
  const recs = {
    report: signal<RecommendationRunReport | null>(null),
    workerOwnsRun: () => recs.report()?.background ?? false,
  };

  function render() {
    TestBed.configureTestingModule({
      imports: [provideTranslocoTesting()],
      providers: [
        { provide: DialogRef, useValue: { close } },
        { provide: RecommendationsService, useValue: recs },
      ],
    });
    const f = TestBed.createComponent(ForYouInfoDialogComponent);
    f.detectChanges();
    return f;
  }

  beforeEach(() => {
    close.mockReset();
    recs.report.set(null);
  });

  it('shows the keep-open copy when the client owns execution', () => {
    recs.report.set(report({ background: false }));
    const el = render().nativeElement as HTMLElement;
    expect(el.textContent).toContain('Keep the app open');
    expect(el.textContent).not.toContain('Runs in the background');
  });

  it('shows the background copy when a worker owns execution', () => {
    recs.report.set(report({ background: true }));
    const el = render().nativeElement as HTMLElement;
    expect(el.textContent).toContain('Runs in the background');
    expect(el.textContent).not.toContain('Keep the app open');
  });

  it('shows the streamed-KB line only once something has streamed', () => {
    recs.report.set(report({ streamedChars: 12288 }));
    const el = render().nativeElement as HTMLElement;
    expect(el.textContent).toContain('12 KB');
  });

  it('hides the streamed-KB line when nothing has streamed yet', () => {
    recs.report.set(report({ streamedChars: 0 }));
    const el = render().nativeElement as HTMLElement;
    expect(el.textContent).not.toContain('KB');
  });

  it('closes the dialog from the close button', () => {
    recs.report.set(report());
    const el = render().nativeElement as HTMLElement;
    (el.querySelector('button') as HTMLButtonElement).click();
    expect(close).toHaveBeenCalled();
  });
});
