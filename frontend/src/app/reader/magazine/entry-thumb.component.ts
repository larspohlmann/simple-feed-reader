// src/app/reader/magazine/entry-thumb.component.ts
import { Component } from '@angular/core';
import { EntryKickerLineComponent } from './entry-kicker-line.component';
import { EntryMetaComponent } from '../entry-meta/entry-meta.component';
import { EntryImageBlockBase } from './entry-image-block-base';

@Component({
  selector: 'app-entry-thumb',
  imports: [EntryKickerLineComponent, EntryMetaComponent],
  templateUrl: './entry-thumb.component.html',
  styleUrl: './entry-thumb.component.scss',
})
export class EntryThumbComponent extends EntryImageBlockBase {}
