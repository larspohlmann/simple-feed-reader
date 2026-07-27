import { Component, signal } from '@angular/core';
import { ComponentFixture, TestBed } from '@angular/core/testing';
import { By } from '@angular/platform-browser';
import { DismissOnOutsideDirective } from './dismiss-on-outside.directive';

@Component({
  imports: [DismissOnOutsideDirective],
  template: `
    <div class="wrapper" [appDismissOnOutside]="open()" (dismiss)="dismissed = dismissed + 1">
      <button class="trigger" type="button">menu</button>
      @if (open()) {
        <div class="pop"><button class="item" type="button">Edit</button></div>
      }
    </div>
    <div class="elsewhere"></div>
  `,
})
class HostComponent {
  readonly open = signal(true);
  dismissed = 0;
}

describe('DismissOnOutsideDirective', () => {
  let fixture: ComponentFixture<HostComponent>;
  let host: HostComponent;

  const pointerDownOn = (selector: string) => {
    const target =
      selector === 'document'
        ? document.body
        : (fixture.debugElement.query(By.css(selector)).nativeElement as Element);
    // jsdom has no PointerEvent constructor; the directive only reads the target.
    target.dispatchEvent(new Event('pointerdown', { bubbles: true }));
  };

  const pressKey = (key: string) =>
    document.dispatchEvent(new KeyboardEvent('keydown', { key, bubbles: true }));

  beforeEach(() => {
    TestBed.configureTestingModule({ imports: [HostComponent] });
    fixture = TestBed.createComponent(HostComponent);
    host = fixture.componentInstance;
    fixture.detectChanges();
  });

  it('dismisses when the pointer goes down anywhere outside the wrapper', () => {
    pointerDownOn('.elsewhere');
    expect(host.dismissed).toBe(1);
  });

  it('leaves a press on the trigger alone, so its own toggle still closes the menu', () => {
    pointerDownOn('.trigger');
    expect(host.dismissed).toBe(0);
  });

  it('leaves a press on a menu item alone', () => {
    pointerDownOn('.item');
    expect(host.dismissed).toBe(0);
  });

  it('dismisses on Escape', () => {
    pressKey('Escape');
    expect(host.dismissed).toBe(1);
  });

  it('ignores other keys', () => {
    pressKey('a');
    expect(host.dismissed).toBe(0);
  });

  it('stops listening once closed', () => {
    host.open.set(false);
    fixture.detectChanges();

    pointerDownOn('.elsewhere');
    pressKey('Escape');

    expect(host.dismissed).toBe(0);
  });
});
