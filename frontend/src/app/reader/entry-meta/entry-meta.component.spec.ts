import { TestBed } from '@angular/core/testing';
import { provideRouter } from '@angular/router';
import { provideTranslocoTesting } from '../../../testing/transloco-testing';
import { EntryMetaComponent } from './entry-meta.component';
import { EntryDto, SubscriptionTagDto } from '../models';

const entry = (over: Partial<EntryDto> = {}): EntryDto => ({
  id: 1,
  title: 'A title',
  url: null,
  author: null,
  summary: null,
  contentHtml: null,
  imageUrl: null,
  imageWidth: null,
  imageHeight: null,
  publishedAt: null,
  createdAt: 'x',
  subscriptionId: 7,
  source: 'Src',
  faviconUrl: null,
  isRead: false,
  isFavorite: false,
  isKept: false,
  isViewed: false,
  ...over,
});

const tag = (id: number, name: string): SubscriptionTagDto => ({
  id,
  name,
  color: null,
  icon: null,
  position: 0,
});

function mount(tags: SubscriptionTagDto[]) {
  TestBed.configureTestingModule({
    imports: [EntryMetaComponent, provideTranslocoTesting()],
    providers: [provideRouter([])],
  });
  const f = TestBed.createComponent(EntryMetaComponent);
  f.componentRef.setInput('entry', entry());
  f.componentRef.setInput('tags', tags);
  f.detectChanges();
  return f;
}

describe('EntryMetaComponent', () => {
  it('renders the tag pills beside the actions', () => {
    const el = mount([tag(1, 'Tech')]).nativeElement as HTMLElement;
    expect(el.textContent).toContain('Tech');
    expect(el.querySelectorAll('app-entry-actions button').length).toBe(3);
  });

  it('still renders the actions when the entry has no tags', () => {
    const el = mount([]).nativeElement as HTMLElement;
    expect(el.querySelector('app-source-tags .pills')).toBeNull();
    expect(el.querySelectorAll('app-entry-actions button').length).toBe(3);
  });

  it('forwards each action to its own output', () => {
    const f = mount([]);
    const favorite = jest.fn();
    const keep = jest.fn();
    const read = jest.fn();
    f.componentInstance.favorite.subscribe(favorite);
    f.componentInstance.keep.subscribe(keep);
    f.componentInstance.read.subscribe(read);

    const buttons = f.nativeElement.querySelectorAll('app-entry-actions button');
    (buttons[0] as HTMLElement).click();
    (buttons[1] as HTMLElement).click();
    (buttons[2] as HTMLElement).click();

    expect(favorite).toHaveBeenCalledWith(expect.objectContaining({ id: 1 }));
    expect(keep).toHaveBeenCalledWith(expect.objectContaining({ id: 1 }));
    expect(read).toHaveBeenCalledWith(expect.objectContaining({ id: 1 }));
  });
});
