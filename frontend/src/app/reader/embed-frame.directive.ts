import { Directive } from '@angular/core';
import { EMBED_FRAME_ATTRIBUTES } from './media/media-embeds';

/** Gives a template's iframe the embed security attributes; Angular takes `sandbox` only as a static attribute. */
@Directive({ selector: 'iframe[appEmbedFrame]', host: EMBED_FRAME_ATTRIBUTES })
export class EmbedFrameDirective {}
