/** Normalize video list API payloads to a plain array. */
export function normalizeVideoList(payload) {
  if (Array.isArray(payload)) return payload;
  if (Array.isArray(payload?.data)) return payload.data;
  if (Array.isArray(payload?.items)) return payload.items;
  if (Array.isArray(payload?.data?.data)) return payload.data.data;
  return [];
}

export function normalizeVideoTotal(payload, fallbackList = []) {
  if (typeof payload?.total === 'number') return payload.total;
  if (typeof payload?.data?.total === 'number') return payload.data.total;
  return fallbackList.length;
}
