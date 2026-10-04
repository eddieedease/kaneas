import { Pipe, PipeTransform } from '@angular/core';

/**
 * Parses MySQL DATETIME strings from the API ("2026-10-04 16:26:23", stored in UTC) into a Date.
 * Use before the date pipe: {{ value | utcDate | date: 'short' }}
 */
@Pipe({ name: 'utcDate' })
export class UtcDatePipe implements PipeTransform {
  transform(value: string | null | undefined): Date | null {
    if (!value) {
      return null;
    }
    const date = new Date(value.replace(' ', 'T') + 'Z');
    return Number.isNaN(date.getTime()) ? null : date;
  }
}
