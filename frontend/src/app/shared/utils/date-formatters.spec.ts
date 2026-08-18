import { formatCodeLandDate, formatCodeLandDateTime } from './date-formatters';

describe('CodeLand date formatters', () => {
  it('uses the exact dd.MM.yyyy. product format', () => {
    expect(formatCodeLandDate(new Date(2026, 7, 18, 11, 46))).toBe('18.08.2026.');
  });

  it('adds local time as HH:mm without exposing ISO text', () => {
    expect(formatCodeLandDateTime(new Date(2026, 7, 18, 11, 46))).toBe('18.08.2026. 11:46');

    const isoValue = formatCodeLandDateTime('2026-08-18T11:46:00Z');
    expect(isoValue).toMatch(/^\d{2}\.\d{2}\.2026\. \d{2}:\d{2}$/);
    expect(isoValue).not.toContain('T11:46');
  });

  it('uses the semantic empty value for missing and invalid dates', () => {
    expect(formatCodeLandDate(null)).toBe('—');
    expect(formatCodeLandDateTime('invalid')).toBe('—');
  });
});
