import { ComponentFixture, TestBed } from '@angular/core/testing';
import { ProgressHairlineComponent } from './progress-hairline.component';

describe('ProgressHairlineComponent', () => {
  let fixture: ComponentFixture<ProgressHairlineComponent>;

  beforeEach(async () => {
    await TestBed.configureTestingModule({
      imports: [ProgressHairlineComponent],
    }).compileComponents();
    fixture = TestBed.createComponent(ProgressHairlineComponent);
  });

  it('renders nothing when idle', () => {
    fixture.componentRef.setInput('active', false);
    fixture.componentRef.setInput('value', 0);
    fixture.detectChanges();

    expect(fixture.nativeElement.querySelector('.bar')).toBeNull();
  });

  it('exposes the progress to assistive technology', () => {
    fixture.componentRef.setInput('active', true);
    fixture.componentRef.setInput('value', 0.42);
    fixture.detectChanges();

    const bar = fixture.nativeElement.querySelector('.bar');
    expect(bar.getAttribute('role')).toBe('progressbar');
    expect(bar.getAttribute('aria-valuenow')).toBe('42');
  });
});
