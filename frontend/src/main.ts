import { bootstrapApplication } from '@angular/platform-browser';
import { appConfig } from './app/app.config';
import { App } from './app/app';
import { revealBootErrorSurface } from './app/core/errors/boot-error-surface';
import { startBlankScreenProbe } from './app/core/errors/blank-screen-probe';

startBlankScreenProbe();

// The initializer never rejects (core/boot-language.ts), so reaching this
// means the platform itself failed to come up.
bootstrapApplication(App, appConfig).catch(revealBootErrorSurface);
