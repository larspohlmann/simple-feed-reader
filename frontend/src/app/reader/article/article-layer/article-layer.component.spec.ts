import { HandednessService } from '../../../core/preferences/handedness.service';
import { Component, inject, signal } from '@angular/core';
import { ComponentFixture, TestBed } from '@angular/core/testing';
import { By } from '@angular/platform-browser';
import { provideTranslocoTesting } from '../../../../testing/transloco-testing';
import {
  restorePageScroll,
  scrollerOf,
  trackPageScrollTop,
} from '../../../../testing/scroll-testing';
import {
  MockResizeObserver,
  installMockResizeObserver,
} from '../../../../testing/resize-observer-testing';
import { ScrollProgressRail } from '../../../shared/progress-rail/scroll-progress-rail';
import { BACK_TO_TOP_AFTER_PX } from '../../../shared/to-top-button/to-top-button.component';
import { PageLayer } from '../../../shared/scroll-host/page-layer';
import { ArticleLayerComponent } from './article-layer.component';

@Component({
  imports: [ArticleLayerComponent],
  template: `
    <app-article-layer
      [fullscreen]="fullscreen()"
      [resetKey]="page()"
      [progressRail]="progressRail"
      [withTail]="false"
      backLabel="Back"
      (back)="closes = closes + 1"
      (scrolled)="scrolledTo.push($event)"
      (toTop)="toTops = toTops + 1"
    >
      <span articleBar class="probe">x</span>
      <span articleCorner class="corner-probe">c</span>
      <span articleDock class="dock-probe">d</span>
      <article>body</article>
    </app-article-layer>
  `,
})
class LayerHostComponent {
  readonly fullscreen = signal(false);
  readonly page = signal('entry:1');
  readonly progressRail = new ScrollProgressRail({
    scroller: () => undefined,
    contentBottom: () => null,
    layout: signal(0),
  });
  closes = 0;
  toTops = 0;
  readonly scrolledTo: number[] = [];
}

@Component({
  imports: [ArticleLayerComponent],
  providers: [
    { provide: PageLayer, useFactory: () => new PageLayer(document.createElement('div')) },
  ],
  template: `
    <app-article-layer
      [fullscreen]="true"
      [resetKey]="page()"
      [progressRail]="progressRail"
      backLabel="Back"
      (scrolled)="scrolledTo.push($event)"
    >
      <article>body</article>
    </app-article-layer>
  `,
})
class PageLayerHostComponent {
  readonly page = signal('entry:1');
  readonly layer = inject(PageLayer);
  readonly progressRail = new ScrollProgressRail({
    scroller: () => undefined,
    contentBottom: () => null,
    layout: signal(0),
  });
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
    const scroller = scrollerOf(fixture);
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

  it('projects the corner slot into the corner actions, outside the scroller (#1914)', () => {
    expect(element.querySelector('.corner-actions .corner-probe')).not.toBeNull();
    expect(element.querySelector('.scroller .corner-probe')).toBeNull();
  });

  it('gathers the corner actions and the pair in one cluster, the dock outside it (#2203)', () => {
    expect(element.querySelector('.corner-cluster .corner-actions .corner-probe')).not.toBeNull();
    expect(element.querySelector('.corner-cluster .end-corner .corner-back')).not.toBeNull();
    expect(element.querySelector('.corner-cluster .dock')).toBeNull();
  });

  it('mirrors the cluster for a left-handed account (#2203)', () => {
    expect(element.querySelector('.corner-cluster')!.classList).not.toContain('left-handed');

    TestBed.inject(HandednessService).set('left');
    fixture.detectChanges();

    expect(element.querySelector('.corner-cluster')!.classList).toContain('left-handed');
  });

  it('projects the dock slot over the pane, outside the scroller', () => {
    expect(element.querySelector('.dock .dock-probe')).not.toBeNull();
    expect(element.querySelector('.scroller .dock-probe')).toBeNull();
  });

  it('holds the chrome in a head and a foot around the scroller (#2038)', () => {
    goFullscreen();
    const frame = element.querySelector('.frame')!;

    const order = Array.from(frame.children).map((child) => child.className.split(' ')[0]);

    expect(order).toEqual(['chrome-head', 'scroller', 'chrome-foot']);
    expect(element.querySelector('.chrome-head .mini')).not.toBeNull();
    expect(element.querySelector('.chrome-head .bar .probe')).not.toBeNull();
    expect(element.querySelector('.chrome-head app-progress-rail')).not.toBeNull();
    expect(element.querySelector('.chrome-foot .corner-actions .corner-probe')).not.toBeNull();
    expect(element.querySelector('.chrome-foot .dock .dock-probe')).not.toBeNull();
    expect(element.querySelector('.chrome-foot .end-corner .corner-back')).not.toBeNull();
  });

  it('emits back from the back button in the split pane', () => {
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

  it('shows a back circle right of the to-top button, closing like the toolbar back (#2002)', () => {
    scrollTo(BACK_TO_TOP_AFTER_PX + 1);
    const back = element.querySelector('.end-corner .corner-back') as HTMLButtonElement;
    expect(back.previousElementSibling!.tagName).toBe('APP-TO-TOP-BUTTON');
    expect(back.getAttribute('aria-label')).toBe('Back');
    back.click();

    expect(fixture.componentInstance.closes).toBe(1);
  });

  it('shows the back circle before the to-top button appears (#2002)', () => {
    expect(element.querySelector('app-to-top-button')).toBeNull();
    expect(element.querySelector('.end-corner .corner-back')).not.toBeNull();
  });

  it('hides the corner the moment the article starts to leave (#1901)', () => {
    goFullscreen();
    scrollTo(BACK_TO_TOP_AFTER_PX + 1);

    (element.querySelector('.bar .close') as HTMLButtonElement).click();
    fixture.detectChanges();

    expect(element.querySelector('.end-corner')).toBeNull();
    expect(fixture.componentInstance.closes).toBe(0);
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

  it('starts a new page at the top with its toolbar presented', () => {
    goFullscreen();
    scrollTo(400);

    fixture.componentInstance.page.set('entry:2');
    fixture.detectChanges();

    expect(scrollerOf(fixture).scrollTop).toBe(0);
    expect(element.querySelector('.bar')!.classList).not.toContain('hidden');
    expect(element.querySelector('app-to-top-button')).toBeNull();
  });

  it('jumps to the top and lands focus on the heading it is given', () => {
    const scroller = scrollerOf(fixture);
    const scrollToSpy = jest.fn();
    scroller.scrollTo = scrollToSpy as unknown as typeof scroller.scrollTo;
    const heading = document.createElement('h1');
    heading.tabIndex = -1;
    document.body.appendChild(heading);
    const layer = fixture.debugElement.query(By.directive(ArticleLayerComponent))
      .componentInstance as ArticleLayerComponent;

    layer.scrollToTop(heading);

    expect(scrollToSpy).toHaveBeenCalledWith(expect.objectContaining({ top: 0 }));
    expect(document.activeElement).toBe(heading);
    heading.remove();
  });
});

describe('ArticleLayerComponent on the page (#2038)', () => {
  let fixture: ComponentFixture<PageLayerHostComponent>;
  let page: { top: number };

  beforeEach(() => {
    installMockResizeObserver();
    page = trackPageScrollTop();
    TestBed.configureTestingModule({
      imports: [PageLayerHostComponent, provideTranslocoTesting()],
    });
    fixture = TestBed.createComponent(PageLayerHostComponent);
    fixture.detectChanges();
  });

  afterEach(restorePageScroll);

  const layerElement = (): HTMLElement =>
    (fixture.nativeElement as HTMLElement).querySelector('app-article-layer') as HTMLElement;

  function scrollPage(top: number): void {
    page.top = top;
    window.dispatchEvent(new Event('scroll'));
    fixture.detectChanges();
  }

  it('marks itself on the page', () => {
    expect(layerElement().classList).toContain('on-page');
  });

  it('hears the page, not its own box, and retracts its toolbar on it', () => {
    scrollPage(400);

    expect(fixture.componentInstance.scrolledTo).toEqual([400]);
    expect(layerElement().querySelector('.bar')!.classList).toContain('hidden');
  });

  it('starts a new page at the top of the document', () => {
    scrollPage(400);

    fixture.componentInstance.page.set('entry:2');
    fixture.detectChanges();

    expect(page.top).toBe(0);
  });

  it('hears nothing while pinned beneath another layer, and moves its pin on a reset', () => {
    const { layer } = fixture.componentInstance;
    layer.pinAt(300);

    scrollPage(1200);
    fixture.componentInstance.page.set('entry:2');
    fixture.detectChanges();

    expect(fixture.componentInstance.scrolledTo).toEqual([]);
    expect(layer.pinnedTop).toBe(0);
    expect(page.top).toBe(1200);
  });

  it('leaves the page’s own scroll-padding alone when its toolbar resizes', () => {
    scrollerOf(fixture).style.setProperty('scroll-padding-top', '44px');
    const bar = layerElement().querySelector('.bar')!;
    MockResizeObserver.instances.find((observer) => observer.targets.has(bar))!.fire();

    expect(document.documentElement.style.getPropertyValue('scroll-padding-top')).toBe('');
  });
});
