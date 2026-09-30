import { TestBed } from '@angular/core/testing';
import { provideTranslocoTesting } from '../../../testing/transloco-testing';
import { ColorFieldComponent } from './color-field.component';
import { TAG_COLORS } from '../icon-choices';

describe('ColorFieldComponent', () => {
  const mount = async (value: string | null = null) => {
    await TestBed.configureTestingModule({
      imports: [ColorFieldComponent, provideTranslocoTesting()],
    }).compileComponents();
    const fixture = TestBed.createComponent(ColorFieldComponent);
    fixture.componentRef.setInput('value', value);
    fixture.detectChanges();
    return fixture;
  };

  it('renders one button per preset swatch', async () => {
    const element: HTMLElement = (await mount()).nativeElement;
    expect(element.querySelectorAll('.swatch').length).toBe(TAG_COLORS.length);
  });

  it('marks the swatch matching the current value', async () => {
    const element: HTMLElement = (await mount(TAG_COLORS[0])).nativeElement;
    expect(element.querySelector('.swatch.on')).not.toBeNull();
  });

  it('names every swatch for assistive tech', async () => {
    const element: HTMLElement = (await mount()).nativeElement;
    const labels = Array.from(element.querySelectorAll('.swatch')).map((swatch) =>
      swatch.getAttribute('aria-label'),
    );
    expect(labels).toEqual(TAG_COLORS.map((preset) => `Colour ${preset}`));
  });

  it('emits the picked colour', async () => {
    const fixture = await mount();
    const picked: (string | null)[] = [];
    fixture.componentInstance.valueChange.subscribe((value: string | null) => picked.push(value));

    (fixture.nativeElement.querySelector('.swatch') as HTMLButtonElement).click();
    expect(picked).toEqual([TAG_COLORS[0]]);
  });

  it('emits null when cleared', async () => {
    const fixture = await mount(TAG_COLORS[0]);
    const picked: (string | null)[] = [];
    fixture.componentInstance.valueChange.subscribe((value: string | null) => picked.push(value));

    (fixture.nativeElement.querySelector('.clear') as HTMLButtonElement).click();
    expect(picked).toEqual([null]);
  });

  it('drops the clear button where a colour is mandatory', async () => {
    const fixture = await mount(TAG_COLORS[0]);
    fixture.componentRef.setInput('clearable', false);
    fixture.detectChanges();

    expect(fixture.nativeElement.querySelector('.clear')).toBeNull();
  });
});
