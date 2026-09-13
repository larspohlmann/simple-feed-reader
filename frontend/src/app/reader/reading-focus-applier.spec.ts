import { ReadingFocusApplier } from './reading-focus-applier';
import { LIST_FOCUS_CURVE } from './reading-focus';

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
