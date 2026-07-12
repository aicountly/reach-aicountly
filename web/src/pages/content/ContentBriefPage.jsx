import { useCallback, useEffect, useState } from 'react';
import { useParams } from 'react-router-dom';
import { contentService } from '../../services/contentService';
import { Card } from '../../components/common/Card';
import { Alert } from '../../components/common/Alert';
import { Loader } from '../../components/common/Loader';
import { usePermission } from '../../hooks/usePermission';

export function ContentBriefPage() {
  const { id } = useParams();
  const { has } = usePermission();
  const canEdit = has('content.edit');
  const [brief, setBrief]       = useState({});
  const [loading, setLoading]   = useState(true);
  const [saving, setSaving]     = useState(false);
  const [error, setError]       = useState(null);
  const [success, setSuccess]   = useState(false);

  const load = useCallback(async () => {
    setLoading(true);
    try {
      const data = await contentService.getBrief(id);
      setBrief(data || {});
    } catch (e) { setError(e.message); }
    finally { setLoading(false); }
  }, [id]);

  useEffect(() => { load(); }, [load]);

  const handleSave = async (e) => {
    e.preventDefault();
    setSaving(true);
    setError(null);
    try {
      await contentService.upsertBrief(id, brief);
      setSuccess(true);
      setTimeout(() => setSuccess(false), 2000);
    } catch (err) { setError(err.message); }
    finally { setSaving(false); }
  };

  const textField = (key, label, multi = false) => (
    <div style={{ marginBottom: 12 }}>
      <label style={{ display: 'block', fontSize: 12, fontWeight: 600, marginBottom: 4 }}>{label}</label>
      {multi ? (
        <textarea rows={3} value={brief[key] || ''} onChange={(e) => setBrief((b) => ({ ...b, [key]: e.target.value }))} disabled={!canEdit}
          style={{ width: '100%', borderRadius: 4, border: '1px solid #d1d5db', padding: '7px 10px', fontSize: 13 }} />
      ) : (
        <input type="text" value={brief[key] || ''} onChange={(e) => setBrief((b) => ({ ...b, [key]: e.target.value }))} disabled={!canEdit}
          style={{ width: '100%', borderRadius: 4, border: '1px solid #d1d5db', padding: '7px 10px', fontSize: 13 }} />
      )}
    </div>
  );

  if (loading) return <Loader />;

  return (
    <div style={{ maxWidth: 640 }}>
      <div className="page-header"><h1>Content Brief</h1></div>
      {error && <Alert variant="danger">{error}</Alert>}
      {success && <Alert variant="success">Brief saved.</Alert>}
      <Card>
        <form onSubmit={handleSave}>
          {textField('objective', 'Objective', true)}
          {textField('audience_description', 'Audience Description', true)}
          {textField('primary_keyword', 'Primary Keyword')}
          {textField('cta', 'CTA', true)}
          {textField('tone', 'Tone')}
          <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 12, marginBottom: 12 }}>
            <div>
              <label style={{ display: 'block', fontSize: 12, fontWeight: 600, marginBottom: 4 }}>Min Word Count</label>
              <input type="number" value={brief.min_word_count || ''} onChange={(e) => setBrief((b) => ({ ...b, min_word_count: e.target.value }))} disabled={!canEdit}
                style={{ width: '100%', borderRadius: 4, border: '1px solid #d1d5db', padding: '7px 10px', fontSize: 13 }} />
            </div>
            <div>
              <label style={{ display: 'block', fontSize: 12, fontWeight: 600, marginBottom: 4 }}>Max Word Count</label>
              <input type="number" value={brief.max_word_count || ''} onChange={(e) => setBrief((b) => ({ ...b, max_word_count: e.target.value }))} disabled={!canEdit}
                style={{ width: '100%', borderRadius: 4, border: '1px solid #d1d5db', padding: '7px 10px', fontSize: 13 }} />
            </div>
          </div>
          {textField('due_date', 'Due Date')}
          {canEdit && (
            <button type="submit" className="btn btn-primary" disabled={saving} style={{ marginTop: 8 }}>
              {saving ? 'Saving…' : 'Save Brief'}
            </button>
          )}
        </form>
      </Card>
    </div>
  );
}

