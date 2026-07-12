import { useCallback, useEffect, useState } from 'react';
import { useParams } from 'react-router-dom';
import { contentService } from '../../services/contentService';
import { Card } from '../../components/common/Card';
import { Alert } from '../../components/common/Alert';
import { Loader } from '../../components/common/Loader';
import { VersionDiff } from '../../components/content/VersionDiff';

export function ContentVersionsPage() {
  const { id } = useParams();
  const [versions, setVersions]   = useState([]);
  const [loading, setLoading]     = useState(true);
  const [error, setError]         = useState(null);
  const [compareA, setCompareA]   = useState(null);
  const [compareB, setCompareB]   = useState(null);
  const [diffData, setDiffData]   = useState(null);
  const [diffLoading, setDiffLoading] = useState(false);

  const load = useCallback(async () => {
    setLoading(true);
    try {
      const data = await contentService.listVersions(id);
      setVersions(data.versions || []);
    } catch (e) { setError(e.message); }
    finally { setLoading(false); }
  }, [id]);

  useEffect(load, [load]);

  const handleCompare = async () => {
    if (!compareA || !compareB) return;
    setDiffLoading(true);
    try {
      const data = await contentService.compareVersions(id, compareA, compareB);
      setDiffData(data);
    } catch (e) { setError(e.message); }
    finally { setDiffLoading(false); }
  };

  if (loading) return <Loader />;

  return (
    <div>
      <div className="page-header"><h1>Version History</h1></div>
      {error && <Alert variant="danger">{error}</Alert>}

      <div style={{ display: 'grid', gridTemplateColumns: '1fr 2fr', gap: 16 }}>
        <div>
          <Card>
            <div style={{ fontWeight: 700, marginBottom: 8, fontSize: 13 }}>All Versions</div>
            {versions.map((v) => (
              <div key={v.id} style={{
                padding: '8px 10px',
                borderRadius: 6,
                background: v.is_current ? '#dbeafe' : '#f9fafb',
                marginBottom: 4,
                fontSize: 12,
                cursor: 'pointer',
                border: '1px solid ' + (v.is_current ? '#bfdbfe' : '#e5e7eb'),
              }}>
                <div style={{ fontWeight: 700 }}>v{v.version_number} {v.is_current && <span style={{ color: '#2563eb' }}>(current)</span>}</div>
                <div style={{ color: '#6b7280' }}>{v.change_summary || 'No summary'}</div>
                <div style={{ color: '#9ca3af', fontSize: 10 }}>{v.created_at ? new Date(v.created_at).toLocaleString() : ''}</div>
                <div style={{ marginTop: 4, display: 'flex', gap: 4 }}>
                  <button className="btn btn-ghost btn-sm" onClick={() => setCompareA(v.id)} style={{ fontSize: 10 }}>Set A</button>
                  <button className="btn btn-ghost btn-sm" onClick={() => setCompareB(v.id)} style={{ fontSize: 10 }}>Set B</button>
                </div>
              </div>
            ))}
            {compareA && compareB && (
              <button className="btn btn-primary btn-sm" onClick={handleCompare} disabled={diffLoading} style={{ marginTop: 8 }}>
                Compare v{versions.find(v => v.id === compareA)?.version_number} ↔ v{versions.find(v => v.id === compareB)?.version_number}
              </button>
            )}
          </Card>
        </div>
        <Card>
          <div style={{ fontWeight: 700, marginBottom: 8, fontSize: 13 }}>Diff</div>
          {!diffData && <div style={{ color: '#9ca3af', fontSize: 13 }}>Select two versions to compare.</div>}
          {diffLoading && <Loader />}
          {diffData && (
            <VersionDiff
              versionA={diffData.version_a}
              versionB={diffData.version_b}
              fieldsChanged={diffData.fields_changed}
            />
          )}
        </Card>
      </div>
    </div>
  );
}
