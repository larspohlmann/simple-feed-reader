import { TestBed } from '@angular/core/testing';
import { Component } from '@angular/core';
import { IconComponent } from './icon.component';

@Component({ imports: [IconComponent], template: `<app-icon name="settings" />` })
class Host {}

describe('IconComponent', () => {
  it('renders the ligature name inside a material-symbols span', async () => {
    await TestBed.configureTestingModule({ imports: [Host] }).compileComponents();
    const fixture = TestBed.createComponent(Host);
    fixture.detectChanges();
    const span: HTMLElement = fixture.nativeElement.querySelector('span.material-symbols-outlined');
    expect(span.textContent?.trim()).toBe('settings');
    expect(span.getAttribute('aria-hidden')).toBe('true');
  });
});
