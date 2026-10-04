import { Directive, ElementRef, afterNextRender, inject } from '@angular/core';

/** Focuses the element once it is rendered (e.g. an inline form that just opened). */
@Directive({ selector: '[appAutofocus]' })
export class Autofocus {
  constructor() {
    const element = inject<ElementRef<HTMLElement>>(ElementRef);
    afterNextRender(() => element.nativeElement.focus());
  }
}
