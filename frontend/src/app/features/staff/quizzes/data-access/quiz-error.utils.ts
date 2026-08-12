import { HttpErrorResponse } from '@angular/common/http';

interface ErrorBody {
  error?: unknown;
}

export function backendErrorMessage(error: unknown): string | null {
  if (!(error instanceof HttpErrorResponse)) return null;
  const body = error.error as ErrorBody | null;
  return typeof body?.error === 'string' ? body.error : null;
}

export function isMissingTopicError(error: unknown): boolean {
  return (
    error instanceof HttpErrorResponse &&
    error.status === 404 &&
    backendErrorMessage(error) === 'Topic was not found.'
  );
}

export const QUESTION_CONTENT_LOCK_MESSAGE =
  'Pitanja se ne mogu mijenjati dok postoji otvorena sesija.';

export function isQuestionContentLockError(error: unknown): boolean {
  return (
    error instanceof HttpErrorResponse &&
    error.status === 409 &&
    backendErrorMessage(error) === 'Quiz content cannot be changed while it has an open session.'
  );
}

export function questionMutationErrorMessage(error: unknown, fallback: string): string {
  if (isQuestionContentLockError(error)) return QUESTION_CONTENT_LOCK_MESSAGE;

  const message = backendErrorMessage(error) ?? '';
  if (message.includes('option texts must be unique')) {
    return 'Odgovori moraju imati različit tekst.';
  }
  if (message.includes('Question text')) {
    return message.includes('1000')
      ? 'Tekst pitanja može imati najviše 1000 znakova.'
      : 'Unesite tekst pitanja.';
  }
  if (message.includes('option text')) {
    return message.includes('255')
      ? 'Tekst odgovora može imati najviše 255 znakova.'
      : 'Unesite tekst svih odgovora.';
  }
  if (message.includes('time limit')) {
    return 'Vrijeme mora biti između 30 i 300 sekundi.';
  }
  if (message.includes('maximum points')) {
    return 'Broj bodova mora biti između 1 i 10000.';
  }
  if (message.includes('TRUE_FALSE')) {
    return 'Pitanje Tačno/Netačno mora imati jedan tačan odgovor.';
  }
  if (message.includes('SINGLE_CHOICE')) {
    return 'Pitanje sa jednim tačnim odgovorom mora imati 2 ili 4 odgovora i tačno jedan označen kao tačan.';
  }
  if (message.includes('MULTIPLE_CHOICE')) {
    return 'Pitanje sa više tačnih odgovora mora imati 4 odgovora, od kojih su 2 ili 3 tačna.';
  }

  return fallback;
}

export function questionImageUploadErrorMessage(error: unknown): string {
  if (isQuestionContentLockError(error)) return QUESTION_CONTENT_LOCK_MESSAGE;
  if (error instanceof HttpErrorResponse && error.status === 413) {
    return 'Slika može imati najviše 5 MB.';
  }

  const message = (backendErrorMessage(error) ?? '').toLocaleLowerCase('en');
  if (
    message.includes('maximum upload') ||
    message.includes('too large') ||
    message.includes('exceeds')
  ) {
    return 'Slika može imati najviše 5 MB.';
  }
  if (
    message.includes('mime') ||
    message.includes('extension') ||
    message.includes('unsupported') ||
    message.includes('supported image') ||
    message.includes('valid image') ||
    message.includes('image content')
  ) {
    return 'Odabrani fajl nije podržana slika.';
  }

  return 'Slika nije uspješno učitana. Pokušajte ponovo.';
}
