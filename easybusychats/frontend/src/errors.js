export class AppError extends Error {
  constructor(message, options = {}) {
    super(String(message || 'Unexpected error'));
    this.name = 'AppError';
    this.code = String(options.code || 'APP_ERROR');
    this.retryable = Boolean(options.retryable);
  }
}

export function toAppError(error, fallbackCode = 'APP_ERROR') {
  if (error instanceof AppError) return error;
  return new AppError(error?.message || 'Unexpected error', {
    code: error?.code || fallbackCode,
    retryable: Boolean(error?.retryable),
  });
}
