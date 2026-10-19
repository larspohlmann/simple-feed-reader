import { ComponentFixture, TestBed } from '@angular/core/testing';
import { provideTranslocoTesting } from '../../../../testing/transloco-testing';
import { ToastService } from '../../../shared/toast/toast.service';
import { ShareButtonComponent } from './share-button.component';

describe('ShareButtonComponent', () => {
  const toast = { show: jest.fn() };
  let fixture: ComponentFixture<ShareButtonComponent>;

  beforeEach(() => {
    toast.show.mockReset();
    TestBed.configureTestingModule({
      imports: [ShareButtonComponent, provideTranslocoTesting()],
      providers: [{ provide: ToastService, useValue: toast }],
    });
    fixture = TestBed.createComponent(ShareButtonComponent);
    fixture.componentRef.setInput('url', 'https://example.com/a?utm_source=rss&id=1');
    fixture.componentRef.setInput('title', 'First');
    fixture.detectChanges();
  });

  afterEach(() => fixture.destroy());

  function openMenu(): HTMLElement {
    (fixture.nativeElement as HTMLElement).querySelector<HTMLButtonElement>('button')!.click();
    fixture.detectChanges();
    return document.querySelector<HTMLElement>('[role="menu"]')!;
  }

  function item(menu: HTMLElement, label: string): HTMLButtonElement {
    return Array.from(menu.querySelectorAll<HTMLButtonElement>('[role="menuitem"]')).find(
      (button) => button.textContent?.trim().endsWith(label),
    )!;
  }

  it('lists the configured targets with their dividers', () => {
    const menu = openMenu();

    expect(item(menu, 'Copy link')).toBeTruthy();
    expect(item(menu, 'Bluesky')).toBeTruthy();
    expect(menu.querySelectorAll('hr').length).toBeGreaterThan(0);
  });

  it('leaves out the system share where the browser has none', () => {
    expect(item(openMenu(), 'System share')).toBeUndefined();
  });

  it('opens a target with the article, tracking parameters removed', () => {
    const open = jest.spyOn(window, 'open').mockReturnValue(null);

    item(openMenu(), 'Bluesky').click();
    fixture.detectChanges();

    expect(open).toHaveBeenCalledWith(
      'https://bsky.app/intent/compose?text=First%20https%3A%2F%2Fexample.com%2Fa%3Fid%3D1',
      '_blank',
      'noopener,noreferrer',
    );
    expect(document.querySelector('[role="menu"]')).toBeNull();
  });

  it('copies the link and confirms it', async () => {
    const writeText = jest.fn().mockResolvedValue(undefined);
    Object.assign(navigator, { clipboard: { writeText } });

    item(openMenu(), 'Copy link').click();
    await Promise.resolve();

    expect(writeText).toHaveBeenCalledWith('https://example.com/a?id=1');
    expect(toast.show).toHaveBeenCalledWith(expect.objectContaining({ message: 'Link copied' }));
  });
});
