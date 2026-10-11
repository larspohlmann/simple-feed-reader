import { ElementRef, signal } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { LanguageService } from '../../../core/i18n/language.service';
import { ReadingFocusService } from '../../../core/preferences/reading-focus.service';
import { articleHost } from '../../../../testing/article-host-testing';
import { LayoutService } from '../../layout.service';
import { ReadingFocusApplier, type ReadingFocusConfig } from './reading-focus-applier';
import { ReadingScope } from './reading-scope.service';

jest.mock('./reading-focus-applier', () => ({
  ReadingFocusApplier: jest.fn().mockImplementation(() => ({
    refresh: jest.fn(),
    clear: jest.fn(),
    destroy: jest.fn(),
  })),
}));

class MockResizeObserver {
  static instances: MockResizeObserver[] = [];
  readonly targets = new Set<Element>();
  constructor(readonly callback: ResizeObserverCallback) {
    MockResizeObserver.instances.push(this);
  }
  observe(target: Element): void {
    this.targets.add(target);
  }
  unobserve(target: Element): void {
    this.targets.delete(target);
  }
  disconnect(): void {
    this.targets.clear();
  }
}

const VIEWPORT = 800;

/** A part of the scope whose box ends `bottom` pixels into the pane. */
function partEndingAt(bottom: number, html = '<p>Text</p>'): HTMLElement {
  const part = articleHost(html);
  part.getBoundingClientRect = () => ({ top: 0, bottom }) as DOMRect;
  return part;
}

function scrollerOfViewport(): HTMLElement {
  const scroller = document.createElement('div');
  Object.defineProperty(scroller, 'clientHeight', { configurable: true, value: VIEWPORT });
  scroller.getBoundingClientRect = () => ({ top: 0, bottom: VIEWPORT }) as DOMRect;
  return scroller;
}

const query = (element: HTMLElement | undefined) =>
  signal(element === undefined ? undefined : new ElementRef(element));

function connectScope(parts: {
  content: HTMLElement;
  linkCards?: HTMLElement;
  comments?: HTMLElement;
}): ReadingScope {
  const scope = TestBed.inject(ReadingScope);
  scope.connect({
    scroller: signal(scrollerOfViewport()),
    content: query(parts.content),
    linkCards: query(parts.linkCards),
    comments: query(parts.comments),
  });
  scope.observeResizes();
  TestBed.tick();
  scope.refresh();
  return scope;
}

function focusBlocks(): HTMLElement[] {
  const applier = jest.mocked(ReadingFocusApplier);
  const config = applier.mock.calls.at(-1)![0] as ReadingFocusConfig;
  return config.blocks();
}

describe('ReadingScope', () => {
  beforeEach(() => {
    jest.mocked(ReadingFocusApplier).mockClear();
    MockResizeObserver.instances = [];
    (globalThis as unknown as { ResizeObserver: unknown }).ResizeObserver = MockResizeObserver;
    TestBed.configureTestingModule({
      providers: [
        ReadingScope,
        { provide: ReadingFocusService, useValue: { enabled: signal(true) } },
        { provide: LayoutService, useValue: { isWide: signal(false) } },
        { provide: LanguageService, useValue: { lang: signal('en') } },
      ],
    });
  });

  it('dims the body and the comments, never the link cards', () => {
    const content = partEndingAt(400, '<p>Body one</p><p>Body two</p>');
    const linkCards = partEndingAt(600, '<p>Card</p>');
    const comments = partEndingAt(900, '<p>Comment</p>');
    connectScope({ content, linkCards, comments });

    expect(focusBlocks().map((block) => block.textContent)).toEqual([
      'Body one',
      'Body two',
      'Comment',
    ]);
  });

  it('re-measures when the body, the link cards or the comments change size', () => {
    const content = partEndingAt(400);
    const linkCards = partEndingAt(600);
    const comments = partEndingAt(900);
    connectScope({ content, linkCards, comments });

    expect([...MockResizeObserver.instances.at(-1)!.targets]).toEqual([
      content,
      linkCards,
      comments,
    ]);
  });

  it('ends at the comments, below the link cards', () => {
    const scope = connectScope({
      content: partEndingAt(400),
      linkCards: partEndingAt(2400),
      comments: partEndingAt(600),
    });

    expect(scope.hasTail()).toBe(false);
  });

  it('ends at the link cards when the entry has no comments', () => {
    const scope = connectScope({ content: partEndingAt(400), linkCards: partEndingAt(2400) });

    expect(scope.hasTail()).toBe(true);
  });

  it('ends at the body when nothing follows it', () => {
    const scope = connectScope({ content: partEndingAt(2400) });

    expect(scope.hasTail()).toBe(true);
    expect(scope.contentBottom()).toBe(2400);
  });

  it('measures the progress rail to the body alone', () => {
    const scope = connectScope({ content: partEndingAt(400), comments: partEndingAt(2400) });

    expect(scope.hasTail()).toBe(true);
    expect(scope.contentBottom()).toBe(400);
  });
});
