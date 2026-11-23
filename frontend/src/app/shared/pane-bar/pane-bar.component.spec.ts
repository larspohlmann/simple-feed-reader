import { Component } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { installMockResizeObserver, resizeTo } from '../../../testing/resize-observer-testing';
import { PaneBarComponent } from './pane-bar.component';

@Component({
  imports: [PaneBarComponent],
  template: `<app-pane-bar (resized)="heights.push($event)"><b class="probe"></b></app-pane-bar>`,
})
class BarHost {
  readonly heights: number[] = [];
}

function barOf(element: HTMLElement): HTMLElement {
  return element.querySelector<HTMLElement>('app-pane-bar')!;
}

function mount() {
  installMockResizeObserver();
  const fixture = TestBed.createComponent(BarHost);
  fixture.detectChanges();
  const element = fixture.nativeElement as HTMLElement;
  return { element, host: fixture.componentInstance };
}

describe('PaneBarComponent', () => {
  it('projects its content', () => {
    const { element } = mount();

    expect(element.querySelector('app-pane-bar > .probe')).not.toBeNull();
  });

  it('reports its height on resize', () => {
    const { element, host } = mount();

    resizeTo(barOf(element), 56);

    expect(host.heights).toEqual([56]);
  });

  it('reports a height only when it changes', () => {
    const { element, host } = mount();

    resizeTo(barOf(element), 56);
    resizeTo(barOf(element), 56);
    resizeTo(barOf(element), 30);

    expect(host.heights).toEqual([56, 30]);
  });

  it('reports nothing while it has no height', () => {
    const { element, host } = mount();

    resizeTo(barOf(element), 0);

    expect(host.heights).toEqual([]);
  });
});
