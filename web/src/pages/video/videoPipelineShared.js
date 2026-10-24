/** Vocabulary shared by the pipeline list and the item detail page. */

export const STATUS_BADGE = {
  pending_vetting: 'badge--warning',
  rejected: 'badge--muted',
  in_progress: 'badge--info',
  blocked: 'badge--warning',
  failed: 'badge--error',
  published: 'badge--success',
  cancelled: 'badge--muted',
};

/** In pipeline order — the detail page lays the steps out in this order. */
export const STEP_LABEL = {
  vet: 'Vetting',
  tts: 'Voice-over',
  visuals: 'Visuals',
  render_submit: 'Render submit',
  render_poll: 'Rendering',
  youtube_upload: 'YouTube upload',
  youtube_extras: 'Thumbnail & captions',
  social: 'Social offer',
  done: 'Done',
};

export function statusLabel(status) {
  return String(status ?? '').replace(/_/g, ' ');
}

export const pipelineItemHref = (uuid) => `/video/pipeline/${uuid}`;

/** Only https links are rendered — the API passes provider URLs through. */
export const safeUrl = (url) => (typeof url === 'string' && /^https:\/\//.test(url) ? url : null);
