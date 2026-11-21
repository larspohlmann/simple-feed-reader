import { TestBed } from '@angular/core/testing';
import { PendingDotsComponent } from './pending-dots.component';

describe('PendingDotsComponent', () => {
  const mount = (size?: 'sm' | 'md') => {
    const fixture = TestBed.createComponent(PendingDotsComponent);
    if (size) fixture.componentRef.setInput('size', size);
    fixture.detectChanges();
    return fixture.nativeElement as HTMLElement;
  };

  it('draws three dots, hidden from assistive tech', () => {
    const element = mount();
    expect(element.querySelectorAll('i')).toHaveLength(3);
    expect(element.getAttribute('aria-hidden')).toBe('true');
  });

  it('takes the md icon slot when asked', () => {
    expect(mount('md').classList).toContain('md');
    expect(mount().classList).not.toContain('md');
  });
});
