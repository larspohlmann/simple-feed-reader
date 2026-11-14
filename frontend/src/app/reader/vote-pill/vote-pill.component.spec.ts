import { ComponentFixture, TestBed } from '@angular/core/testing';
import { signal } from '@angular/core';
import { provideTranslocoTesting } from '../../../testing/transloco-testing';
import { Lang } from '../../core/i18n/language';
import { LanguageService } from '../../core/i18n/language.service';
import { VotePillComponent } from './vote-pill.component';
import { VoteValue } from './vote';

describe('VotePillComponent', () => {
  let fixture: ComponentFixture<VotePillComponent>;

  beforeEach(() => {
    TestBed.configureTestingModule({
      imports: [VotePillComponent, provideTranslocoTesting()],
      providers: [{ provide: LanguageService, useValue: { lang: signal<Lang>('en') } }],
    });
    fixture = TestBed.createComponent(VotePillComponent);
  });

  function render(
    score: number,
    variant?: 'framed' | 'bare',
    myVote: VoteValue | null = null,
  ): HTMLElement {
    fixture.componentRef.setInput('score', score);
    fixture.componentRef.setInput('myVote', myVote);
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

  describe('with a vote to give', () => {
    const button = (host: HTMLElement, name: string) =>
      host.querySelector(`button[aria-label="${name}"]`) as HTMLButtonElement;

    it('staysAnImageWithoutAVote', () => {
      const host = render(12);

      expect(host.querySelector('[role="img"]')).not.toBeNull();
      expect(host.querySelector('button')).toBeNull();
    });

    it('offersTwoButtonsWithAVote', () => {
      const host = render(12, undefined, 0);

      const group = host.querySelector('[role="group"]');
      expect(group?.getAttribute('aria-label')).toBe('12 points');
      expect(host.querySelector('[role="img"]')).toBeNull();
      expect(button(host, 'Upvote').getAttribute('aria-pressed')).toBe('false');
      expect(button(host, 'Downvote').getAttribute('aria-pressed')).toBe('false');
    });

    it('marksTheActiveArrow', () => {
      const host = render(12, undefined, 1);

      expect(button(host, 'Upvote').getAttribute('aria-pressed')).toBe('true');
      expect(button(host, 'Upvote').classList).toContain('voted');
      expect(button(host, 'Downvote').classList).not.toContain('voted');
    });

    it('marksTheDownvote', () => {
      const host = render(12, undefined, -1);

      expect(button(host, 'Downvote').getAttribute('aria-pressed')).toBe('true');
      expect(button(host, 'Downvote').classList).toContain('voted');
    });

    it.each<[VoteValue, string, VoteValue]>([
      [1, 'Upvote', 0],
      [0, 'Upvote', 1],
      [1, 'Downvote', -1],
      [-1, 'Downvote', 0],
    ])('emitsTheNextVote from %i pressing %s', (current, name, expected) => {
      const host = render(12, undefined, current);
      const emitted: VoteValue[] = [];
      fixture.componentInstance.voted.subscribe((vote) => emitted.push(vote));

      button(host, name).click();

      expect(emitted).toEqual([expected]);
    });

    it('keepsTheClickFromTheRow', () => {
      const host = render(12, undefined, 0);
      const rowClick = jest.fn();
      document.body.appendChild(host);
      host.addEventListener('click', rowClick);

      button(host, 'Upvote').click();

      expect(rowClick).not.toHaveBeenCalled();
      host.remove();
    });
  });
});
