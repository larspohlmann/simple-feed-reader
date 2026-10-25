import { TestBed } from '@angular/core/testing';
import { provideTranslocoTesting } from '../../../testing/transloco-testing';
import { DayChartComponent } from './day-chart.component';
import { ChartDay } from './day-chart';

describe('DayChartComponent', () => {
  function mount(days: ChartDay[]) {
    TestBed.configureTestingModule({
      imports: [DayChartComponent, provideTranslocoTesting()],
    });
    const fixture = TestBed.createComponent(DayChartComponent);
    fixture.componentRef.setInput('days', days);
    fixture.componentRef.setInput('label', 'New articles');
    fixture.detectChanges();
    return fixture;
  }

  it('draws one hover column per day and names the busiest known day at rest', () => {
    const fixture = mount([
      { date: '2026-09-01', count: null },
      { date: '2026-09-02', count: 2 },
      { date: '2026-09-03', count: 4 },
    ]);

    expect(fixture.nativeElement.querySelectorAll('.chart-col')).toHaveLength(3);
    expect(fixture.nativeElement.querySelector('.chart-tip strong')?.textContent).toBe('4');
  });

  it('is a group named by its label', () => {
    const figure = mount([{ date: '2026-09-01', count: 1 }]).nativeElement.querySelector('.chart');

    expect(figure?.getAttribute('role')).toBe('group');
    expect(figure?.getAttribute('aria-label')).toBe('New articles');
  });

  it('says a hovered unknown day has no data and draws no marker on it', () => {
    const fixture = mount([
      { date: '2026-09-01', count: null },
      { date: '2026-09-02', count: 5 },
    ]);
    fixture.componentInstance.hover(0);
    fixture.detectChanges();

    expect(fixture.nativeElement.querySelector('.chart-marker')).toBeNull();
    expect(fixture.nativeElement.querySelector('.chart-tip')?.textContent).toContain('No data');
  });
});
