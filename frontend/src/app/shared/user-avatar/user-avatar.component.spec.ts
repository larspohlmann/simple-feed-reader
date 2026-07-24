import { TestBed } from '@angular/core/testing';
import { UserAvatarComponent } from './user-avatar.component';

function mount(email: string | null) {
  TestBed.resetTestingModule();
  TestBed.configureTestingModule({ imports: [UserAvatarComponent] });
  const f = TestBed.createComponent(UserAvatarComponent);
  f.componentRef.setInput('email', email);
  f.detectChanges();
  return f;
}

// Poll across a few macrotasks: the avatar URL is set from an async hash, so the
// <img> appears a tick or two after mount.
async function untilImage(f: ReturnType<typeof mount>): Promise<HTMLImageElement> {
  for (let i = 0; i < 20; i++) {
    f.detectChanges();
    const img = (f.nativeElement as HTMLElement).querySelector('img.avatar');
    if (img) return img as HTMLImageElement;
    await new Promise((r) => setTimeout(r));
  }
  throw new Error('avatar image never rendered');
}

describe('UserAvatarComponent', () => {
  it('shows the generic icon when there is no email', () => {
    const el = mount(null).nativeElement as HTMLElement;
    expect(el.querySelector('app-icon')).not.toBeNull();
    expect(el.querySelector('img.avatar')).toBeNull();
  });

  it('shows a Gravatar image once the email hash resolves', async () => {
    const img = await untilImage(mount('a@b.c'));
    expect(img.src).toContain('https://www.gravatar.com/avatar/');
    expect(img.src).toContain('d=404');
  });

  it('falls back to the icon when the Gravatar image errors', async () => {
    const f = mount('a@b.c');
    const img = await untilImage(f);
    img.dispatchEvent(new Event('error'));
    f.detectChanges();
    const el = f.nativeElement as HTMLElement;
    expect(el.querySelector('img.avatar')).toBeNull();
    expect(el.querySelector('app-icon')).not.toBeNull();
  });
});
