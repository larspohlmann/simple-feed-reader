import { TestBed } from '@angular/core/testing';
import { Component } from '@angular/core';
import { WarningBoxComponent } from './warning-box.component';

@Component({
  imports: [WarningBoxComponent],
  template: `<app-warning-box><span class="body">The preview ends here</span></app-warning-box>`,
})
class Host {}

describe('WarningBoxComponent', () => {
  const mount = async () => {
    await TestBed.configureTestingModule({ imports: [Host] }).compileComponents();
    const fixture = TestBed.createComponent(Host);
    fixture.detectChanges();
    return fixture;
  };

  it('renders its projected content', async () => {
    const el: HTMLElement = (await mount()).nativeElement;
    const box = el.querySelector('app-warning-box') as HTMLElement;
    expect(box).not.toBeNull();
    expect(box.querySelector('.body')?.textContent).toBe('The preview ends here');
  });
});
