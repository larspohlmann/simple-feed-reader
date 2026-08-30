import { ComponentFixture, TestBed } from '@angular/core/testing';
import { By } from '@angular/platform-browser';
import { of } from 'rxjs';
import { MagazineStyleService } from '../../core/magazine-style.service';
import { MAGAZINE_STYLE_WRITER } from '../../core/magazine-style-writer';
import { MagazineStyleSwitcherComponent } from './magazine-style-switcher.component';
import { provideTranslocoTesting } from '../../../testing/transloco-testing';

describe('MagazineStyleSwitcherComponent', () => {
  let fixture: ComponentFixture<MagazineStyleSwitcherComponent>;
  let service: MagazineStyleService;

  beforeEach(async () => {
    localStorage.clear();
    await TestBed.configureTestingModule({
      imports: [MagazineStyleSwitcherComponent, provideTranslocoTesting()],
      providers: [{ provide: MAGAZINE_STYLE_WRITER, useValue: { write: () => of(true) } }],
    }).compileComponents();
    fixture = TestBed.createComponent(MagazineStyleSwitcherComponent);
    service = TestBed.inject(MagazineStyleService);
    fixture.detectChanges();
  });

  function buttons(): HTMLButtonElement[] {
    return fixture.debugElement.queryAll(By.css('button')).map((d) => d.nativeElement);
  }

  it('offers exactly the two styles', () => {
    expect(buttons()).toHaveLength(2);
  });

  it('marks the active style pressed', () => {
    expect(buttons()[0].getAttribute('aria-pressed')).toBe('true');
    expect(buttons()[1].getAttribute('aria-pressed')).toBe('false');
  });

  it('switches the style on click', () => {
    buttons()[1].click();
    fixture.detectChanges();

    expect(service.style()).toBe('airy');
    expect(buttons()[1].getAttribute('aria-pressed')).toBe('true');
  });
});
