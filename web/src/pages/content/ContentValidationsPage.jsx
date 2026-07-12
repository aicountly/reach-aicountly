import { useCallback, useEffect, useState } from 'react';
import { useParams } from 'react-router-dom';
import { contentService } from '../../services/contentService';
import { Card } from '../../components/common/Card';
import { Alert } from '../../components/common/Alert';
import { Loader } from '../../components/common/Loader';
import { ValidationPanel } from '../../components/content/ValidationPanel';
import { usePermission } from '../../hooks/usePermission';

export function ContentValidationsPage() {
  const { id } = useParams();
  const { has } = usePermission();
  const canWaive = has('content_validation.waive');
  const [validations, setValidations] = useState([]);
  const [loading, setLoading]         = useState(true);
  const [error, setError]             = useState(null);

  const load = useCallback(async () => {
    setLoading(true);
    try {
      const data = await contentService.listValidations(id);
      setValidations(data.validations || []);
    } catch (e) { setError(e.message); }
    finally { setLoading(false); }
  }, [id]);

  useEffect(load, [load]);

  if (loading) return <Loader />;

  return (
    <div>
      <div className="page-header"><h1>Validations</h1></div>
      {error && <Alert variant="danger">{error}</Alert>}
      <Card>
        <ValidationPanel
          contentItemId={id}
          validations={validations}
          onRefresh={load}
          canWaive={canWaive}
        />
      </Card>
    </div>
  );
}
