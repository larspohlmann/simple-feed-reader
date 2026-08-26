import { Component } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { RecommendationStripComponent } from './recommendation-strip.component';
import { EntryDto } from '../models';
import { provideTranslocoTesting } from '../../../testing/transloco-testing';

const entry = (over: Partial<EntryDto> = {}): EntryDto => ({
  id: 1,
  title: 'Hello',
  url: null,
  author: null,
  summary: 's',
  contentHtml: null,
  imageUrl: null,
  imageWidth: null,
  imageHeight: null,
  publishedAt: '2026-07-22T11:00:00Z',
  createdAt: 'x',
  subscriptionId: 5,
  source: 'heise',
  faviconUrl: null,
  isHidden: false,
  isFavorite: false,
  isKept: false,
  isViewed: false,
  ...over,
});

@Component({
  standalone: true,
  imports: [RecommendationStripComponent],
  template: `<app-recommendation-strip [entry]="e"
    ><p class="card">card</p></app-recommendation-strip
  >`,
})
class Host {
  e: EntryDto | null = null;
}

function mount(e: EntryDto | null) {
  const f = TestBed.createComponent(Host);
  f.componentInstance.e = e;
  f.detectChanges();
  return f.nativeElement as HTMLElement;
}

describe('RecommendationStripComponent', () => {
  beforeEach(() => TestBed.configureTestingModule({ imports: [Host, provideTranslocoTesting()] }));

  it('always projects its card content', () => {
    expect(mount(null).querySelector('.card')!.textContent).toContain('card');
  });

  it('renders nothing extra without a reason', () => {
    expect(mount(entry()).querySelector('.reason')).toBeNull();
    expect(mount(entry({ recommendationReason: null })).querySelector('.reason')).toBeNull();
    expect(mount(null).querySelector('.reason')).toBeNull();
  });

  it('renders the reason below the card when present', () => {
    const el = mount(entry({ recommendationReason: 'because you read heise' }));
    expect(el.querySelector('.reason')!.textContent).toContain('because you read heise');
  });

  // Not a wire state any more (#576 sends the pair together) but a real data
  // state: the salvager stores '' for a pick whose reason came back blank, so
  // the strip must still appear and carry the score on its own.
  it('renders the score alone when the reason is blank', () => {
    const el = mount(entry({ recommendationScore: 823 }));
    expect(el.querySelector('.reason')).not.toBeNull();
    expect(el.querySelector('.reason .score')!.textContent).toContain('82');
    expect(el.querySelector('.reason')!.textContent).not.toContain('undefined');
  });

  it('renders the score pill only when the score is a number', () => {
    const withScore = mount(entry({ recommendationReason: 'r', recommendationScore: 823 }));
    expect(withScore.querySelector('.reason .score')!.textContent).toContain('82');
    expect(withScore.querySelector('.reason .score')!.textContent).not.toContain('823');

    const noScore = mount(entry({ recommendationReason: 'r' }));
    expect(noScore.querySelector('.reason .score')).toBeNull();

    const nullScore = mount(entry({ recommendationReason: 'r', recommendationScore: null }));
    expect(nullScore.querySelector('.reason .score')).toBeNull();
  });

  // The model scores on 0-1000 so it can separate candidates; the reader is
  // shown a figure out of 100, rounded rather than truncated (#403).
  it('shows the 0-1000 score out of 100, rounded', () => {
    const rounded = mount(entry({ recommendationReason: 'r', recommendationScore: 856 }));
    expect(rounded.querySelector('.reason .score')!.textContent).toContain('86');

    const zero = mount(entry({ recommendationReason: 'r', recommendationScore: 0 }));
    expect(zero.querySelector('.reason .score')!.textContent).toContain('0');
  });
});
