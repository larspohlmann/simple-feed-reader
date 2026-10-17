import { TestBed } from '@angular/core/testing';
import { provideRouter } from '@angular/router';
import { provideTranslocoTesting } from '../../../../testing/transloco-testing';
import { anEntry } from '../../../../testing/entry-testing';
import { EntryDto } from '../../models';
import { SubscriptionRows } from '../../state/subscription-rows';
import { makeSubscription } from '../../testing/subscription.factory';
import { EntrySourceComponent } from './entry-source.component';

const BOB = { name: 'Bob', url: 'https://bsky.app/profile/bob', imageUrl: null };

function sourceLine(entry: EntryDto, sourceFormat: 'xml' | 'saved'): string {
  TestBed.configureTestingModule({
    imports: [EntrySourceComponent, provideTranslocoTesting()],
    providers: [provideRouter([])],
  });
  TestBed.inject(SubscriptionRows).stored.set([
    makeSubscription({
      id: entry.subscriptionId,
      title: sourceFormat === 'saved' ? 'Saved from Bluesky' : 'A feed',
      sourceFormat,
      social:
        sourceFormat === 'saved' ? { network: 'bluesky', accountId: null, kind: 'saved' } : null,
    }),
  ]);
  const fixture = TestBed.createComponent(EntrySourceComponent);
  fixture.componentRef.setInput('entry', entry);
  fixture.componentRef.setInput('layout', 'row');
  fixture.detectChanges();
  const line = (fixture.nativeElement as HTMLElement).cloneNode(true) as HTMLElement;
  line.querySelector('app-favicon')?.remove();
  return (line.textContent ?? '').replace(/\s+/g, ' ').trim();
}

describe('EntrySourceComponent', () => {
  it('names the poster, then the saved feed a saved post now lives in', () => {
    expect(sourceLine(anEntry({ poster: BOB }), 'saved')).toBe('Bob · Saved from Bluesky');
  });

  it('names the poster alone in an ordinary feed', () => {
    expect(sourceLine(anEntry({ poster: BOB }), 'xml')).toBe('Bob');
  });

  it('names the feed alone when nobody else posted', () => {
    expect(sourceLine(anEntry(), 'xml')).toBe('A feed');
  });
});
