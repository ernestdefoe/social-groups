/** The first error a failed request carries, or the fallback text. */
export function errorText(err, fallback) {
  return err?.response?.errors?.[0]?.detail || err?.response?.error || err?.message || fallback;
}
