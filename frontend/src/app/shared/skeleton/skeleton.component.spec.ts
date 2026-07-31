import { TestBed } from '@angular/core/testing';
import { SkeletonComponent } from './skeleton.component';

describe('SkeletonComponent', () => {
  async function render(rows?: number) {
    await TestBed.configureTestingModule({ imports: [SkeletonComponent] }).compileComponents();
    const fixture = TestBed.createComponent(SkeletonComponent);
    fixture.componentRef.setInput('label', 'Loading tags');
    if (rows !== undefined) fixture.componentRef.setInput('rows', rows);
    fixture.detectChanges();
    return fixture.nativeElement as HTMLElement;
  }

  it('renders three placeholder rows by default', async () => {
    const el = await render();
    expect(el.querySelectorAll('.row')).toHaveLength(3);
  });

  it('renders the requested number of rows', async () => {
    const el = await render(6);
    expect(el.querySelectorAll('.row')).toHaveLength(6);
  });

  it('announces the load with the given label', async () => {
    const el = await render();
    const status = el.querySelector('[role="status"]');
    expect(status?.getAttribute('aria-label')).toBe('Loading tags');
  });

  it('hides the placeholder rows from assistive technology', async () => {
    const el = await render();
    // The rows are decoration; the role=status label is what gets announced.
    expect(el.querySelector('.rows')?.getAttribute('aria-hidden')).toBe('true');
  });
});
