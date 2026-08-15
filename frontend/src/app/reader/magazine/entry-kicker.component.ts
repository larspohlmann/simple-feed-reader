// src/app/reader/magazine/entry-kicker.component.ts
import { Component } from '@angular/core';
import { EntryKickerLineComponent } from './entry-kicker-line.component';
import { EntryMetaComponent } from '../entry-meta/entry-meta.component';
import { EntryBlockBase } from './entry-block-base';

@Component({
  selector: 'app-entry-kicker',
  imports: [EntryKickerLineComponent, EntryMetaComponent],
  templateUrl: './entry-kicker.component.html',
  styleUrl: './entry-kicker.component.scss',
})
export class EntryKickerComponent extends EntryBlockBase {}
