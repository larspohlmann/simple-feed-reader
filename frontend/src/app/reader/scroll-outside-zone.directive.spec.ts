import { Component, NgZone } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { ScrollOutsideZoneDirective } from './scroll-outside-zone.directive';

@Component({
  imports: [ScrollOutsideZoneDirective],
  template: `<div class="scroller" [appScrollOutsideZone]="onScroll"></div>`,
})
class HostComponent {
  readonly seen: { event: Event; inAngularZone: boolean }[] = [];
  readonly onScroll = (event: Event): void => {
    this.seen.push({ event, inAngularZone: NgZone.isInAngularZone() });
  };
}

function mount() {
  TestBed.configureTestingModule({ imports: [HostComponent] });
  const fixture = TestBed.createComponent(HostComponent);
  fixture.detectChanges();
  const scroller = (fixture.nativeElement as HTMLElement).querySelector('.scroller') as HTMLElement;
  return { fixture, scroller, host: fixture.componentInstance };
}

describe('ScrollOutsideZoneDirective', () => {
  it('hands every scroll event to the handler, outside the Angular zone', () => {
    const { scroller, host } = mount();
    const event = new Event('scroll');

    scroller.dispatchEvent(event);

    expect(host.seen).toHaveLength(1);
    expect(host.seen[0].event).toBe(event);
    // A template `(scroll)` listener would report true here: that tick per
    // scroll event is the whole reason the directive exists (#501).
    expect(host.seen[0].inAngularZone).toBe(false);
  });

  it('registers a passive listener, so it can never hold up the scroll', () => {
    const addEventListener = jest.spyOn(HTMLElement.prototype, 'addEventListener');
    try {
      mount();
      expect(addEventListener).toHaveBeenCalledWith('scroll', expect.any(Function), {
        passive: true,
      });
    } finally {
      addEventListener.mockRestore();
    }
  });

  it('stops listening once the host is destroyed', () => {
    const { fixture, scroller, host } = mount();
    fixture.destroy();

    scroller.dispatchEvent(new Event('scroll'));

    expect(host.seen).toHaveLength(0);
  });
});
