import { bootstrapApplication } from '@angular/platform-browser';
import { appConfig } from './app/app.config';
import { App } from './app/app';

bootstrapApplication(App, appConfig).catch((err) => {
  console.error(err);
  // The initializer never rejects (core/boot-language.ts), so reaching this
  // means the platform itself failed to come up. A console line helps nobody
  // on a phone; give the user something to act on instead of a blank page.
  document.getElementById('boot-error')?.removeAttribute('hidden');
});
