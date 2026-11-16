import { TestBed } from '@angular/core/testing';
import { provideTranslocoTesting } from '../../../../testing/transloco-testing';
import { ListHeaderComponent } from './list-header.component';
import { Selection } from '../../query/query';

function mount(selection: Selection, title: string): HTMLElement {
  TestBed.configureTestingModule({ imports: [ListHeaderComponent, provideTranslocoTesting()] });
  const fixture = TestBed.createComponent(ListHeaderComponent);
  fixture.componentRef.setInput('selection', selection);
  fixture.componentRef.setInput('title', title);
  fixture.detectChanges();
  return fixture.nativeElement as HTMLElement;
}

describe('ListHeaderComponent', () => {
  it('leads with the network logo and offers only refresh', () => {
    const element = mount({ kind: 'notifications', network: 'bluesky' }, 'Notifications');

    expect(element.querySelector('h2 app-network-logo')).not.toBeNull();
    expect(element.querySelector('.refresh')).not.toBeNull();
    expect(element.querySelector('.list-order')).toBeNull();
    expect(element.querySelector('.unread-switch')).toBeNull();
    expect(element.querySelector('.mark-all')).toBeNull();
    expect(element.querySelector('.title-count')).toBeNull();
  });
});
