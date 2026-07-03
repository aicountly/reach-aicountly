const LABELS = {
  linkedin:          'LinkedIn',
  twitter:           'X / Twitter',
  facebook:          'Facebook',
  instagram:         'Instagram',
  youtube:           'YouTube',
  whatsapp_channel:  'WhatsApp',
  email_newsletter:  'Email Newsletter',
  email:             'Email',
  whatsapp:          'WhatsApp',
  social:            'Social',
  landing:           'Landing',
  paid_ad:           'Paid Ad',
  webinar:           'Webinar',
  referral:          'Referral',
  multi:             'Multi',
  blog:              'Blog',
  campaign:          'Campaign',
  other:             'Other',
};

const COLOR = {
  linkedin: 'info',   twitter: 'secondary', facebook: 'info',
  instagram: 'warning', youtube: 'danger',   whatsapp_channel: 'success',
  email_newsletter: 'primary', email: 'primary', whatsapp: 'success',
  social: 'info', landing: 'primary', paid_ad: 'warning',
  webinar: 'info', referral: 'success', multi: 'secondary',
  blog: 'primary', campaign: 'info', other: 'secondary',
};

export function ChannelBadge({ channel }) {
  const label = LABELS[channel] || (channel ? String(channel).replace(/_/g, ' ') : 'channel');
  const color = COLOR[channel]   || 'secondary';
  return <span className={`badge badge-${color}`}>{label}</span>;
}
