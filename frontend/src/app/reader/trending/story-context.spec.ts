import { Component, signal } from '@angular/core';
import { TestBed } from '@angular/core/testing';

import { aStory } from '../../../testing/entry-testing';
import { injectStory, StoryContextDirective } from './story-context';
import { TrendingStoryDto } from './trending.models';

@Component({ selector: 'app-probe', template: '' })
class ProbeComponent {
  readonly story = injectStory();
}

@Component({
  imports: [StoryContextDirective, ProbeComponent],
  template: `
    <ng-container [appStory]="story()"><app-probe id="outer" /></ng-container>
    <ng-container [appStory]="story()">
      <ng-container [appStory]="null"><app-probe id="inner" /></ng-container>
    </ng-container>
  `,
})
class HostComponent {
  readonly story = signal<TrendingStoryDto | null>(null);
}

@Component({ imports: [ProbeComponent], template: '<app-probe />' })
class BareHostComponent {}

describe('story context', () => {
  function render() {
    const fixture = TestBed.createComponent(HostComponent);
    fixture.detectChanges();
    const probes = (selector: string): ProbeComponent =>
      fixture.debugElement.query((node) => node.nativeElement.id === selector).componentInstance;
    return { fixture, outer: probes('outer'), inner: probes('inner') };
  }

  it('gives a child the story its host provides', () => {
    const { fixture, outer } = render();

    fixture.componentInstance.story.set(aStory({ id: 4 }));
    fixture.detectChanges();

    expect(outer.story()?.id).toBe(4);
  });

  it('follows the host when the story object changes', () => {
    const { fixture, outer } = render();
    fixture.componentInstance.story.set(aStory({ id: 4 }));
    fixture.detectChanges();

    fixture.componentInstance.story.set(aStory({ id: 4, viewed: true }));
    fixture.detectChanges();

    expect(outer.story()?.viewed).toBe(true);
  });

  it('gives null without a host', () => {
    const fixture = TestBed.createComponent(BareHostComponent);
    fixture.detectChanges();

    const probe = fixture.debugElement.children[0].componentInstance as ProbeComponent;

    expect(probe.story()).toBeNull();
  });

  it('lets an inner host reset the story', () => {
    const { fixture, outer, inner } = render();

    fixture.componentInstance.story.set(aStory({ id: 4 }));
    fixture.detectChanges();

    expect(inner.story()).toBeNull();
    expect(outer.story()?.id).toBe(4);
  });
});
