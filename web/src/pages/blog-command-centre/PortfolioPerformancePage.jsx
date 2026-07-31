import { Alert } from '../../components/common/Alert';

export function PortfolioPerformancePage() {
  return (
    <div>
      <h2>Portfolio Performance</h2>
      <p className="text-sm text-muted">Blog portfolio analytics across marketing, product, and problem-to-product streams.</p>
      <Alert variant="info">
        Analytics connectors are not wired for blog portfolio performance yet. Status: NO DATA.
      </Alert>
    </div>
  );
}
