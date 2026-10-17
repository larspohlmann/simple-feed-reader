import { Component } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { ThreadLineComponent } from './thread-line.component';
import { ThreadLinePostComponent } from './thread-line-post.component';
import { ThreadCountComponent } from './thread-count.component';

@Component({
  imports: [ThreadLineComponent, ThreadLinePostComponent, ThreadCountComponent],
  template: `
    <app-thread-line>
      <app-thread-line-post><p>First</p></app-thread-line-post>
      <app-thread-line-post>
        <p>Second <app-thread-count [position]="2" [total]="3" /></p>
      </app-thread-line-post>
    </app-thread-line>
  `,
})
class ThreadHostComponent {}

function mount(): HTMLElement {
  const fixture = TestBed.createComponent(ThreadHostComponent);
  fixture.detectChanges();
  return fixture.nativeElement as HTMLElement;
}

describe('the thread line', () => {
  it('is a list of its posts', () => {
    const element = mount();

    expect(element.querySelector('app-thread-line')!.getAttribute('role')).toBe('list');
    const posts = element.querySelectorAll('app-thread-line-post');
    expect(posts).toHaveLength(2);
    posts.forEach((post) => expect(post.getAttribute('role')).toBe('listitem'));
  });

  it('projects each post’s content beside its rail', () => {
    const post = mount().querySelector('app-thread-line-post')!;

    expect(post.querySelector('.rail')).not.toBeNull();
    expect(post.querySelector('.content')!.textContent).toContain('First');
  });

  it('counts a post as n/N', () => {
    expect(mount().querySelector('app-thread-count')!.textContent!.trim()).toBe('2/3');
  });
});
