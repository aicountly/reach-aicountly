/**
 * Publication preflight readiness checklist.
 *
 * Displays all required conditions before a video can be published to YouTube.
 * No action is taken — this is a read-only status display component.
 *
 * Props:
 *  - project     {object} — Video project record.
 *  - script      {object|null} — Current script record.
 *  - renderJob   {object|null} — Latest render job record.
 *  - connection  {object|null} — Active YouTube connection.
 */
export function VideoPublicationPreflight({ project, script, renderJob, connection }) {
  const checks = [
    {
      label:   'Project is in publishable state',
      passed:  ['rendered', 'publish_queued', 'publishing', 'published'].includes(project?.status),
      detail:  `Project status: ${project?.status ?? 'unknown'}`,
    },
    {
      label:   'Script is approved',
      passed:  script?.workflow_status === 'approved',
      detail:  `Script status: ${script?.workflow_status ?? 'no script'}`,
    },
    {
      label:   'Render job completed successfully',
      passed:  renderJob?.status === 'rendered',
      detail:  `Render status: ${renderJob?.status ?? 'no render job'}`,
    },
    {
      label:   'YouTube connection is active',
      passed:  connection !== null && connection !== undefined,
      detail:  connection ? `Connection: ${connection.name ?? connection.id}` : 'No active YouTube connection',
    },
  ];

  const allPassed  = checks.every(c => c.passed);
  const passedCount = checks.filter(c => c.passed).length;

  return (
    <div className="publication-preflight card">
      <h3 className="card__title">Publication readiness</h3>
      <div className={`preflight-summary mb-3 ${allPassed ? 'text-success' : 'text-muted'}`}>
        {allPassed
          ? 'All readiness conditions met — ready to publish.'
          : `${passedCount}/${checks.length} conditions met.`}
      </div>

      <ul className="preflight-checklist">
        {checks.map((check, i) => (
          <li key={i} className={`preflight-checklist__item ${check.passed ? 'passed' : 'pending'}`}>
            <span className="preflight-checklist__icon" aria-hidden="true">
              {check.passed ? '✓' : '○'}
            </span>
            <span className="preflight-checklist__label">{check.label}</span>
            <span className="preflight-checklist__detail text-muted">{check.detail}</span>
          </li>
        ))}
      </ul>
    </div>
  );
}
