import { TestBed } from '@angular/core/testing';
import { provideRouter } from '@angular/router';
import { SourceTagsComponent } from './source-tags.component';
import { SubscriptionTagDto } from '../models';

const tag = (
  id: number,
  name: string,
  over: Partial<SubscriptionTagDto> = {},
): SubscriptionTagDto => ({ id, name, color: null, icon: null, position: 0, ...over });

describe('SourceTagsComponent', () => {
  function mount(tags: SubscriptionTagDto[]) {
    TestBed.configureTestingModule({
      imports: [SourceTagsComponent],
      providers: [provideRouter([])],
    });
    const f = TestBed.createComponent(SourceTagsComponent);
    f.componentRef.setInput('tags', tags);
    f.detectChanges();
    return f;
  }

  it('renders one clickable pill per tag with its name', () => {
    const el = mount([tag(1, 'News'), tag(2, 'Tech')]).nativeElement as HTMLElement;
    const pills = el.querySelectorAll('a.pill');
    expect(pills.length).toBe(2);
    expect(pills[0].textContent).toContain('News');
    expect(pills[1].textContent).toContain('Tech');
  });

  it('links each pill to its tag filter', () => {
    const el = mount([tag(5, 'News')]).nativeElement as HTMLElement;
    const href = el.querySelector('a.pill')!.getAttribute('href');
    expect(href).toContain('tag=5');
  });

  it('renders nothing when there are no tags', () => {
    const el = mount([]).nativeElement as HTMLElement;
    expect(el.querySelector('a.pill')).toBeNull();
  });

  it('stops a pill click from bubbling so the parent entry does not open', () => {
    const f = mount([tag(1, 'News')]);
    const parentClick = jest.fn();
    f.nativeElement.addEventListener('click', parentClick);
    const pill = f.nativeElement.querySelector('a.pill') as HTMLElement;
    pill.dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true }));
    expect(parentClick).not.toHaveBeenCalled();
  });
});
