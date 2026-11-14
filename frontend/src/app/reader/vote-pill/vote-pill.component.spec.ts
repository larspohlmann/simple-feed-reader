import { ComponentFixture, TestBed } from '@angular/core/testing';
import { signal } from '@angular/core';
import { provideTranslocoTesting } from '../../../testing/transloco-testing';
import { Lang } from '../../core/i18n/language';
import { LanguageService } from '../../core/i18n/language.service';
import { VotePillComponent } from './vote-pill.component';

describe('VotePillComponent', () => {
  let fixture: ComponentFixture<VotePillComponent>;

  beforeEach(() => {
    TestBed.configureTestingModule({
      imports: [VotePillComponent, provideTranslocoTesting()],
      providers: [{ provide: LanguageService, useValue: { lang: signal<Lang>('en') } }],
    });
    fixture = TestBed.createComponent(VotePillComponent);
  });

  function render(score: number, variant?: 'framed' | 'bare'): HTMLElement {
    fixture.componentRef.setInput('score', score);
    if (variant) fixture.componentRef.setInput('variant', variant);
    fixture.detectChanges();
    return fixture.nativeElement;
  }

  it('rendersTheScoreBetweenTwoArrows', () => {
    const host = render(1284);

    expect(host.querySelector('.number')?.textContent?.trim()).toBe('1.3K');
    const icons = Array.from(host.querySelectorAll('.arrow app-icon'));
    expect(icons.map((icon) => icon.getAttribute('name'))).toEqual([
      'arrow_upward',
      'arrow_downward',
    ]);
    expect(icons.every((icon) => icon.getAttribute('aria-hidden') === 'true')).toBe(true);
  });

  it.each([
    [0, '0'],
    [-3, '-3'],
  ])('rendersZeroAndNegativeScores %i', (score, text) => {
    expect(render(score).querySelector('.number')?.textContent?.trim()).toBe(text);
  });

  it.each([
    [1, '1 point'],
    [1284, '1284 points'],
  ])('labelsThePillForScreenReaders %i', (score, label) => {
    const pill = render(score).querySelector('[role="img"]');

    expect(pill?.getAttribute('aria-label')).toBe(label);
  });

  it('marksTheBareVariant', () => {
    expect(render(5, 'bare').classList.contains('bare')).toBe(true);
  });

  it('leavesTheFramedVariantUnmarked', () => {
    expect(render(5).classList.contains('bare')).toBe(false);
  });
});
