const numberFormatter = new Intl.NumberFormat('bs-BA', { maximumFractionDigits: 2 });
const scoreFormatter = new Intl.NumberFormat('bs-BA', { maximumFractionDigits: 0 });
const dateTimeFormatter = new Intl.DateTimeFormat('bs-BA', {
  day: '2-digit',
  month: '2-digit',
  year: 'numeric',
  hour: '2-digit',
  minute: '2-digit',
});
const dateFormatter = new Intl.DateTimeFormat('bs-BA', {
  day: '2-digit',
  month: '2-digit',
  year: 'numeric',
});

export function formatPercentage(value: number | null | undefined): string {
  return value === null || value === undefined || !Number.isFinite(value)
    ? '—'
    : `${numberFormatter.format(value)}%`;
}

export function formatScore(value: number | null | undefined): string {
  return value === null || value === undefined || !Number.isFinite(value)
    ? '—'
    : scoreFormatter.format(value);
}

export function formatNumber(value: number | null | undefined): string {
  return value === null || value === undefined || !Number.isFinite(value)
    ? '—'
    : numberFormatter.format(value);
}

export function formatResponseTime(milliseconds: number | null | undefined): string {
  return milliseconds === null || milliseconds === undefined || !Number.isFinite(milliseconds)
    ? '—'
    : `${numberFormatter.format(milliseconds / 1_000)} s`;
}

export function formatStaffDate(value: string | null | undefined, includeTime = true): string {
  if (!value) return '—';
  const date = new Date(value);
  if (!Number.isFinite(date.getTime())) return '—';
  const formatted = (includeTime ? dateTimeFormatter : dateFormatter).format(date);
  return includeTime ? formatted.replace(/,\s*/, ' · ') : formatted;
}
