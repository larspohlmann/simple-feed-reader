import { ComponentFixture, TestBed } from '@angular/core/testing';
import { ToggleComponent } from './toggle.component';

describe('ToggleComponent', () => {
  let fixture: ComponentFixture<ToggleComponent>;

  const input = (): HTMLInputElement =>
    fixture.nativeElement.querySelector('input[type="checkbox"]');

  beforeEach(async () => {
    await TestBed.configureTestingModule({ imports: [ToggleComponent] }).compileComponents();
    fixture = TestBed.createComponent(ToggleComponent);
    fixture.componentRef.setInput('label', 'Enable scraping');
    fixture.detectChanges();
  });

  it('reflects the checked input', () => {
    fixture.componentRef.setInput('checked', true);
    fixture.detectChanges();

    expect(input().checked).toBe(true);
  });

  it('emits the new value when clicked', () => {
    const seen: boolean[] = [];
    fixture.componentInstance.toggled.subscribe((v) => seen.push(v));

    input().click();
    fixture.detectChanges();

    expect(seen).toEqual([true]);
  });

  it('labels the control for assistive technology', () => {
    expect(input().getAttribute('aria-label')).toBe('Enable scraping');
  });

  it('carries no id by default', () => {
    expect(input().hasAttribute('id')).toBe(false);
  });

  it('exposes the given id on the native checkbox, so an external label can target it', () => {
    fixture.componentRef.setInput('inputId', 'scrape-fallback-toggle');
    fixture.detectChanges();

    expect(input().id).toBe('scrape-fallback-toggle');
  });
});
