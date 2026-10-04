import { ChangeDetectionStrategy, Component, computed, input } from '@angular/core';
import { TranslatePipe } from '@ngx-translate/core';
import { ValidationError } from '@angular/forms/signals';

/** Minimal shape of a Signal Forms field state, so any field type can be passed in. */
interface FieldLike {
  errors(): readonly ValidationError[];
  touched(): boolean;
}

/**
 * Shows the first validation error of a Signal Forms field once it has been touched.
 * Error kinds map to `validation.<kind>`; the error object itself provides the params.
 */
@Component({
  selector: 'app-field-errors',
  imports: [TranslatePipe],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    @if (error(); as err) {
      <p class="mt-1 text-xs text-red-600" role="alert">{{ err.message ?? ('validation.' + err.kind) | translate: err }}</p>
    }
  `,
})
export class FieldErrors {
  readonly field = input.required<FieldLike>();
  protected readonly error = computed(() => {
    const field = this.field();
    return field.touched() ? (field.errors()[0] ?? null) : null;
  });
}
