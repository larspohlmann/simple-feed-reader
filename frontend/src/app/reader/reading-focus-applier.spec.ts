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
