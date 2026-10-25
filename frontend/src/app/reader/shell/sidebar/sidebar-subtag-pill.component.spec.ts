import { ComponentFixture, TestBed } from '@angular/core/testing';
import { provideTranslocoTesting } from '../../../../testing/transloco-testing';
import { SidebarSubtagPillComponent } from './sidebar-subtag-pill.component';

function mount(count: number): ComponentFixture<SidebarSubtagPillComponent> {
  const fixture = TestBed.createComponent(SidebarSubtagPillComponent);
  fixture.componentRef.setInput('count', count);
  fixture.detectChanges();
  return fixture;
}

const shown = (fixture: ComponentFixture<SidebarSubtagPillComponent>): string =>
  (fixture.nativeElement as HTMLElement).querySelector('[aria-hidden]')?.textContent?.trim() ?? '';

const announced = (fixture: ComponentFixture<SidebarSubtagPillComponent>): string =>
  (fixture.nativeElement as HTMLElement).querySelector('.sr-only')?.textContent?.trim() ?? '';

describe('SidebarSubtagPillComponent', () => {
  beforeEach(() => TestBed.configureTestingModule({ imports: [provideTranslocoTesting()] }));

  it('shows the subtag count up to nine', () => {
    expect(shown(mount(9))).toBe('9');
  });

  it('shows 9+ past nine subtags but announces the exact count', () => {
    const fixture = mount(12);

    expect(shown(fixture)).toBe('9+');
    expect(announced(fixture)).toBe('12 subtags');
  });

  it('hides itself when there are no subtags', () => {
    expect((mount(0).nativeElement as HTMLElement).hidden).toBe(true);
    expect((mount(3).nativeElement as HTMLElement).hidden).toBe(false);
  });

  it('announces a single subtag in the singular', () => {
    expect(announced(mount(1))).toBe('1 subtag');
  });
});
