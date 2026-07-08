import { Injectable } from '@nestjs/common';
// @ts-ignore
import * as moment from 'moment-hijri';

@Injectable()
export class DateService {
  /**
   * Converts a standard Gregorian Date object to a Hijri date string (YYYY/MM/DD).
   */
  toHijri(date: Date | string): string {
    return moment(date).format('iYYYY/iMM/iDD');
  }

  /**
   * Converts a Hijri date string (iYYYY/iMM/iDD) to a standard Gregorian Date object.
   */
  toGregorian(hijriDateStr: string): Date {
    return moment(hijriDateStr, 'iYYYY/iMM/iDD').toDate();
  }

  /**
   * Formats a date showing both Gregorian and Hijri (e.g. "2026-07-08 (Hijri: 1448/01/23)")
   */
  toDualDateString(date: Date | string): string {
    const m = moment(date);
    return `${m.format('YYYY-MM-DD')} (Hijri: ${m.format('iYYYY/iMM/iDD')})`;
  }
}
