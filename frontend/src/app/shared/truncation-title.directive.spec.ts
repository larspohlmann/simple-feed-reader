import { Component } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { TruncationTitleDirective } from './truncation-title.directive';

@Component({
  imports: [TruncationTitleDirective],
  template: `<span class="name" appTruncationTitle> A rather long feed name </span>`,
})
class HostComponent {}

describe('TruncationTitleDirective', () => {
  let name: HTMLElement;

  const layOut = (scrollWidth: number, clientWidth: number) => {
    Object.defineProperty(name, 'scrollWidth', { configurable: true, value: scrollWidth });
    Object.defineProperty(name, 'clientWidth', { configurable: true, value: clientWidth });
  };

  const hover = () => name.dispatchEvent(new MouseEvent('mouseenter'));

  beforeEach(() => {
    const fixture = TestBed.createComponent(HostComponent);
    fixture.detectChanges();
    name = fixture.nativeElement.querySelector('.name');
  });

  it('shows the full text when the text is cut off', () => {
    layOut(200, 120);
    hover();
    expect(name.title).toBe('A rather long feed name');
  });

  it('shows no tooltip when the text fits', () => {
    layOut(120, 120);
    hover();
    expect(name.hasAttribute('title')).toBe(false);
  });

  it('drops the tooltip once the text fits again', () => {
    layOut(200, 120);
    hover();
    layOut(200, 240);
    hover();
    expect(name.hasAttribute('title')).toBe(false);
  });
});
