import {
  formatCodeLandDate,
  formatCodeLandDateTime,
} from '../../../../shared/utils/date-formatters';

const numberFormatter = new Intl.NumberFormat('bs-BA', { maximumFractionDigits: 2 });
const scoreFormatter = new Intl.NumberFormat('bs-BA', { maximumFractionDigits: 0 });

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
  return includeTime ? formatCodeLandDateTime(value) : formatCodeLandDate(value);
}
