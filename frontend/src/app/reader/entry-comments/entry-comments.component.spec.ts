import { ComponentFixture, TestBed } from '@angular/core/testing';
import { WritableSignal, signal } from '@angular/core';
import { EntryCommentsComponent } from './entry-comments.component';
import { CommentsService, CommentsState } from '../comments.service';
import { CommentsLoad } from '../models';
import { provideTranslocoTesting } from '../../../testing/transloco-testing';

const DISCUSSION = 'https://www.reddit.com/r/x/comments/1/t/';

describe('EntryCommentsComponent', () => {
  let fixture: ComponentFixture<EntryCommentsComponent>;
  let states: Map<number, WritableSignal<CommentsState>>;
  let service: { state: jest.Mock; load: jest.Mock; reload: jest.Mock };
  let observers: { init: IntersectionObserverInit | undefined; disconnect: jest.Mock }[];
  let trigger: ((visible: boolean) => void) | undefined;
  const realIntersectionObserver = globalThis.IntersectionObserver;

  beforeEach(() => {
    states = new Map();
    service = {
      state: jest.fn((id: number) => stateOf(id)),
      load: jest.fn(),
      reload: jest.fn(),
    };
    observers = [];
    trigger = undefined;
    globalThis.IntersectionObserver = jest.fn(
      (callback: IntersectionObserverCallback, init?: IntersectionObserverInit) => {
        const observer = { observe: jest.fn(), disconnect: jest.fn() };
        observers.push({ init, disconnect: observer.disconnect });
        trigger = (visible) =>
          callback(
            [{ isIntersecting: visible } as IntersectionObserverEntry],
            observer as unknown as IntersectionObserver,
          );
        return observer;
      },
    ) as unknown as typeof IntersectionObserver;
    TestBed.configureTestingModule({
      imports: [EntryCommentsComponent, provideTranslocoTesting()],
      providers: [{ provide: CommentsService, useValue: service }],
    });
    fixture = TestBed.createComponent(EntryCommentsComponent);
  });

  afterEach(() => {
    globalThis.IntersectionObserver = realIntersectionObserver;
  });

  function stateOf(id: number): WritableSignal<CommentsState> {
    const existing = states.get(id);
    if (existing) return existing;
    const created = signal<CommentsState>({ status: 'idle' });
    states.set(id, created);
    return created;
  }

  function show(comments: CommentsLoad, id = 5, discussionUrl: string | null = DISCUSSION): void {
    fixture.componentRef.setInput('entry', { id, comments, discussionUrl });
    fixture.detectChanges();
  }

  function settle(state: CommentsState): HTMLElement {
    stateOf(5).set(state);
    fixture.detectChanges();
    return fixture.nativeElement;
  }

  it('auto-loads only once the section nears the viewport', () => {
    show('auto');
    expect(service.load).not.toHaveBeenCalled();
    trigger!(false);
    expect(service.load).not.toHaveBeenCalled();
    trigger!(true);
    expect(service.load).toHaveBeenCalledWith(5);
  });

  it('looks ahead of the viewport', () => {
    show('auto');
    expect(observers[0].init?.rootMargin).toBe('400px 0px');
  });

  it('watches afresh for the next entry, loading it only once it is seen', () => {
    show('auto');
    show('auto', 6);
    expect(observers.length).toBe(2);
    expect(observers[0].disconnect).toHaveBeenCalled();
    trigger!(true);
    expect(service.load).toHaveBeenCalledTimes(1);
    expect(service.load).toHaveBeenCalledWith(6);
  });

  it('stops watching once destroyed', () => {
    show('auto');
    fixture.destroy();
    expect(observers[0].disconnect).toHaveBeenCalled();
  });

  it('never watches a manual source; the button loads it', () => {
    show('manual');
    expect(observers.length).toBe(0);
    fixture.nativeElement.querySelector('button.load').click();
    expect(service.load).toHaveBeenCalledWith(5);
  });

  it('offers no load button for an auto source', () => {
    show('auto');
    expect(fixture.nativeElement.querySelector('button.load')).toBeNull();
  });

  it('shows a skeleton while loading', () => {
    show('auto');
    const el = settle({ status: 'loading' });
    expect(el.querySelector('app-skeleton')).not.toBeNull();
    expect(el.querySelector('section')?.getAttribute('aria-busy')).toBe('true');
  });

  it('renders comments with the OP pill and the count', () => {
    show('auto');
    const el = settle({
      status: 'ok',
      comments: [
        {
          author: '/u/op',
          authorUrl: 'https://www.reddit.com/user/op',
          url: 'https://c/1',
          publishedAt: new Date(Date.now() - 3 * 3_600_000).toISOString(),
          html: '<p>Hi</p>',
          byEntryAuthor: true,
        },
        {
          author: '/u/b',
          authorUrl: null,
          url: null,
          publishedAt: null,
          html: '<p>Yo</p>',
          byEntryAuthor: false,
        },
      ],
    });
    const rows = el.querySelectorAll('.comment');
    expect(rows.length).toBe(2);
    expect(el.querySelectorAll('.op').length).toBe(1);
    expect(el.querySelector('.count')?.textContent?.trim()).toBe('2');
    expect(rows[0].querySelector('a.author')?.getAttribute('href')).toBe(
      'https://www.reddit.com/user/op',
    );
    expect(rows[0].querySelector('a.when')?.getAttribute('href')).toBe('https://c/1');
    expect(rows[0].querySelector('a.when')?.textContent?.trim()).toBe('3 hr. ago');
    expect(rows[0].querySelector('.body')?.innerHTML).toBe('<p>Hi</p>');
    expect(rows[1].querySelector('a.author')).toBeNull();
    expect(rows[1].querySelector('.when')).toBeNull();
    expect(el.querySelector('a.all')?.getAttribute('href')).toBe(DISCUSSION);
  });

  it('shows the empty line for zero comments', () => {
    show('auto');
    const el = settle({ status: 'ok', comments: [] });
    expect(el.querySelector('.empty')?.textContent?.trim()).toBe('No comments yet.');
  });

  it('drops the closing link without a discussion URL', () => {
    show('manual', 5, null);
    const el = settle({ status: 'ok', comments: [] });
    expect(el.querySelector('a.all')).toBeNull();
  });

  it('reload calls the service', () => {
    show('auto');
    const el = settle({ status: 'ok', comments: [] });
    const reload = el.querySelector<HTMLButtonElement>('button.reload')!;
    expect(reload.getAttribute('aria-label')).toBe('Reload comments');
    reload.click();
    expect(service.reload).toHaveBeenCalledWith(5);
  });

  describe('throttled', () => {
    beforeEach(() => jest.useFakeTimers({ now: new Date('2026-09-24T12:00:00Z') }));
    afterEach(() => jest.useRealTimers());

    it('counts down to the retry and retries through reload', () => {
      show('auto');
      const el = settle({ status: 'throttled', retryAt: Date.now() + 30_000 });
      const box = el.querySelector('app-warning-box')!;
      expect(box.textContent).toContain('Try again in 30 s.');

      jest.advanceTimersByTime(29_000);
      fixture.detectChanges();
      expect(box.textContent).toContain('Try again in 1 s.');

      jest.advanceTimersByTime(1_000);
      fixture.detectChanges();
      expect(box.textContent).toContain('Try again now.');
      expect(box.querySelector('a')?.getAttribute('href')).toBe(DISCUSSION);

      box.querySelector<HTMLButtonElement>('button.retry')!.click();
      expect(service.reload).toHaveBeenCalledWith(5);
    });
  });

  it('shows a quiet failure with a retry', () => {
    show('auto');
    const el = settle({ status: 'failed' });
    const failed = el.querySelector('.failed')!;
    expect(failed.textContent).toContain('Comments could not be loaded.');
    expect(failed.querySelector('a')?.getAttribute('href')).toBe(DISCUSSION);
    expect(el.querySelector('app-warning-box')).toBeNull();
    failed.querySelector<HTMLButtonElement>('button.retry')!.click();
    expect(service.reload).toHaveBeenCalledWith(5);
  });
});
