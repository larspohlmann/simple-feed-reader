import {
  ApplicationRef,
  Binding,
  ComponentRef,
  EnvironmentInjector,
  Injector,
  NgZone,
  Type,
  createComponent,
} from '@angular/core';

const READER_ISLAND = 'reader-island';

/** Angular components mounted into the rendered article body, each living until the next render (#1886). */
export class ArticleIslands {
  private mounted: ComponentRef<unknown>[] = [];

  constructor(private readonly injector: Injector) {}

  mountAfter<C>(anchor: Element, component: Type<C>, bindings: Binding[]): ComponentRef<C> {
    return this.mount(component, bindings, (host) => lastTrailingIsland(anchor).after(host));
  }

  mountBefore<C>(anchor: Element, component: Type<C>, bindings: Binding[]): ComponentRef<C> {
    return this.mount(component, bindings, (host) => anchor.before(host));
  }

  private mount<C>(
    component: Type<C>,
    bindings: Binding[],
    insert: (host: HTMLElement) => void,
  ): ComponentRef<C> {
    return this.injector.get(NgZone).run(() => {
      const ref = createComponent(component, {
        environmentInjector: this.injector.get(EnvironmentInjector),
        elementInjector: this.injector,
        bindings,
      });
      const host = hostOf(ref);
      host.classList.add(READER_ISLAND);
      insert(host);
      this.injector.get(ApplicationRef).attachView(ref.hostView);
      ref.changeDetectorRef.detectChanges();
      this.mounted.push(ref);
      return ref;
    });
  }

  releaseAll(): void {
    for (const ref of this.mounted) {
      const host = hostOf(ref);
      ref.destroy();
      host.remove();
    }
    this.mounted = [];
  }
}

function lastTrailingIsland(anchor: Element): Element {
  let last = anchor;
  while (last.nextElementSibling?.classList.contains(READER_ISLAND)) {
    last = last.nextElementSibling;
  }
  return last;
}

export function isInIsland(node: Element): boolean {
  return node.closest(`.${READER_ISLAND}`) !== null;
}

function hostOf(ref: ComponentRef<unknown>): HTMLElement {
  return ref.location.nativeElement as HTMLElement;
}
