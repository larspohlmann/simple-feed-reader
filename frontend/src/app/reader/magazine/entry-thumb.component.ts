// src/app/reader/magazine/entry-thumb.component.ts
import { Component } from '@angular/core';
import { FaviconComponent } from '../../shared/favicon/favicon.component';
import { SourceTagsComponent } from '../source-tags/source-tags.component';
import { EntryImageBlockBase } from './entry-image-block-base';

@Component({
  selector: 'app-entry-thumb',
  imports: [FaviconComponent, SourceTagsComponent],
  templateUrl: './entry-thumb.component.html',
  styleUrl: './entry-thumb.component.scss',
})
export class EntryThumbComponent extends EntryImageBlockBase {}
