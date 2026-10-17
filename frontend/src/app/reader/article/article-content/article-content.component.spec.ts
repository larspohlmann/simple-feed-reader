import { Component, signal } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { provideTranslocoTesting } from '../../../../testing/transloco-testing';
import { ArticleContentComponent } from './article-content.component';
import { ListAutoplayService } from '../../../core/preferences/list-autoplay.service';
import { ListAutoplayCoordinator } from '../../media/autoplay/list-autoplay-coordinator';
import { UnlockedVideoElements } from '../../media/autoplay/unlocked-video-elements';

@Component({
  imports: [ArticleContentComponent],
  providers: [ListAutoplayCoordinator, UnlockedVideoElements],
  template: `<app-article-content
    [html]="html()"
    [pageUrl]="null"
    (decorated)="decorated.push($event)"
  />`,
})
class HostComponent {
  readonly html = signal('<p>One <a href="https://example.com">link</a></p>');
  readonly decorated: HTMLElement[] = [];
}

describe('ArticleContentComponent', () => {
  beforeEach(() => {
    TestBed.configureTestingModule({
      imports: [provideTranslocoTesting()],
      providers: [{ provide: ListAutoplayService, useValue: { enabled: signal(false) } }],
    });
  });

  it('renders the html, decorates it, then reports the decorated element', async () => {
    const fixture = TestBed.createComponent(HostComponent);
    fixture.detectChanges();
    await fixture.whenStable();
    await Promise.resolve();

    const content = fixture.nativeElement.querySelector('.content') as HTMLElement;
    expect(content.querySelector('p')?.textContent).toContain('One');
    expect(content.querySelector('a')?.target).toBe('_blank');
    expect(fixture.componentInstance.decorated).toEqual([content]);
  });
});
