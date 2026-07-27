// src/app/reader/magazine/entry-wide.component.ts
import { Component } from '@angular/core';
import { FaviconComponent } from '../../shared/favicon/favicon.component';
import { SourceTagsComponent } from '../source-tags/source-tags.component';
import { EntryImageBlockBase } from './entry-image-block-base';

@Component({
  selector: 'app-entry-wide',
  imports: [FaviconComponent, SourceTagsComponent],
  templateUrl: './entry-wide.component.html',
  styleUrl: './entry-wide.component.scss',
})
export class EntryWideComponent extends EntryImageBlockBase {}
