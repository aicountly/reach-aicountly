import { useCallback, useEffect, useRef, useState } from 'react';
import { AlertTriangle, Upload, Images } from 'lucide-react';
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

/**
 * A cover only enters the rotation when it is an active upload with something
 * to match on — which is why the deficit can read 0 available while the
 * gallery shows images.
 */
function isRotationReady(asset) {
  return asset?.status === 'active' && asset?.kind === 'gallery_upload' && !isUnmatchable(asset);
}

/** Alt text describes the asset; the stored prompt is a paragraph, not alt text. */
function altFor(asset) {
  const tags = assetTags(asset);
  return tags.length ? `Cover image tagged ${tags.join(', ')}` : `Cover image ${asset.id}`;
}

function Thumb({ asset, servable }) {
  const box = {
    width: '100%', aspectRatio: '3 / 2', borderRadius: '4px',
    display: 'flex', alignItems: 'center', justifyContent: 'center',
    background: 'var(--color-bg)', border: '1px dashed var(--color-border)',
    padding: '0.5rem', textAlign: 'center',
  };

  if (asset.file_missing) {
    return (
      <div style={box} className="text-xs text-muted">
        Image file missing on the server — the database row survived a deploy the binary did not.
      </div>
    );
  }

  if (!servable) {
    return (
      <div style={box} className="text-xs text-muted">
        Cannot be served until <code>MEDIA_SIGNING_KEY</code> is set.
      </div>
    );
  }

  return (
    <img
      src={asset.public_url}
      alt={altFor(asset)}
      title={asset.prompt_used || undefined}
      style={{ width: '100%', aspectRatio: '3 / 2', objectFit: 'cover', borderRadius: '4px' }}
      loading="lazy"
    />
  );
}

export function MediaGalleryPage() {
  const [assets, setAssets] = useState([]);
  const [meta, setMeta] = useState({
    signing_key_configured: true,
    files_missing: 0,
    storage_path: '',
    storage_writable: true,
    storage_outside_deploy: true,
  });
  const [deficit, setDeficit] = useState(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [notice, setNotice] = useState('');
  const [uploading, setUploading] = useState(false);
  const [tags, setTags] = useState('');
  const [stream, setStream] = useState('');
  const [tab, setTab] = useState('deficit');
  const fileRef = useRef(null);

  const load = useCallback(() => {
    setLoading(true);
    Promise.all([mediaGalleryService.list(), mediaGalleryService.deficit()])
      .then(([listRes, deficitRes]) => {
        setAssets(listRes?.assets ?? []);
        setMeta({
          signing_key_configured: listRes?.signing_key_configured !== false,
          files_missing: listRes?.files_missing ?? 0,
          storage_path: listRes?.storage_path ?? '',
          storage_writable: listRes?.storage_writable,
          storage_outside_deploy: listRes?.storage_outside_deploy,
        });
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

  const rotationReady = assets.filter(isRotationReady).length;
  const tabs = [
    { id: 'deficit', label: `Deficit${deficit?.deficit ? ` (${deficit.deficit})` : ''}`, icon: AlertTriangle },
    { id: 'upload', label: 'Upload', icon: Upload },
    { id: 'gallery', label: `Gallery (${assets.length})`, icon: Images },
  ];

  return (
    <div>
      <div className="page-header">
        <div>
          <h1>Cover Gallery</h1>
          <p className="text-sm text-muted">
            Covers shared across blog, knowledge base and community.
            {' '}{rotationReady} of {assets.length} are rotation-ready.
          </p>
        </div>
      </div>

      {error && <Alert variant="danger">{error}</Alert>}
      {notice && <Alert variant="success">{notice}</Alert>}

      {!meta.signing_key_configured && (
        <Alert variant="danger">
          <strong>Cover images cannot be served.</strong> <code>MEDIA_SIGNING_KEY</code> is not set in
          {' '}<code>api/.env</code>, and serving fails closed — every cover returns 404 here <em>and</em> on
          aicountly.com, where published articles reference these same URLs. Generate a key with
          {' '}<code>openssl rand -hex 32</code>, add it as <code>MEDIA_SIGNING_KEY=…</code>, and reload.
          Existing images are unaffected: the signature is derived per request, not stored.
        </Alert>
      )}

      {meta.files_missing > 0 && (
        <Alert variant="warning">
          {meta.files_missing} asset(s) have a database row but no file on disk.
          {' '}Run <code>php spark reach:media-reconcile --fix</code> to retire them, then re-upload.
        </Alert>
      )}

      {meta.storage_writable === false && (
        <Alert variant="danger">
          <strong>Uploads will fail.</strong> The cover directory
          {' '}<code>{meta.storage_path}</code> does not exist or is not writable by the web user.
          {' '}Create it and give it to the account that runs PHP.
        </Alert>
      )}

      {meta.storage_writable !== false && meta.storage_outside_deploy === false && (
        <Alert variant="warning">
          Covers are stored at <code>{meta.storage_path}</code>, inside the deployed API directory.
          {' '}Deploys rsync that directory with <code>--delete</code>, so uploads there are one
          filter-rule mistake away from being erased — which is how every cover was lost before.
          {' '}Point <code>MEDIA_STORAGE_PATH</code> at a directory outside the document root.
        </Alert>
      )}

      <div className="flex gap-2" role="tablist">
        {tabs.map((entry) => (
          <button
            key={entry.id}
            type="button"
            role="tab"
            aria-selected={tab === entry.id}
            className={`btn btn-sm ${tab === entry.id ? 'btn-primary' : 'btn-secondary'}`}
            onClick={() => setTab(entry.id)}
          >
            <entry.icon size={14} /> {entry.label}
          </button>
        ))}
      </div>

      <div className="mt-4">
        {tab === 'deficit' && deficit && (
          <Card title="Cover deficit">
            {deficit.deficit > 0 ? (
              <>
                <Alert variant="warning">
                  {deficit.deficit} cover image(s) missing for the next {deficit.lookahead_days} days
                  ({deficit.needed} upcoming entries, {deficit.available} rotation-ready covers).
                  Generate them with the prompts below and upload them on the Upload tab.
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
                            className="btn btn-secondary btn-sm"
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
                relevance, so untagged ones are never picked — tag them on the Gallery tab to bring them
                into rotation.
              </Alert>
            )}
          </Card>
        )}

        {tab === 'upload' && (
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
              <button type="submit" className="btn btn-primary" disabled={uploading}>
                {uploading ? 'Uploading…' : 'Upload'}
              </button>
            </form>
            <p className="text-sm text-muted mt-4">
              Tags are what make a cover usable — assignment scores them against the article&apos;s
              category, stream, tags and title. An untagged upload can never be matched.
            </p>
          </Card>
        )}

        {tab === 'gallery' && (
          <Card title={`Gallery (${assets.length})`}>
            {assets.length === 0 ? (
              <p className="text-muted">
                No covers yet. Routine blogs park for review until a suitable cover exists.
              </p>
            ) : (
              <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fill, minmax(200px, 1fr))', gap: '1rem' }}>
                {assets.map((asset) => (
                  <div key={asset.id} className="card" style={{ padding: '0.5rem' }}>
                    <Thumb asset={asset} servable={meta.signing_key_configured} />
                    <div className="text-sm" style={{ marginTop: '0.5rem' }}>
                      <div>
                        <span className={`badge ${asset.status === 'active' ? 'badge--success' : 'badge--muted'}`}>{asset.status}</span>
                        {' '}<span className="text-muted">{asset.kind}</span>
                        {asset.status === 'active' && isUnmatchable(asset) && (
                          <> <span className="badge badge--warning">untagged · never assigned</span></>
                        )}
                        {asset.status === 'active' && asset.kind !== 'gallery_upload' && (
                          <> <span className="badge badge--muted">not in rotation</span></>
                        )}
                      </div>
                      <div className="text-muted">
                        {assetTags(asset).length > 0 ? assetTags(asset).join(', ') : 'no tags'}
                        {asset.portfolio_stream ? ` · ${asset.portfolio_stream}` : ''}
                      </div>
                      <div className="text-muted">Used {asset.times_used}× {asset.last_used_at ? `· last ${formatDate(asset.last_used_at)}` : ''}</div>
                      <div style={{ display: 'flex', gap: '0.25rem', marginTop: '0.25rem' }}>
                        <button type="button" className="btn btn-secondary btn-sm" onClick={() => handleRetag(asset)}>
                          Edit tags
                        </button>
                        <button type="button" className="btn btn-secondary btn-sm" onClick={() => handleRetire(asset)}>
                          {asset.status === 'active' ? 'Retire' : 'Reactivate'}
                        </button>
                      </div>
                    </div>
                  </div>
                ))}
              </div>
            )}
          </Card>
        )}
      </div>
    </div>
  );
}
