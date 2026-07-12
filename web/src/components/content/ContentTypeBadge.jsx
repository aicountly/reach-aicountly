const TYPE_LABELS = {
  blog:                  'Blog',
  knowledge_base:        'Knowledge Base',
  community_question:    'Community Q',
  community_answer:      'Community A',
  video_topic:           'Video Topic',
  video_script:          'Video Script',
  social_post:           'Social Post',
  email:                 'Email',
  whatsapp:              'WhatsApp',
  sms:                   'SMS',
  landing_page:          'Landing Page',
  product_announcement:  'Product Announcement',
  release_announcement:  'Release Announcement',
  webinar:               'Webinar',
  case_study:            'Case Study',
  content_refresh:       'Content Refresh',
};

export function ContentTypeBadge({ type }) {
  return (
    <span style={{
      background: '#f3f4f6',
      color: '#374151',
      borderRadius: 4,
      padding: '2px 8px',
      fontSize: 11,
      fontWeight: 600,
      border: '1px solid #e5e7eb',
    }}>
      {TYPE_LABELS[type] || type?.replace(/_/g, ' ') || '—'}
    </span>
  );
}
