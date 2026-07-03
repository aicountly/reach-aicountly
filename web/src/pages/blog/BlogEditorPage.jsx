import { useEffect, useState } from 'react';
import { useNavigate, useParams } from 'react-router-dom';
import { Save, ArrowLeft } from 'lucide-react';
import { blogService } from '../../services/blogService';
import { Card } from '../../components/common/Card';
import { Alert } from '../../components/common/Alert';
import { Loader } from '../../components/common/Loader';

const EMPTY = {
  title: '', slug: '', excerpt: '', content: '', category: '', tags: '',
  seo_title: '', seo_description: '', canonical_url: '', focus_keyword: '',
  author: '', featured_image: '', scheduled_at: '',
};

function toStringField(v) {
  if (v == null) return '';
  if (Array.isArray(v)) return v.join(', ');
  return String(v);
}

export function BlogEditorPage() {
  const { id } = useParams();
  const navigate = useNavigate();
  const isNew = !id;
  const [form, setForm]   = useState(EMPTY);
  const [error, setError] = useState(null);
  const [saving, setSaving]= useState(false);
  const [loading, setLoading] = useState(!isNew);

  useEffect(() => {
    if (isNew) return;
    blogService.get(id)
      .then((d) => setForm({
        ...EMPTY,
        ...d,
        tags: toStringField(d.tags),
        scheduled_at: d.scheduled_at ? d.scheduled_at.substring(0, 16) : '',
      }))
      .catch((e) => setError(e.message))
      .finally(() => setLoading(false));
  }, [id, isNew]);

  const set = (k) => (e) => setForm((s) => ({ ...s, [k]: e.target.value }));

  const submit = async (e) => {
    e.preventDefault();
    setSaving(true);
    setError(null);
    try {
      const payload = {
        ...form,
        tags: form.tags ? form.tags.split(',').map((t) => t.trim()).filter(Boolean) : [],
        scheduled_at: form.scheduled_at ? form.scheduled_at.replace('T', ' ') + ':00' : null,
      };
      const saved = isNew
        ? await blogService.create(payload)
        : await blogService.update(id, payload);
      navigate(`/blog/${saved.id ?? id}`);
    } catch (err) {
      setError(err.message);
    } finally {
      setSaving(false);
    }
  };

  if (loading) return <Loader />;

  return (
    <div>
      <div className="page-header">
        <div>
          <button className="btn btn-secondary btn-sm" onClick={() => navigate(-1)}>
            <ArrowLeft size={12} /> Back
          </button>
          <h1 style={{ marginTop: 6 }}>{isNew ? 'New blog post' : 'Edit blog post'}</h1>
        </div>
      </div>

      {error && <Alert variant="danger">{error}</Alert>}

      <form onSubmit={submit} className="grid grid-2" style={{ alignItems: 'start' }}>
        <div className="flex flex-col gap-3">
          <Card title="Content">
            <div className="flex flex-col gap-3">
              <div>
                <label className="text-xs text-secondary">Title</label>
                <input required value={form.title} onChange={set('title')} />
              </div>
              <div>
                <label className="text-xs text-secondary">Slug</label>
                <input value={form.slug} onChange={set('slug')} placeholder="auto-generated if empty" />
              </div>
              <div>
                <label className="text-xs text-secondary">Excerpt</label>
                <textarea rows={3} value={form.excerpt} onChange={set('excerpt')} />
              </div>
              <div>
                <label className="text-xs text-secondary">Content</label>
                <textarea rows={16} value={form.content} onChange={set('content')} placeholder="Markdown or HTML" />
              </div>
            </div>
          </Card>
        </div>

        <div className="flex flex-col gap-3">
          <Card title="Taxonomy">
            <div className="flex flex-col gap-3">
              <div>
                <label className="text-xs text-secondary">Category</label>
                <input value={form.category} onChange={set('category')} />
              </div>
              <div>
                <label className="text-xs text-secondary">Tags (comma separated)</label>
                <input value={form.tags} onChange={set('tags')} />
              </div>
              <div>
                <label className="text-xs text-secondary">Author</label>
                <input value={form.author} onChange={set('author')} />
              </div>
              <div>
                <label className="text-xs text-secondary">Featured image URL</label>
                <input value={form.featured_image} onChange={set('featured_image')} />
              </div>
            </div>
          </Card>

          <Card title="SEO">
            <div className="flex flex-col gap-3">
              <div>
                <label className="text-xs text-secondary">SEO title</label>
                <input value={form.seo_title} onChange={set('seo_title')} />
              </div>
              <div>
                <label className="text-xs text-secondary">SEO description</label>
                <textarea rows={2} value={form.seo_description} onChange={set('seo_description')} />
              </div>
              <div>
                <label className="text-xs text-secondary">Canonical URL</label>
                <input value={form.canonical_url} onChange={set('canonical_url')} />
              </div>
              <div>
                <label className="text-xs text-secondary">Focus keyword</label>
                <input value={form.focus_keyword} onChange={set('focus_keyword')} />
              </div>
            </div>
          </Card>

          <Card title="Scheduling">
            <label className="text-xs text-secondary">Scheduled at</label>
            <input type="datetime-local" value={form.scheduled_at} onChange={set('scheduled_at')} />
          </Card>

          <div className="flex justify-end gap-2">
            <button type="button" className="btn btn-secondary" onClick={() => navigate(-1)}>Cancel</button>
            <button type="submit" className="btn btn-primary" disabled={saving}>
              <Save size={14} /> {saving ? 'Saving…' : 'Save'}
            </button>
          </div>
        </div>
      </form>
    </div>
  );
}
