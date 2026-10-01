export class ApiError extends Error {
  constructor(message: string, public errors: Record<string, string[]> = {}, public status = 0) {
    super(message);
    this.name = 'ApiError';
  }
}

/** Same-origin session requests; read the current cookie after session regeneration. */
export async function request(url: string, method = 'POST', data: unknown = {}): Promise<any> {
  if (!navigator.onLine) throw new ApiError('You’re offline. Reconnect before loading records or saving changes. Nothing is queued for later.');
  const token = document.cookie.split('; ').find(cookie => cookie.startsWith('XSRF-TOKEN='))?.slice(11);
  const headers: Record<string, string> = { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' };
  if (token) headers['X-XSRF-TOKEN'] = decodeURIComponent(token);
  else {
    const meta = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content;
    if (meta) headers['X-CSRF-TOKEN'] = meta;
  }
  const readOnly = method === 'GET' || method === 'HEAD';
  if (!readOnly) headers['Content-Type'] = 'application/json';
  let response: Response;
  try { response = await fetch(url, { method, headers, credentials: 'same-origin', body: readOnly ? undefined : JSON.stringify(data) }); }
  catch { throw new ApiError('Connection lost. Your changes were not saved. Please try again.'); }
  const payload = await response.json().catch(() => ({}));
  if (!response.ok) {
    const message = response.status === 419 ? 'Your session has expired. Refresh this page and try again.'
      : response.status === 401 ? 'Please sign in again to continue.'
      : response.status === 429 ? 'Please wait a moment before trying again.'
      : payload.message || 'This action could not be completed. Please try again.';
    throw new ApiError(message, payload.errors || {}, response.status);
  }
  return payload;
}
