import { Component, signal } from '@angular/core';
import { ComponentFixture, TestBed } from '@angular/core/testing';
import { By } from '@angular/platform-browser';
import { DrawerSwipeDirective } from './drawer-swipe.directive';

@Component({
  imports: [DrawerSwipeDirective],
  template: `
    <div
      class="host"
      appDrawerSwipe
      [appDrawerSwipeOpen]="open()"
      [appDrawerSwipeDisabled]="disabled()"
      (appDrawerSwipeOpenDrawer)="opened = opened + 1"
      (appDrawerSwipeCloseDrawer)="closed = closed + 1"
    >
      <aside data-drawer-panel><button class="in-panel" type="button">x</button></aside>
      <div class="content"></div>
    </div>
  `,
})
class HostComponent {
  readonly open = signal(false);
  readonly disabled = signal(false);
  opened = 0;
  closed = 0;
}

describe('DrawerSwipeDirective', () => {
  let fixture: ComponentFixture<HostComponent>;
  let host: HostComponent;
  let dir: DrawerSwipeDirective;

  const touch = (x: number, y: number, target?: Element) =>
    ({
      touches: [{ clientX: x, clientY: y }],
      target: target ?? null,
    }) as unknown as TouchEvent;

  function swipe(fromX: number, toX: number, y = 0, target?: Element) {
    dir.onTouchStart(touch(fromX, y, target));
    dir.onTouchMove(touch(toX, y, target));
    dir.onTouchEnd();
  }

  beforeEach(() => {
    TestBed.configureTestingModule({ imports: [HostComponent] });
    fixture = TestBed.createComponent(HostComponent);
    host = fixture.componentInstance;
    dir = fixture.debugElement
      .query(By.directive(DrawerSwipeDirective))
      .injector.get(DrawerSwipeDirective);
    fixture.detectChanges();
  });

  it('opens on a rightward swipe when closed', () => {
    swipe(20, 140);
    expect(host.opened).toBe(1);
    expect(host.closed).toBe(0);
  });

  it('closes on a leftward swipe when open', () => {
    host.open.set(true);
    fixture.detectChanges();
    swipe(200, 40);
    expect(host.closed).toBe(1);
    expect(host.opened).toBe(0);
  });

  it('does not open on a leftward swipe when already closed', () => {
    swipe(200, 40);
    expect(host.opened).toBe(0);
  });

  it('ignores swipes when disabled', () => {
    host.disabled.set(true);
    fixture.detectChanges();
    swipe(20, 140);
    expect(host.opened).toBe(0);
  });

  it('ignores a mostly-vertical drag (a scroll)', () => {
    dir.onTouchStart(touch(20, 0));
    dir.onTouchMove(touch(40, 200));
    dir.onTouchEnd();
    expect(host.opened).toBe(0);
  });

  it('leaves touches that begin inside the open drawer panel to the panel', () => {
    host.open.set(true);
    fixture.detectChanges();
    const inPanel = fixture.debugElement.query(By.css('.in-panel')).nativeElement as Element;
    swipe(200, 40, 0, inPanel);
    expect(host.closed).toBe(0);
  });
});
