import {
  ChangeDetectionStrategy,
  Component,
  DestroyRef,
  Injector,
  NgZone,
  inject,
  input,
  inputBinding,
  signal,
} from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { ArticleIslands } from './article-islands';

let destroyed = 0;
let clickedInZone: boolean | null = null;

@Component({
  selector: 'app-island-probe',
  template: '<button type="button" (click)="clicked()">{{ label() }}</button>',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
class IslandProbeComponent {
  readonly label = input.required<string>();

  constructor() {
    inject(DestroyRef).onDestroy(() => destroyed++);
  }

  protected clicked(): void {
    clickedInZone = NgZone.isInAngularZone();
  }
}

function bodyWithAnchor(): { host: HTMLElement; anchor: HTMLElement } {
  const host = document.createElement('div');
  host.innerHTML = '<div class="anchor"></div><p>Notes</p>';
  return { host, anchor: host.querySelector<HTMLElement>('.anchor')! };
}

describe('ArticleIslands', () => {
  beforeEach(() => {
    destroyed = 0;
    clickedInZone = null;
  });

  function islands(): ArticleIslands {
    return new ArticleIslands(TestBed.inject(Injector));
  }

  it('mounts the component right after its anchor, rendered and marked as an island', () => {
    const { host, anchor } = bodyWithAnchor();

    islands().mountAfter(anchor, IslandProbeComponent, [inputBinding('label', () => 'Chapters')]);

    const mounted = host.children[1];
    expect(mounted.tagName).toBe('APP-ISLAND-PROBE');
    expect(mounted.classList).toContain('reader-island');
    expect(mounted.textContent).toBe('Chapters');
  });

  it('mounts a component right before its anchor', () => {
    const { host, anchor } = bodyWithAnchor();

    islands().mountBefore(anchor, IslandProbeComponent, [inputBinding('label', () => 'Notes')]);

    expect(host.children[0].tagName).toBe('APP-ISLAND-PROBE');
    expect(host.children[1]).toBe(anchor);
  });

  it('keeps two islands mounted after one anchor in mount order', () => {
    const { host, anchor } = bodyWithAnchor();
    const registry = islands();

    registry.mountAfter(anchor, IslandProbeComponent, [inputBinding('label', () => 'first')]);
    registry.mountAfter(anchor, IslandProbeComponent, [inputBinding('label', () => 'second')]);

    expect(host.children[0]).toBe(anchor);
    expect(host.children[1].textContent).toBe('first');
    expect(host.children[2].textContent).toBe('second');
  });

  it('keeps two islands mounted before one anchor in mount order', () => {
    const { host, anchor } = bodyWithAnchor();
    const registry = islands();

    registry.mountBefore(anchor, IslandProbeComponent, [inputBinding('label', () => 'first')]);
    registry.mountBefore(anchor, IslandProbeComponent, [inputBinding('label', () => 'second')]);

    expect(host.children[0].textContent).toBe('first');
    expect(host.children[1].textContent).toBe('second');
    expect(host.children[2]).toBe(anchor);
  });

  it('follows a bound signal', () => {
    const { host, anchor } = bodyWithAnchor();
    const label = signal('Chapters');

    islands().mountAfter(anchor, IslandProbeComponent, [inputBinding('label', label)]);
    label.set('Kapitel');
    TestBed.tick();

    expect(host.querySelector('app-island-probe')!.textContent).toBe('Kapitel');
  });

  it('runs its listeners in the Angular zone even when mounted outside it', () => {
    const { host, anchor } = bodyWithAnchor();
    const registry = islands();

    TestBed.inject(NgZone).runOutsideAngular(() =>
      registry.mountAfter(anchor, IslandProbeComponent, [inputBinding('label', () => 'x')]),
    );
    host.querySelector('button')!.click();

    expect(clickedInZone).toBe(true);
  });

  it('destroys and removes every mounted component on release', () => {
    const { host, anchor } = bodyWithAnchor();
    const registry = islands();
    registry.mountAfter(anchor, IslandProbeComponent, [inputBinding('label', () => 'a')]);
    registry.mountAfter(anchor, IslandProbeComponent, [inputBinding('label', () => 'b')]);

    registry.releaseAll();

    expect(destroyed).toBe(2);
    expect(host.querySelector('app-island-probe')).toBeNull();
  });

  it('releases a component whose body was already swapped out', () => {
    const { host, anchor } = bodyWithAnchor();
    const registry = islands();
    registry.mountAfter(anchor, IslandProbeComponent, [inputBinding('label', () => 'a')]);
    host.innerHTML = '<p>Next article</p>';

    registry.releaseAll();

    expect(destroyed).toBe(1);
    expect(host.innerHTML).toBe('<p>Next article</p>');
  });

  it('releases nothing twice', () => {
    const { anchor } = bodyWithAnchor();
    const registry = islands();
    registry.mountAfter(anchor, IslandProbeComponent, [inputBinding('label', () => 'a')]);

    registry.releaseAll();
    registry.releaseAll();

    expect(destroyed).toBe(1);
  });
});
