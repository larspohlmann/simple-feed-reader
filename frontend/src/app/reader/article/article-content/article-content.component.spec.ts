import { Component, signal } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { provideTranslocoTesting } from '../../../../testing/transloco-testing';
import { NO_EMBED_DETAILS } from '../../../../testing/embed-details-testing';
import { ArticleContentComponent } from './article-content.component';
import { ListAutoplayService } from '../../../core/preferences/list-autoplay.service';
import { ListAutoplayCoordinator } from '../../media/autoplay/list-autoplay-coordinator';
import { UnlockedVideoElements } from '../../media/autoplay/unlocked-video-elements';
import { provideAccountIdentity } from '../../../../testing/account-identity-testing';
import { anEntry } from '../../../../testing/entry-testing';
import { embedTrackOrigin } from '../../audio/embed-track';

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

@Component({
  imports: [ArticleContentComponent],
  providers: [ListAutoplayCoordinator, UnlockedVideoElements],
  template: `<app-article-content [html]="html" [pageUrl]="null" [origin]="origin" />`,
})
class BodyAudioHostComponent {
  readonly html =
    '<audio controls="" preload="none" src="https://x.test/a.mp3" title="Ohne echte Lobby">' +
    '<time datetime="PT2871S">47:51</time></audio>';
  readonly origin = embedTrackOrigin(anEntry({ id: 7 }));
}

describe('ArticleContentComponent', () => {
  beforeEach(() => {
    TestBed.configureTestingModule({
      imports: [provideTranslocoTesting()],
      providers: [
        provideAccountIdentity(),
        { provide: ListAutoplayService, useValue: { enabled: signal(false) } },
        NO_EMBED_DETAILS,
      ],
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

  it("times the body audio's Listen row from markup that crossed Angular's sanitizer", async () => {
    const fixture = TestBed.createComponent(BodyAudioHostComponent);
    fixture.detectChanges();
    await fixture.whenStable();
    await Promise.resolve();

    const row = (fixture.nativeElement as HTMLElement).querySelector('app-listen-row');
    expect(row?.querySelector('.listen-duration')?.textContent).toBe('47:51');
  });
});
