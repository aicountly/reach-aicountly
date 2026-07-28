import { Link } from 'react-router-dom';
import { MessageSquare, ShieldOff, BadgeCheck } from 'lucide-react';

const SECTIONS = [
  {
    title: 'SMS Dispatch',
    description:
      'Dispatch approved SMS campaigns to the provider. Validates DLT entity, template, and sender IDs before sending.',
    to: '/distribution/sms/dispatch',
    cta: 'Open Dispatch',
    primary: true,
    icon: MessageSquare,
  },
  {
    title: 'Suppression List',
    description:
      'Manage opted-out numbers. Suppressed numbers are automatically excluded from all SMS dispatch batches.',
    to: '/distribution/suppressions?channel=sms',
    cta: 'Manage Suppressions',
    primary: false,
    icon: ShieldOff,
  },
  {
    title: 'DLT Compliance',
    description:
      'Validate DLT entity ID, template ID, and sender ID for Indian regulatory compliance (TRAI).',
    to: '/distribution/sms/dispatch',
    cta: 'Validate DLT',
    primary: false,
    icon: BadgeCheck,
  },
];

export default function SmsOverviewPage() {
  return (
    <div>
      <div className="page-header page-header--stack">
        <h1>SMS Channel</h1>
        <p className="page-header__subtitle">
          Manage SMS campaigns with DLT compliance and suppression controls
        </p>
      </div>

      <div className="cards-grid">
        {SECTIONS.map(({ title, description, to, cta, primary, icon: Icon }) => (
          <div key={title} className="card">
            <div className="card__header">
              <h3 className="card__title">
                <Icon size={16} className="card__icon" aria-hidden="true" />
                {title}
              </h3>
            </div>
            <div className="card__body">
              <p>{description}</p>
            </div>
            <div className="card__footer">
              <Link
                to={to}
                className={primary ? 'btn btn--primary' : 'btn btn--secondary'}
              >
                {cta}
              </Link>
            </div>
          </div>
        ))}
      </div>
    </div>
  );
}
