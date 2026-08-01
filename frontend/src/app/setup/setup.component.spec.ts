import { ComponentFixture, TestBed } from '@angular/core/testing';
import { provideHttpClient } from '@angular/common/http';
import { provideRouter } from '@angular/router';
import { provideTranslocoTesting } from '../../testing/transloco-testing';
import { of, throwError } from 'rxjs';
import { API_BASE_URL } from '../core/api';
import { SetupApi } from './setup-api';
import { SetupService } from './setup.service';
import { SetupComponent } from './setup.component';

describe('SetupComponent', () => {
  let createAdmin: jest.Mock;
  let markComplete: jest.Mock;

  async function mount(): Promise<ComponentFixture<SetupComponent>> {
    createAdmin = jest.fn().mockReturnValue(of({ token: 'jwt-token' }));
    markComplete = jest.fn();
    await TestBed.configureTestingModule({
      imports: [SetupComponent, provideTranslocoTesting()],
      providers: [
        provideHttpClient(),
        provideRouter([]),
        { provide: API_BASE_URL, useValue: 'https://api.test' },
        { provide: SetupApi, useValue: { createAdmin } },
        { provide: SetupService, useValue: { markComplete } },
      ],
    }).compileComponents();
    const fixture = TestBed.createComponent(SetupComponent);
    fixture.detectChanges();
    return fixture;
  }

  it('posts the form and marks setup complete on success', async () => {
    const fixture = await mount();
    const component = fixture.componentInstance;
    component.form.setValue({
      email: 'root@example.com',
      password: 'a-strong-password-123',
      secret: 'the-secret',
    });

    component.submit();

    expect(createAdmin).toHaveBeenCalledWith(
      'root@example.com',
      'a-strong-password-123',
      'the-secret',
    );
    expect(markComplete).toHaveBeenCalled();
  });

  it('surfaces an error and does not complete when the API rejects', async () => {
    const fixture = await mount();
    createAdmin.mockReturnValue(throwError(() => ({ error: { detail: 'nope' } })));
    fixture.componentInstance.form.setValue({
      email: 'root@example.com',
      password: 'a-strong-password-123',
      secret: 'bad',
    });

    fixture.componentInstance.submit();

    expect(fixture.componentInstance.error()).not.toBeNull();
    expect(markComplete).not.toHaveBeenCalled();
  });
});
