import { ReadingFocusApplier } from './reading-focus-applier';
import { LIST_FOCUS_CURVE } from './reading-focus';
import { SENTENCE_CLASS, sectionedUnits } from './reading-sections';

class MockResizeObserver {
  static instances: MockResizeObserver[] = [];
  readonly targets = new Set<Element>();
  constructor(readonly callback: ResizeObserverCallback) {
    MockResizeObserver.instances.push(this);
  }
  observe(t: Element): void {
    this.targets.add(t);
  }
  unobserve(t: Element): void {
    this.targets.delete(t);
  }
  disconnect(): void {
    this.targets.clear();
  }
  fire(): void {
    this.callback([], this as unknown as ResizeObserver);
  }
}

const frames = (): Promise<void> =>
  new Promise((r) => requestAnimationFrame(() => requestAnimationFrame(() => r())));

function scrollerWith(count: number): { scroller: HTMLElement; blocks: () => HTMLElement[] } {
  const scroller = document.createElement('div');
  for (let i = 0; i < count; i++) scroller.appendChild(document.createElement('article'));
  const blocks = (): HTMLElement[] => Array.from(scroller.children) as HTMLElement[];
  return { scroller, blocks };
}

const opacities = (blocks: () => HTMLElement[]): string[] => blocks().map((b) => b.style.opacity);
const blank = (blocks: () => HTMLElement[]): void =>
  blocks().forEach((b) => (b.style.opacity = ''));

let observer: () => MockResizeObserver;

beforeEach(() => {
  MockResizeObserver.instances = [];
  (globalThis as unknown as { ResizeObserver: unknown }).ResizeObserver = MockResizeObserver;
  observer = () => MockResizeObserver.instances[MockResizeObserver.instances.length - 1];
});

it('runs an initial pass on construction when active', async () => {
  const { scroller, blocks } = scrollerWith(3);
  const applier = new ReadingFocusApplier({
    scroller,
    blocks,
    curve: LIST_FOCUS_CURVE,
    isActive: () => true,
  });
  await frames();
  expect(opacities(blocks)).not.toContain('');
  applier.destroy();
});

it('clears every block when inactive', async () => {
  const { scroller, blocks } = scrollerWith(3);
  blocks().forEach((b) => (b.style.opacity = '0.5'));
  const applier = new ReadingFocusApplier({
    scroller,
    blocks,
    curve: LIST_FOCUS_CURVE,
    isActive: () => false,
  });
  await frames();
  expect(opacities(blocks)).toEqual(['', '', '']);
  applier.destroy();
});

it('clear() blanks synchronously', () => {
  const { scroller, blocks } = scrollerWith(2);
  const applier = new ReadingFocusApplier({
    scroller,
    blocks,
    curve: LIST_FOCUS_CURVE,
    isActive: () => true,
  });
  blocks().forEach((b) => (b.style.opacity = '1'));
  applier.clear();
  expect(opacities(blocks)).toEqual(['', '']);
  applier.destroy();
});

it('recomputes on a scroll event', async () => {
  const { scroller, blocks } = scrollerWith(3);
  const applier = new ReadingFocusApplier({
    scroller,
    blocks,
    curve: LIST_FOCUS_CURVE,
    isActive: () => true,
  });
  await frames();
  blank(blocks);
  scroller.dispatchEvent(new Event('scroll'));
  await frames();
  expect(opacities(blocks)).not.toContain('');
  applier.destroy();
});

it('recomputes when the ResizeObserver fires (orientation/reflow)', async () => {
  const { scroller, blocks } = scrollerWith(3);
  const applier = new ReadingFocusApplier({
    scroller,
    blocks,
    curve: LIST_FOCUS_CURVE,
    isActive: () => true,
  });
  await frames();
  blank(blocks);
  observer().fire();
  await frames();
  expect(opacities(blocks)).not.toContain('');
  applier.destroy();
});

it('observes the scroller and every block', () => {
  const { scroller, blocks } = scrollerWith(3);
  const applier = new ReadingFocusApplier({
    scroller,
    blocks,
    curve: LIST_FOCUS_CURVE,
    isActive: () => true,
  });
  expect(observer().targets.has(scroller)).toBe(true);
  for (const b of blocks()) expect(observer().targets.has(b)).toBe(true);
  applier.destroy();
});

it('refresh() re-observes newly added blocks', async () => {
  const { scroller, blocks } = scrollerWith(2);
  const applier = new ReadingFocusApplier({
    scroller,
    blocks,
    curve: LIST_FOCUS_CURVE,
    isActive: () => true,
  });
  const added = document.createElement('article');
  scroller.appendChild(added);
  applier.refresh();
  await frames();
  expect(observer().targets.has(added)).toBe(true);
  expect(added.style.opacity).not.toBe('');
  applier.destroy();
});

it('picks up a row revealed between two refreshes on the next scroll', async () => {
  const { scroller, blocks } = scrollerWith(2);
  const applier = new ReadingFocusApplier({
    scroller,
    blocks,
    curve: LIST_FOCUS_CURVE,
    isActive: () => true,
  });
  await frames();
  const revealed = document.createElement('article');
  scroller.appendChild(revealed);
  scroller.dispatchEvent(new Event('scroll'));
  await frames();
  expect(revealed.style.opacity).not.toBe('');
  applier.destroy();
});

it('stops recomputing after destroy()', async () => {
  const { scroller, blocks } = scrollerWith(3);
  const applier = new ReadingFocusApplier({
    scroller,
    blocks,
    curve: LIST_FOCUS_CURVE,
    isActive: () => true,
  });
  await frames();
  applier.destroy();
  blank(blocks);
  scroller.dispatchEvent(new Event('scroll'));
  observer().fire();
  await frames();
  expect(opacities(blocks)).toEqual(['', '', '']);
});

describe('splitting a tall block into sections', () => {
  const FOUR_SENTENCES =
    'Sentence one is long enough here. Sentence two is long enough here. ' +
    'Sentence three is long enough here. Sentence four is long enough here.';

  function stubRect(el: HTMLElement, top: number, height: number): void {
    el.getBoundingClientRect = () =>
      ({ top, height, bottom: top + height, left: 0, right: 0, width: 0, x: 0, y: 0 }) as DOMRect;
  }

  function tallArticle(): {
    scroller: HTMLElement;
    block: HTMLElement;
    blocks: () => HTMLElement[];
  } {
    const scroller = document.createElement('div');
    Object.defineProperty(scroller, 'clientHeight', { value: 600, configurable: true });
    scroller.getBoundingClientRect = () => ({ top: 0 }) as DOMRect;
    const block = document.createElement('p');
    block.textContent = FOUR_SENTENCES;
    stubRect(block, 0, 400); // taller than half the 600px scroller
    scroller.appendChild(block);
    return { scroller, block, blocks: () => Array.from(scroller.children) as HTMLElement[] };
  }

  function articleApplier(scroller: HTMLElement, blocks: () => HTMLElement[]): ReadingFocusApplier {
    return new ReadingFocusApplier({
      scroller,
      blocks,
      curve: LIST_FOCUS_CURVE,
      isActive: () => true,
      units: sectionedUnits(() => 'en'),
    });
  }

  it('fades the sentence spans, not the tall paragraph itself', async () => {
    const { scroller, block, blocks } = tallArticle();
    const applier = articleApplier(scroller, blocks);
    await frames();

    const spans = Array.from(block.querySelectorAll<HTMLElement>(`span.${SENTENCE_CLASS}`));
    expect(spans).toHaveLength(4);
    spans.forEach((span, i) => stubRect(span, i * 100, 100)); // stacked down the scroller
    observer().fire();
    await frames();

    expect(block.style.opacity).toBe(''); // the block is not a focus target any more
    expect(spans.map((s) => s.style.opacity).every((o) => o !== '')).toBe(true);
    expect(new Set(spans.map((s) => s.style.opacity)).size).toBeGreaterThan(1);
    applier.destroy();
  });

  it('reverts to one unit and clears the spans when the block is no longer tall', async () => {
    const { scroller, block, blocks } = tallArticle();
    const applier = articleApplier(scroller, blocks);
    await frames();
    const spans = Array.from(block.querySelectorAll<HTMLElement>(`span.${SENTENCE_CLASS}`));
    spans.forEach((span, i) => stubRect(span, i * 100, 100));
    observer().fire();
    await frames();

    stubRect(block, 0, 100); // shorter than half the scroller now
    observer().fire();
    await frames();

    expect(block.style.opacity).not.toBe(''); // the block carries the opacity again
    expect(spans.map((s) => s.style.opacity)).toEqual(['', '', '', '']); // spans cleared
    applier.destroy();
  });

  it('clear() blanks the sentence spans too', async () => {
    const { scroller, block, blocks } = tallArticle();
    const applier = articleApplier(scroller, blocks);
    await frames();
    const spans = Array.from(block.querySelectorAll<HTMLElement>(`span.${SENTENCE_CLASS}`));
    spans.forEach((span, i) => stubRect(span, i * 100, 100));
    observer().fire();
    await frames();

    applier.clear();
    expect(spans.map((s) => s.style.opacity)).toEqual(['', '', '', '']);
    expect(block.style.opacity).toBe('');
    applier.destroy();
  });
});

// #501: a pass that reads one block's rect, writes its opacity, then reads the
// next forces a style recalc per block. Reads must all land before any write.
describe('geometry reads and style writes', () => {
  function spyOnOpacityWrites(block: HTMLElement, log: string[]): void {
    let value = '';
    Object.defineProperty(block.style, 'opacity', {
      configurable: true,
      get: () => value,
      set: (next: string) => {
        log.push('write');
        value = next;
      },
    });
  }

  function traced(count: number): {
    scroller: HTMLElement;
    blocks: () => HTMLElement[];
    log: string[];
  } {
    const { scroller, blocks } = scrollerWith(count);
    const log: string[] = [];
    for (const block of blocks()) {
      spyOnOpacityWrites(block, log);
      block.getBoundingClientRect = () => {
        log.push('read');
        return { top: 0, height: 10 } as DOMRect;
      };
    }
    return { scroller, blocks, log };
  }

  it('reads every block before it writes any', async () => {
    const { scroller, blocks, log } = traced(3);
    const applier = new ReadingFocusApplier({
      scroller,
      blocks,
      curve: LIST_FOCUS_CURVE,
      isActive: () => true,
    });
    await frames();
    expect(log).toEqual(['read', 'read', 'read', 'write', 'write', 'write']);
    applier.destroy();
  });

  it('leaves a block untouched when its opacity has not changed', async () => {
    const { scroller, blocks, log } = traced(2);
    const applier = new ReadingFocusApplier({
      scroller,
      blocks,
      curve: LIST_FOCUS_CURVE,
      isActive: () => true,
    });
    await frames();
    log.length = 0;
    scroller.dispatchEvent(new Event('scroll'));
    await frames();
    expect(log.filter((entry) => entry === 'write')).toEqual([]);
    applier.destroy();
  });
});
