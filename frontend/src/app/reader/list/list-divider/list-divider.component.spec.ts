import { TestBed } from '@angular/core/testing';
import { ListDividerComponent } from './list-divider.component';

describe('ListDividerComponent', () => {
  it('names the separator with its label, since a separator hides its children from screen readers', () => {
    TestBed.configureTestingModule({ imports: [ListDividerComponent] });
    const fixture = TestBed.createComponent(ListDividerComponent);
    fixture.componentRef.setInput('label', 'Before your last visit');
    fixture.detectChanges();

    const separator = (fixture.nativeElement as HTMLElement).querySelector('[role="separator"]');
    expect(separator?.getAttribute('aria-label')).toBe('Before your last visit');
    expect(separator?.textContent?.trim()).toBe('Before your last visit');
  });
});
