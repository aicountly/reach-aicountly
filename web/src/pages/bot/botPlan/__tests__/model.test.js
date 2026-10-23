import { describe, it, expect } from 'vitest';
import { formatIstDate, formatIstDateTime, metricRecordsHref, rangeDates, recordsHrefIfAny } from '../model';

describe('Bot plan dates', () => {
  it('reads PostgreSQL timestamps with an hour-only offset', () => {
    expect(formatIstDateTime('2026-10-22 10:00:00+00')).toContain('22 Oct 2026');
    expect(formatIstDateTime('2026-10-22 10:00:00+00')).toContain('15:30');
    expect(formatIstDate('2026-10-22 20:00:00+00')).toBe('23 Oct 2026');
  });

  it('keeps an IST calendar date on its own day', () => {
    expect(formatIstDate('2026-10-18')).toBe('18 Oct 2026');
  });
});

describe('Bot plan metric → records links', () => {
  it('maps every PlanMetrics key to the list of records it counts', () => {
    expect(metricRecordsHref('content.blog.published')).toBe('/content?content_type=blog&workflow_status=published');
    expect(metricRecordsHref('content.knowledge_base.published')).toBe('/content?content_type=knowledge_base&workflow_status=published');
    expect(metricRecordsHref('content.community.published')).toBe('/community/deployments');
    expect(metricRecordsHref('content.release_note.published')).toBe('/publishing/release-notes');
    expect(metricRecordsHref('content.workflow_guide.published')).toBe('/publishing/workflow-guides');
    expect(metricRecordsHref('video.vlog.published')).toBe('/video?status=published&format=vlog');
    expect(metricRecordsHref('video.short.published')).toBe('/video?status=published&format=short');
    expect(metricRecordsHref('social.linkedin.posted')).toBe('/social/calendar?status=posted&channel=linkedin');
    expect(metricRecordsHref('social.twitter.posted')).toBe('/social/calendar?status=posted&channel=twitter');
    expect(metricRecordsHref('email.accepted')).toBe('/distribution/email');
    expect(metricRecordsHref('covers.generated')).toBe('/quality/gallery');
    expect(metricRecordsHref('seo.checks_run')).toBe('/seo-centre/tracker');
    expect(metricRecordsHref('search.clicks')).toBe('/seo-centre/search');
    expect(metricRecordsHref('search.impressions')).toBe('/seo-centre/search');
    expect(metricRecordsHref('leads.captured')).toBe('/leads');
    expect(metricRecordsHref('leads.pushed')).toBe('/leads?engage_push_status=pushed');
    expect(metricRecordsHref('engage.conversions')).toBe('/leads/outcomes?stage=converted');
    expect(metricRecordsHref('engage.revenue_inr')).toBe('/leads/outcomes?stage=payment_confirmed');
    expect(metricRecordsHref('something.new')).toBeNull();
  });

  it('never links a zero', () => {
    expect(recordsHrefIfAny('leads.pushed', 0)).toBeNull();
    expect(recordsHrefIfAny('leads.pushed', null)).toBeNull();
    expect(recordsHrefIfAny('leads.pushed', 3)).toBe('/leads?engage_push_status=pushed');
  });

  it('lists the days of a range inclusively', () => {
    expect(rangeDates('2026-10-30', '2026-11-02')).toEqual(['2026-10-30', '2026-10-31', '2026-11-01', '2026-11-02']);
    expect(rangeDates('2026-10-18', '2026-10-18')).toEqual(['2026-10-18']);
    expect(rangeDates(null, '2026-10-18')).toEqual([]);
  });
});
