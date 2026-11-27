import { TestBed } from '@angular/core/testing';
import { EMPTY, Subject } from 'rxjs';
import { provideTranslocoTesting } from '../../../../testing/transloco-testing';
import { anEntry } from '../../../../testing/entry-testing';
import { EntryDto } from '../../models';
import { EntryActionHandler } from '../entry-actions/entry-action-handler';
import { EntryLikeComponent } from './entry-like.component';

describe('EntryLikeComponent', () => {
  const actions = { like: jest.fn() };
  const network = { network: 'bluesky' as const, isLiked: false, canLike: true, isOwn: false };

  function mount(overrides: Partial<EntryDto>, countSide?: 'start' | 'end'): HTMLElement {
    actions.like.mockReset();
    actions.like.mockReturnValue(EMPTY);
    TestBed.configureTestingModule({
      imports: [EntryLikeComponent, provideTranslocoTesting()],
      providers: [{ provide: EntryActionHandler, useValue: actions }],
    });
    const fixture = TestBed.createComponent(EntryLikeComponent);
    fixture.componentRef.setInput('entry', anEntry(overrides));
    if (countSide !== undefined) fixture.componentRef.setInput('countSide', countSide);
    fixture.detectChanges();
    return fixture.nativeElement as HTMLElement;
  }

  const button = (element: HTMLElement) => element.querySelector('button') as HTMLButtonElement;

  it('gives an article an unfilled local toggle', () => {
    const element = mount({ social: null, myVote: null });

    expect(button(element).getAttribute('aria-label')).toBe('Like');
    expect(button(element).getAttribute('aria-pressed')).toBe('false');
    expect(element.querySelector('app-icon')?.getAttribute('name')).toBe('thumb_up');
  });

  it('shows a liked article as pressed and offers to unlike', () => {
    const element = mount({ social: null, myVote: null, isLiked: true });

    expect(button(element).getAttribute('aria-label')).toBe('Unlike');
    expect(button(element).getAttribute('aria-pressed')).toBe('true');
  });

  it('hands the entry to the like action on a click', () => {
    const element = mount({ social: null, myVote: null });

    button(element).click();

    expect(actions.like).toHaveBeenCalledTimes(1);
  });

  it('keeps the count of a post the reader cannot like on its network', () => {
    const element = mount({ social: { ...network, canLike: false }, likeCount: 7 });

    expect(button(element).getAttribute('aria-label')).toBe('Like, 7 likes');
    expect(element.querySelector('.count')?.textContent).toBe('7');
  });

  it('labels a post the reader can like on its network by the network', () => {
    const element = mount({ social: network, likeCount: 7 });

    expect(button(element).getAttribute('aria-label')).toBe('Like on Bluesky, 7 likes');
  });

  it('reads a network like from the post, not from the local like', () => {
    const element = mount({ social: { ...network, isLiked: true }, isLiked: false });

    expect(button(element).getAttribute('aria-label')).toBe('Unlike on Bluesky');
    expect(button(element).getAttribute('aria-pressed')).toBe('true');
  });

  it('renders nothing for a Lemmy post the reader voted on', () => {
    const element = mount({ social: null, myVote: 1, score: 3 });

    expect(button(element)).toBeNull();
  });

  it('gives a Lemmy post without a vote the local toggle', () => {
    const element = mount({ social: null, myVote: null, score: 3 });

    expect(button(element).getAttribute('aria-label')).toBe('Like');
  });

  describe('countSide', () => {
    const precedes = (element: HTMLElement): boolean =>
      !!(
        element
          .querySelector('.count')!
          .compareDocumentPosition(element.querySelector('app-icon')!) &
        Node.DOCUMENT_POSITION_FOLLOWING
      );

    it('puts the count before the thumb when countSide is start', () => {
      const element = mount({ social: null, myVote: null, likeCount: 12 }, 'start');

      expect(precedes(element.querySelector('button')!)).toBe(true);
    });

    it('puts the bare count of a voted Lemmy post before the thumb when countSide is start', () => {
      const element = mount({ social: null, myVote: 1, score: 3, likeCount: 12 }, 'start');

      expect(precedes(element.querySelector('.likes')!)).toBe(true);
    });

    it('keeps the thumb first by default', () => {
      const element = mount({ social: null, myVote: null, likeCount: 12 });

      expect(precedes(element.querySelector('button')!)).toBe(false);
    });
  });

  describe('pending display', () => {
    function mountPending(overrides: Partial<EntryDto>) {
      const answer = new Subject<void>();
      actions.like.mockReset();
      actions.like.mockReturnValue(answer);
      TestBed.configureTestingModule({
        imports: [EntryLikeComponent, provideTranslocoTesting()],
        providers: [{ provide: EntryActionHandler, useValue: actions }],
      });
      const fixture = TestBed.createComponent(EntryLikeComponent);
      fixture.componentRef.setInput('entry', anEntry(overrides));
      fixture.detectChanges();
      return { fixture, answer, host: fixture.nativeElement as HTMLElement };
    }

    it('flips the thumb at once and shows no dots when the entry has no like count', () => {
      const { fixture, host } = mountPending({ social: null, myVote: null, likeCount: null });

      button(host).click();
      fixture.componentRef.setInput(
        'entry',
        anEntry({ social: null, myVote: null, likeCount: null, isLiked: true }),
      );
      fixture.detectChanges();

      expect(host.querySelector('app-pending-dots')).toBeNull();
      expect(host.querySelector('app-icon')?.getAttribute('name')).toBe('thumb_up');
      expect(button(host).hasAttribute('aria-busy')).toBe(false);
      expect(button(host).getAttribute('aria-pressed')).toBe('true');
    });

    it('ignores a second click while a no-count like is in flight', () => {
      const { host } = mountPending({ social: null, myVote: null, likeCount: null });

      button(host).click();
      button(host).click();

      expect(actions.like).toHaveBeenCalledTimes(1);
    });

    it('shows the dots while a like count waits for the answer', () => {
      const { fixture, answer, host } = mountPending({
        social: null,
        myVote: null,
        likeCount: 12,
      });

      button(host).click();
      fixture.detectChanges();

      expect(host.querySelector('app-pending-dots')).not.toBeNull();
      expect(host.querySelector('app-icon')).toBeNull();
      expect(button(host).getAttribute('aria-busy')).toBe('true');

      answer.complete();
      fixture.detectChanges();

      expect(host.querySelector('app-pending-dots')).toBeNull();
      expect(host.querySelector('app-icon')?.getAttribute('name')).toBe('thumb_up');
    });
  });
});
