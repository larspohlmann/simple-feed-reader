// src/app/reader/magazine/entry-kicker.component.ts
import { Component } from '@angular/core';
import { FaviconComponent } from '../../shared/favicon/favicon.component';
import { SourceTagsComponent } from '../source-tags/source-tags.component';
import { EntryBlockBase } from './entry-block-base';

@Component({
  selector: 'app-entry-kicker',
  imports: [FaviconComponent, SourceTagsComponent],
  templateUrl: './entry-kicker.component.html',
  styleUrl: './entry-kicker.component.scss',
})
export class EntryKickerComponent extends EntryBlockBase {}
