import { Component, signal } from '@angular/core';
import { ComponentFixture, TestBed } from '@angular/core/testing';
import { provideTranslocoTesting } from '../../../../testing/transloco-testing';
import { installMockResizeObserver } from '../../../../testing/resize-observer-testing';
import { ScrollProgressRail } from '../../../shared/progress-rail/scroll-progress-rail';
import { BACK_TO_TOP_AFTER_PX } from '../../../shared/to-top-button/to-top-button.component';
import { ArticleScrollRestore } from '../reading/article-scroll-restore.service';
import { ArticleLayerComponent } from './article-layer.component';

@Component({
  imports: [ArticleLayerComponent],
  providers: [ArticleScrollRestore],
  template: `
    <app-article-layer
      [fullscreen]="fullscreen()"
      [progressRail]="progressRail"
      [withTail]="false"
      backLabel="Back"
      (close)="closes = closes + 1"
      (scrolled)="scrolledTo.push($event)"
      (toTop)="toTops = toTops + 1"
    >
      <span articleBar class="probe">x</span>
      <article>body</article>
    </app-article-layer>
  `,
})
class LayerHostComponent {
  readonly fullscreen = signal(false);
  readonly progressRail = new ScrollProgressRail({
    isWide: signal(false),
    contentBottom: () => null,
    layout: signal(0),
  });
  closes = 0;
  toTops = 0;
  readonly scrolledTo: number[] = [];
}

describe('ArticleLayerComponent', () => {
  let fixture: ComponentFixture<LayerHostComponent>;
  let element: HTMLElement;

  beforeEach(() => {
    installMockResizeObserver();
    TestBed.configureTestingModule({
      imports: [LayerHostComponent, provideTranslocoTesting()],
    });
    fixture = TestBed.createComponent(LayerHostComponent);
    fixture.detectChanges();
    element = fixture.nativeElement as HTMLElement;
  });

  function scrollTo(top: number): void {
    const scroller = element.querySelector('.scroller') as HTMLElement;
    scroller.scrollTop = top;
    scroller.dispatchEvent(new Event('scroll'));
    fixture.detectChanges();
  }

  function goFullscreen(): void {
    fixture.componentInstance.fullscreen.set(true);
    fixture.detectChanges();
  }

  it('projects the toolbar slot into the bar and the article into the reader', () => {
    expect(element.querySelector('.bar .probe')).not.toBeNull();
    expect(element.querySelector('.reader article')!.textContent).toBe('body');
  });

  it('emits close from the back button in the split pane', () => {
    (element.querySelector('.bar .close') as HTMLButtonElement).click();

    expect(fixture.componentInstance.closes).toBe(1);
  });

  it('labels the back button with the label it is given', () => {
    expect(element.querySelector('.close')!.getAttribute('aria-label')).toBe('Back');
  });

  it('reports every scroll offset', () => {
    scrollTo(120);
    scrollTo(80);

    expect(fixture.componentInstance.scrolledTo).toEqual([120, 80]);
  });

  it('shows the to-top button past a screen, and reports its activation', () => {
    expect(element.querySelector('app-to-top-button')).toBeNull();

    scrollTo(BACK_TO_TOP_AFTER_PX + 1);
    (element.querySelector('app-to-top-button button') as HTMLButtonElement).click();

    expect(fixture.componentInstance.toTops).toBe(1);
  });

  it('retracts the full-screen toolbar scrolling down and returns it scrolling up', () => {
    goFullscreen();

    scrollTo(400);
    expect(element.querySelector('.bar')!.classList).toContain('hidden');

    scrollTo(300);
    expect(element.querySelector('.bar')!.classList).not.toContain('hidden');
  });

  it('never retracts the split-pane toolbar', () => {
    scrollTo(100);
    scrollTo(500);

    expect(element.querySelector('.bar')!.classList).not.toContain('hidden');
  });
});
