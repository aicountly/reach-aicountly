import { useCallback, useEffect, useRef, useState } from 'react';
import { mediaGalleryService } from '../../services/mediaGalleryService';
import { Loader } from '../../components/common/Loader';
import { Alert } from '../../components/common/Alert';
import { Card } from '../../components/common/Card';
import { formatDate } from '../../utils/formatDate';

/** category_tags arrives as a JSONB string from Postgres or an array once edited. */
function assetTags(asset) {
  const raw = asset?.category_tags;
  if (Array.isArray(raw)) return raw.map(String);
  if (typeof raw === 'string' && raw.trim() !== '') {
    try {
      const parsed = JSON.parse(raw);
      return Array.isArray(parsed) ? parsed.map(String) : [];
    } catch {
      return [];
    }
  }
  return [];
}

function isUnmatchable(asset) {
  return assetTags(asset).length === 0 && !asset?.portfolio_stream;
}

export function MediaGalleryPage() {
  const [assets, setAssets] = useState([]);
  const [deficit, setDeficit] = useState(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [notice, setNotice] = useState('');
  const [uploading, setUploading] = useState(false);
  const [tags, setTags] = useState('');
  const [stream, setStream] = useState('');
  const fileRef = useRef(null);

  const load = useCallback(() => {
    setLoading(true);
    Promise.all([mediaGalleryService.list(), mediaGalleryService.deficit()])
      .then(([listRes, deficitRes]) => {
        setAssets(listRes?.assets ?? []);
        setDeficit(deficitRes);
        setError('');
      })
      .catch((err) => setError(err?.message || 'Unable to load the gallery.'))
      .finally(() => setLoading(false));
  }, []);

  useEffect(load, [load]);

  const handleUpload = async (event) => {
    event.preventDefault();
    const file = fileRef.current?.files?.[0];
    if (!file) {
      setError('Choose an image file first.');
      return;
    }
    setUploading(true);
    setError('');
    setNotice('');
    try {
      await mediaGalleryService.upload(file, { tags, portfolioStream: stream });
      setNotice(`Uploaded ${file.name}. It joins the rotation immediately.`);
      if (fileRef.current) fileRef.current.value = '';
      load();
    } catch (err) {
      setError(err?.message || 'Upload failed.');
    } finally {
      setUploading(false);
    }
  };

  const handleRetire = async (asset) => {
    const nextStatus = asset.status === 'active' ? 'retired' : 'active';
    try {
      await mediaGalleryService.update(asset.id, { status: nextStatus });
      load();
    } catch (err) {
      setError(err?.message || 'Update failed.');
    }
  };

  // Covers are matched to articles by tag/stream relevance, so an untagged
  // asset never gets assigned. Tagging is the fix, not another upload.
  const handleRetag = async (asset) => {
    const current = assetTags(asset).join(', ');
    const next = window.prompt(
      `Tags for asset ${asset.id} (comma-separated, e.g. "gst, compliance, invoicing").\n` +
        'Covers are matched to articles by these tags — untagged covers are never assigned.',
      current,
    );
    if (next === null) return;
    const parsed = next.split(',').map((t) => t.trim()).filter(Boolean);
    try {
      await mediaGalleryService.update(asset.id, { category_tags: parsed });
      setNotice(`Updated tags for asset ${asset.id}.`);
      load();
    } catch (err) {
      setError(err?.message || 'Update failed.');
    }
  };

  if (loading) return <Loader label="Loading cover gallery…" />;

  return (
    <div>
      {error && <Alert variant="error">{error}</Alert>}
      {notice && <Alert variant="success">{notice}</Alert>}

      {deficit && (
        <Card title="Cover deficit">
          {deficit.deficit > 0 ? (
            <>
              <Alert variant="warning">
                {deficit.deficit} cover image(s) missing for the next {deficit.lookahead_days} days
                ({deficit.needed} upcoming entries, {deficit.available} rotation-ready covers).
                Generate them with the prompts below and upload here.
              </Alert>
              <ul className="text-sm" style={{ paddingLeft: '1.25rem' }}>
                {(deficit.upcoming || []).map((entry) => (
                  <li key={entry.key} style={{ marginBottom: '0.5rem' }}>
                    <strong>{entry.title}</strong> {entry.target_date ? `(target ${formatDate(entry.target_date)})` : ''}
                    {entry.cover_prompt && (
                      <div className="text-muted">
                        Prompt: {entry.cover_prompt}{' '}
                        <button
                          type="button"
                          className="btn btn--secondary btn--sm"
                          onClick={() => navigator.clipboard?.writeText(entry.cover_prompt)}
                        >
                          Copy
                        </button>
                      </div>
                    )}
                  </li>
                ))}
              </ul>
            </>
          ) : (
            <Alert variant="success">
              No deficit — {deficit.available} rotation-ready covers for {deficit.needed} upcoming entries.
            </Alert>
          )}
          {deficit.untagged > 0 && (
            <Alert variant="warning">
              {deficit.untagged} active cover(s) have no tags or stream. Covers are assigned by topical
              relevance, so untagged ones are never picked — tag them below to bring them into rotation.
            </Alert>
          )}
        </Card>
      )}

      <Card title="Upload cover image">
        <form onSubmit={handleUpload} style={{ display: 'flex', gap: '0.75rem', flexWrap: 'wrap', alignItems: 'end' }}>
          <label>
            <span className="text-muted" style={{ display: 'block' }}>Image (max 4 MB, stored as WebP)</span>
            <input ref={fileRef} type="file" accept="image/*" />
          </label>
          <label>
            <span className="text-muted" style={{ display: 'block' }}>Tags (comma-separated categories)</span>
            <input value={tags} onChange={(e) => setTags(e.target.value)} placeholder="gst, accounting" />
          </label>
          <label>
            <span className="text-muted" style={{ display: 'block' }}>Stream</span>
            <select value={stream} onChange={(e) => setStream(e.target.value)}>
              <option value="">Any</option>
              <option value="marketing">marketing</option>
              <option value="product">product</option>
              <option value="problem_to_product">problem_to_product</option>
            </select>
          </label>
          <button type="submit" className="btn btn--primary" disabled={uploading}>
            {uploading ? 'Uploading…' : 'Upload'}
          </button>
        </form>
      </Card>

      <Card title={`Gallery (${assets.length})`}>
        {assets.length === 0 ? (
          <p className="text-muted">No covers yet. Routine blogs publish without a hero until covers exist.</p>
        ) : (
          <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fill, minmax(200px, 1fr))', gap: '1rem' }}>
            {assets.map((asset) => (
              <div key={asset.id} className="card" style={{ padding: '0.5rem' }}>
                <img
                  src={asset.public_url}
                  alt={asset.prompt_used || `Gallery asset ${asset.id}`}
                  style={{ width: '100%', aspectRatio: '3 / 2', objectFit: 'cover', borderRadius: '4px' }}
                  loading="lazy"
                />
                <div className="text-sm" style={{ marginTop: '0.5rem' }}>
                  <div>
                    <span className={`badge ${asset.status === 'active' ? 'badge--success' : 'badge--muted'}`}>{asset.status}</span>
                    {' '}<span className="text-muted">{asset.kind}</span>
                    {asset.status === 'active' && isUnmatchable(asset) && (
                      <> <span className="badge badge--warning">untagged · never assigned</span></>
                    )}
                  </div>
                  <div className="text-muted">
                    {assetTags(asset).length > 0 ? assetTags(asset).join(', ') : 'no tags'}
                    {asset.portfolio_stream ? ` · ${asset.portfolio_stream}` : ''}
                  </div>
                  <div className="text-muted">Used {asset.times_used}× {asset.last_used_at ? `· last ${formatDate(asset.last_used_at)}` : ''}</div>
                  <div style={{ display: 'flex', gap: '0.25rem', marginTop: '0.25rem' }}>
                    <button type="button" className="btn btn--secondary btn--sm" onClick={() => handleRetag(asset)}>
                      Edit tags
                    </button>
                    <button type="button" className="btn btn--secondary btn--sm" onClick={() => handleRetire(asset)}>
                      {asset.status === 'active' ? 'Retire' : 'Reactivate'}
                    </button>
                  </div>
                </div>
              </div>
            ))}
          </div>
        )}
      </Card>
    </div>
  );
}
