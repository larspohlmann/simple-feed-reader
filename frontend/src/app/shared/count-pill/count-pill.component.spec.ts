import { ComponentFixture, TestBed } from '@angular/core/testing';
import { CountPillComponent } from './count-pill.component';

describe('CountPillComponent', () => {
  let fixture: ComponentFixture<CountPillComponent>;

  beforeEach(async () => {
    await TestBed.configureTestingModule({ imports: [CountPillComponent] }).compileComponents();
    fixture = TestBed.createComponent(CountPillComponent);
  });

  it('shows the count', () => {
    fixture.componentRef.setInput('count', 4);
    fixture.detectChanges();

    expect(fixture.nativeElement.textContent.trim()).toBe('4');
  });
});
