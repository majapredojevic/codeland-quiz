type DateValue = string | number | Date | null | undefined;

const dateFormatter = new Intl.DateTimeFormat('bs-BA', {
  day: '2-digit',
  month: '2-digit',
  year: 'numeric',
});
const timeFormatter = new Intl.DateTimeFormat('bs-BA', {
  hour: '2-digit',
  minute: '2-digit',
  hourCycle: 'h23',
});

export function formatCodeLandDate(value: DateValue): string {
  const date = validDate(value);
  if (date === null) return '—';

  const parts = partMap(dateFormatter, date);
  return `${parts.get('day')}.${parts.get('month')}.${parts.get('year')}.`;
}

export function formatCodeLandDateTime(value: DateValue): string {
  const date = validDate(value);
  if (date === null) return '—';

  const dateLabel = formatCodeLandDate(date);
  const parts = partMap(timeFormatter, date);
  return `${dateLabel} ${parts.get('hour')}:${parts.get('minute')}`;
}

function validDate(value: DateValue): Date | null {
  if (value === null || value === undefined || value === '') return null;
  const date = value instanceof Date ? value : new Date(value);
  return Number.isFinite(date.getTime()) ? date : null;
}

function partMap(formatter: Intl.DateTimeFormat, date: Date): Map<string, string> {
  return new Map(
    formatter
      .formatToParts(date)
      .filter(({ type }) => type !== 'literal')
      .map(({ type, value }) => [type, value]),
  );
}
