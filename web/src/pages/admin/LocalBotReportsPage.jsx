import { useEffect, useState } from 'react';
import { botService } from '../../services/botService';
import { Card } from '../../components/common/Card';
import { Alert } from '../../components/common/Alert';
import { Loader } from '../../components/common/Loader';
import { BotReportTimeline } from '../../components/bot/BotReportTimeline';

export function LocalBotReportsPage() {
  const [reports, setReports] = useState([]);
  const [error, setError]     = useState(null);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    botService.reports({ limit: 30 })
      .then((d) => setReports(d.items || d))
      .catch((e) => setError(e.message))
      .finally(() => setLoading(false));
  }, []);

  return (
    <div>
      <div className="page-header">
        <div>
          <h1>Local bot reports</h1>
          <p className="text-sm text-muted">Compact timeline view of the most recent 30 bot reports.</p>
        </div>
      </div>
      {error && <Alert variant="danger">{error}</Alert>}
      {loading ? <Loader /> : (
        <Card><BotReportTimeline reports={reports} /></Card>
      )}
    </div>
  );
}
