import { ComponentFixture, TestBed } from '@angular/core/testing';
import { provideTranslocoTesting } from '../../../testing/transloco-testing';
import { FeedIntroComponent } from './feed-intro.component';

describe('FeedIntroComponent', () => {
  let fixture: ComponentFixture<FeedIntroComponent>;

  beforeEach(async () => {
    await TestBed.configureTestingModule({
      imports: [FeedIntroComponent, provideTranslocoTesting()],
    }).compileComponents();
    fixture = TestBed.createComponent(FeedIntroComponent);
  });

  function render(values: {
    description?: string;
    imageUrl?: string;
    siteUrl?: string;
  }): HTMLElement {
    fixture.componentRef.setInput('description', values.description ?? null);
    fixture.componentRef.setInput('imageUrl', values.imageUrl ?? null);
    fixture.componentRef.setInput('siteUrl', values.siteUrl ?? null);
    fixture.detectChanges();
    return fixture.nativeElement as HTMLElement;
  }

  it('renders the description', () => {
    expect(render({ description: 'A feed about things.' }).textContent).toContain(
      'A feed about things.',
    );
  });

  it('renders the image', () => {
    const img = render({ imageUrl: 'https://example.com/logo.png' }).querySelector('img');
    expect(img?.getAttribute('src')).toBe('https://example.com/logo.png');
  });

  it('renders the homepage link in a new tab, without leaking the referrer', () => {
    const link = render({ siteUrl: 'https://example.com/' }).querySelector('a');
    expect(link?.getAttribute('href')).toBe('https://example.com/');
    expect(link?.getAttribute('target')).toBe('_blank');
    expect(link?.getAttribute('rel')).toBe('noopener noreferrer');
  });

  it('omits each part whose value is null', () => {
    const host = render({ description: 'Only text.' });
    expect(host.querySelector('img')).toBeNull();
    expect(host.querySelector('a')).toBeNull();
  });

  it('reports no content when every value is null', () => {
    render({});
    expect(fixture.componentInstance.hasContent()).toBe(false);
  });

  it('hides a broken image instead of leaving a broken-image box', () => {
    const host = render({ imageUrl: 'https://example.com/dead.png' });
    host.querySelector('img')?.dispatchEvent(new Event('error'));
    fixture.detectChanges();
    expect(host.querySelector('img')).toBeNull();
  });
});
