import { RoutineSubmissionsPanel } from '../../components/automation/RoutineSubmissionsPanel';

/**
 * Routine Articles — blogs written by the Claude routine, with Gemini's
 * vetting decision (the review of record), the generated cover and where
 * publishing got to. Retry pushes a stalled one forward.
 */
export function RoutineArticlesPage() {
  return (
    <div>
      <div className="page-header" style={{ marginBottom: 0 }}>
        <div>
          <h2 style={{ margin: 0 }}>Routine Articles</h2>
          <p className="text-sm text-muted" style={{ marginTop: 4 }}>
            Gemini approves, corrects or rejects every routine blog; approved ones get a DALL·E cover and publish automatically.
            Items Gemini marks high risk wait in the Verification Queue for a person.
          </p>
        </div>
      </div>
      <RoutineSubmissionsPanel contentType="blog" title="Routine blogs" />
    </div>
  );
}

export default RoutineArticlesPage;
