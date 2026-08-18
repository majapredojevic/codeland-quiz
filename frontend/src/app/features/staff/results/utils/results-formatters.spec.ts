import {
  formatPercentage,
  formatResponseTime,
  formatScore,
  formatStaffDate,
} from './results-formatters';

describe('Results formatters', () => {
  it('uses Bosnian locale-aware score and percentage formatting', () => {
    expect(formatScore(7450)).toBe('7.450');
    expect(formatPercentage(74)).toBe('74%');
    expect(formatPercentage(74.5)).toBe('74,5%');
    expect(formatPercentage(74.52)).toBe('74,52%');
  });

  it('centralizes response-time formatting and safe missing values', () => {
    expect(formatResponseTime(850)).toBe('0,85 s');
    expect(formatResponseTime(1250)).toBe('1,25 s');
    expect(formatResponseTime(null)).toBe('—');
    expect(formatPercentage(Number.NaN)).toBe('—');
  });

  it('never exposes a raw ISO date or an invalid date', () => {
    const value = formatStaffDate('2026-08-12T19:42:00+02:00');
    expect(value).not.toContain('T19:42');
    expect(value).toMatch(/^\d{2}\.\d{2}\.2026\. \d{2}:\d{2}$/);
    expect(formatStaffDate('2026-08-12T19:42:00+02:00', false)).toMatch(/^\d{2}\.\d{2}\.2026\.$/);
    expect(formatStaffDate('invalid')).toBe('—');
  });
});
