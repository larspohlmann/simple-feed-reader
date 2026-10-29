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
