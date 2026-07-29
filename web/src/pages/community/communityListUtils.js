/** Normalize community list API payloads to a plain array. */
export function normalizeCommunityList(payload) {
  if (Array.isArray(payload)) return payload;
  if (Array.isArray(payload?.data)) return payload.data;
  if (Array.isArray(payload?.items)) return payload.items;
  if (Array.isArray(payload?.data?.data)) return payload.data.data;
  return [];
}

/** Normalize community object payloads (overview/stats). */
export function normalizeCommunityObject(payload) {
  if (!payload || typeof payload !== 'object' || Array.isArray(payload)) {
    return {};
  }
  if (
    payload.data?.data
    && typeof payload.data.data === 'object'
    && !Array.isArray(payload.data.data)
  ) {
    return payload.data.data;
  }
  if (payload.data && typeof payload.data === 'object' && !Array.isArray(payload.data)) {
    return payload.data;
  }
  return payload;
}

export function normalizeCommunityMeta(payload) {
  return payload?.meta
    ?? payload?.data?.meta
    ?? payload?.data?.data?.meta
    ?? {};
}
