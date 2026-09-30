import { TestBed } from '@angular/core/testing';
import { UserAvatarComponent } from './user-avatar.component';

function mount(email: string | null) {
  TestBed.resetTestingModule();
  TestBed.configureTestingModule({ imports: [UserAvatarComponent] });
  const fixture = TestBed.createComponent(UserAvatarComponent);
  fixture.componentRef.setInput('email', email);
  fixture.detectChanges();
  return fixture;
}

// Poll across a few macrotasks: the avatar URL is set from an async hash, so the
// <img> appears a tick or two after mount.
async function untilImage(fixture: ReturnType<typeof mount>): Promise<HTMLImageElement> {
  for (let index = 0; index < 20; index++) {
    fixture.detectChanges();
    const img = (fixture.nativeElement as HTMLElement).querySelector('img.avatar');
    if (img) return img as HTMLImageElement;
    await new Promise((resolve) => setTimeout(resolve));
  }
  throw new Error('avatar image never rendered');
}

describe('UserAvatarComponent', () => {
  it('shows the generic icon when there is no email', () => {
    const element = mount(null).nativeElement as HTMLElement;
    expect(element.querySelector('app-icon')).not.toBeNull();
    expect(element.querySelector('img.avatar')).toBeNull();
  });

  it('shows a Gravatar image once the email hash resolves', async () => {
    const img = await untilImage(mount('a@b.c'));
    expect(img.src).toContain('https://www.gravatar.com/avatar/');
    expect(img.src).toContain('d=404');
  });

  it('falls back to the icon when the Gravatar image errors', async () => {
    const fixture = mount('a@b.c');
    const img = await untilImage(fixture);
    img.dispatchEvent(new Event('error'));
    fixture.detectChanges();
    const element = fixture.nativeElement as HTMLElement;
    expect(element.querySelector('img.avatar')).toBeNull();
    expect(element.querySelector('app-icon')).not.toBeNull();
  });
});
