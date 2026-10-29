import { TestBed } from '@angular/core/testing';
import { Dialog } from '@angular/cdk/dialog';
import { provideTranslocoTesting } from '../../../../testing/transloco-testing';
import { UrlDropZoneComponent } from './url-drop-zone.component';
import { fakeDataTransfer } from '../../../../testing/fake-data-transfer';

const LINK = { 'text/uri-list': 'https://example.com/blog' };

function dragEvent(type: string, payloads: Record<string, string>, target: EventTarget): Event {
  const event = new Event(type, { bubbles: true, cancelable: true });
  Object.defineProperty(event, 'dataTransfer', { value: fakeDataTransfer(payloads) });
  target.dispatchEvent(event);
  return event;
}

describe('UrlDropZoneComponent', () => {
  const openDialogs: unknown[] = [];

  function create() {
    openDialogs.length = 0;
    TestBed.configureTestingModule({
      imports: [UrlDropZoneComponent, provideTranslocoTesting()],
      providers: [{ provide: Dialog, useValue: { openDialogs } }],
    });
    const fixture = TestBed.createComponent(UrlDropZoneComponent);
    const dropped: string[] = [];
    fixture.componentInstance.urlDropped.subscribe((url) => dropped.push(url));
    fixture.detectChanges();
    const host = fixture.nativeElement as HTMLElement;
    const shown = () => {
      fixture.detectChanges();
      return host.classList.contains('shown');
    };
    return { dropped, shown };
  }

  it('shows the veil while a link is dragged over the page and emits it on drop', () => {
    const { dropped, shown } = create();

    dragEvent('dragenter', LINK, document.body);
    expect(shown()).toBe(true);
    expect(dragEvent('dragover', LINK, document.body).defaultPrevented).toBe(true);

    const drop = dragEvent('drop', LINK, document.body);
    expect(drop.defaultPrevented).toBe(true);
    expect(shown()).toBe(false);
    expect(dropped).toEqual(['https://example.com/blog']);
  });

  it('stays up while the drag crosses child elements and hides once it leaves the window', () => {
    const { shown } = create();

    dragEvent('dragenter', LINK, document.body);
    dragEvent('dragenter', LINK, document.body);
    dragEvent('dragleave', LINK, document.body);
    expect(shown()).toBe(true);

    dragEvent('dragleave', LINK, document.body);
    expect(shown()).toBe(false);
  });

  it('ignores drags that carry no link', () => {
    const { dropped, shown } = create();
    const text = { 'text/plain': 'https://example.com' };

    dragEvent('dragenter', text, document.body);
    expect(shown()).toBe(false);
    expect(dragEvent('dragover', text, document.body).defaultPrevented).toBe(false);
    expect(dragEvent('drop', text, document.body).defaultPrevented).toBe(false);
    expect(dropped).toEqual([]);
  });

  it('ignores a link dragged from inside the page', () => {
    const { dropped, shown } = create();

    document.body.dispatchEvent(new Event('dragstart', { bubbles: true }));
    dragEvent('dragenter', LINK, document.body);
    dragEvent('drop', LINK, document.body);
    expect(shown()).toBe(false);
    expect(dropped).toEqual([]);

    document.body.dispatchEvent(new Event('dragend', { bubbles: true }));
    dragEvent('dragenter', LINK, document.body);
    expect(shown()).toBe(true);
  });

  it('ignores drags while a dialog is open', () => {
    const { shown } = create();
    openDialogs.push({});

    dragEvent('dragenter', LINK, document.body);
    expect(shown()).toBe(false);
  });
});
