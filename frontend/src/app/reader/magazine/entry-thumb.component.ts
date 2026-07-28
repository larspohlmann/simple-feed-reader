// src/app/reader/magazine/entry-thumb.component.ts
import { Component } from '@angular/core';
import { EntryKickerLineComponent } from './entry-kicker-line.component';
import { SourceTagsComponent } from '../source-tags/source-tags.component';
import { EntryImageBlockBase } from './entry-image-block-base';

@Component({
  selector: 'app-entry-thumb',
  imports: [EntryKickerLineComponent, SourceTagsComponent],
  templateUrl: './entry-thumb.component.html',
  styleUrl: './entry-thumb.component.scss',
})
export class EntryThumbComponent extends EntryImageBlockBase {}
