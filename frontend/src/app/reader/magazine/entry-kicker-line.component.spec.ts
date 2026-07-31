import { TestBed } from '@angular/core/testing';
import { signal } from '@angular/core';
import { provideRouter } from '@angular/router';
import { provideTranslocoTesting } from '../../../testing/transloco-testing';
import { EntryKickerLineComponent } from './entry-kicker-line.component';
import { LanguageService } from '../../core/language.service';
import { EntryDto } from '../models';

// LanguageService now depends on AuthService (for the account write-through) —
// stub it so this test doesn't need the HttpClient chain.
const language = { lang: signal<'en' | 'de'>('en') };

const entry = (over: Partial<EntryDto> = {}): EntryDto => ({
  id: 1,
  title: 'A headline',
  url: null,
  author: null,
  summary: null,
  contentHtml: null,
  imageUrl: null,
  imageWidth: null,
  imageHeight: null,
  publishedAt: new Date(Date.now() - 5 * 60_000).toISOString(),
  createdAt: 'x',
  subscriptionId: 1,
  source: 'NDR.de - Das Beste am Norden - Radio - Fernsehen - Nachrichten',
  faviconUrl: null,
  isRead: false,
  isFavorite: false,
  isKept: false,
  ...over,
});

function mount(e: EntryDto, inputs: Record<string, unknown> = {}) {
  // Several cases mount twice to compare two states, and a configured TestBed
  // cannot be reconfigured once instantiated.
  TestBed.resetTestingModule();
  TestBed.configureTestingModule({
    imports: [EntryKickerLineComponent, provideTranslocoTesting()],
    providers: [provideRouter([]), { provide: LanguageService, useValue: language }],
  });
  const fixture = TestBed.createComponent(EntryKickerLineComponent);
  fixture.componentRef.setInput('entry', e);
  for (const [name, value] of Object.entries(inputs)) {
    fixture.componentRef.setInput(name, value);
  }
  fixture.detectChanges();
  return fixture.nativeElement as HTMLElement;
}

describe('EntryKickerLineComponent', () => {
  it('renders the source in full and the relative time beside it', () => {
    const el = mount(entry());

    expect(el.querySelector('.source')!.textContent).toBe(
      'NDR.de - Das Beste am Norden - Radio - Fernsehen - Nachrichten',
    );
    expect(el.querySelector('.when')!.textContent).toContain('5');
  });

  it('marks the dot unread only while the entry is unread', () => {
    expect(mount(entry()).querySelector('.dot')!.classList).toContain('on');
    expect(mount(entry({ isRead: true })).querySelector('.dot')!.classList).not.toContain('on');
  });

  it('times an entry by its published date, falling back to when we ingested it', () => {
    const el = mount(entry({ publishedAt: null, createdAt: new Date().toISOString() }));

    expect(el.querySelector('.when')!.textContent).not.toBe('');
  });

  it('drops the source, its favicon and the separator when the caller suppresses it', () => {
    const el = mount(entry(), { showSource: false });

    expect(el.querySelector('.source')).toBeNull();
    expect(el.querySelector('app-favicon')).toBeNull();
    expect(el.querySelector('.separator')).toBeNull();
    expect(el.querySelector('.when')!.textContent).not.toBe('');
  });

  it('drops the dot when the caller renders its own outside the line', () => {
    expect(mount(entry(), { showDot: false }).querySelector('.dot')).toBeNull();
  });

  it('sizes the favicon at 12px by default and honours the hero override', () => {
    const withIcon = (inputs: Record<string, unknown>) =>
      mount(entry({ faviconUrl: 'https://i/f.png' }), inputs).querySelector('img.favicon')!;

    expect(withIcon({}).getAttribute('width')).toBe('12');
    expect(withIcon({ faviconSize: 14 }).getAttribute('width')).toBe('14');
  });
});
