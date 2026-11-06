import { Component, inject, signal } from '@angular/core';
import { NgTemplateOutlet } from '@angular/common';
import { ComponentFixture, TestBed } from '@angular/core/testing';
import { By } from '@angular/platform-browser';
import { PageLayerDirective } from './page-layer.directive';
import { PageScrollOwner } from './page-scroll-owner';
import { PageOffsetHold } from './page-offset-hold';
import { PAGE_PINNED_CLASS, PageLayer } from '../../shared/scroll-host/page-layer';
import { restorePageScroll, trackPageScrollTop } from '../../../testing/scroll-testing';

@Component({ selector: 'app-layer-probe', template: '' })
class LayerProbeComponent {
  readonly layer = inject(PageLayer, { optional: true });
}

@Component({
  imports: [PageLayerDirective, NgTemplateOutlet, LayerProbeComponent],
  providers: [PageScrollOwner, PageOffsetHold],
  template: `
    <section class="list" appPageLayer></section>
    @if (open()) {
      <div class="overlay" appPageLayer #layer="appPageLayer">
        <ng-container *ngTemplateOutlet="probe; injector: layer.injector" />
      </div>
    }
    <ng-template #probe><app-layer-probe /></ng-template>
  `,
})
class PageLayersHostComponent {
  readonly open = signal(false);
}

describe('PageLayerDirective', () => {
  let fixture: ComponentFixture<PageLayersHostComponent>;
  let page: { top: number };
  const host = (): HTMLElement => fixture.nativeElement as HTMLElement;

  beforeEach(() => {
    page = trackPageScrollTop();
    TestBed.configureTestingModule({ imports: [PageLayersHostComponent] });
    fixture = TestBed.createComponent(PageLayersHostComponent);
    fixture.detectChanges();
    page.top = 1200;
  });

  afterEach(() => {
    TestBed.resetTestingModule();
    restorePageScroll();
  });

  function setOpen(open: boolean): void {
    fixture.componentInstance.open.set(open);
    fixture.detectChanges();
  }

  it('pins the list where it stood when an overlay enters, and starts the page at the top', () => {
    setOpen(true);

    expect(host().querySelector('.list')!.classList).toContain(PAGE_PINNED_CLASS);
    expect(host().querySelector('.overlay')!.classList).not.toContain(PAGE_PINNED_CLASS);
    expect(page.top).toBe(0);
  });

  it('hands the page back to the list as the overlay leaves', () => {
    setOpen(true);
    page.top = 500;

    setOpen(false);

    expect(host().querySelector('.list')!.classList).not.toContain(PAGE_PINNED_CLASS);
    expect(page.top).toBe(1200);
  });

  it('lends its layer to an outlet whose template is declared outside it', () => {
    setOpen(true);
    const probe = fixture.debugElement.query(By.directive(LayerProbeComponent))
      .componentInstance as LayerProbeComponent;
    expect(probe.layer?.element).toBe(host().querySelector('.overlay'));
  });
});
