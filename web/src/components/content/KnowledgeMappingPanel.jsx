import { useState } from 'react';
import { Plus, Trash2 } from 'lucide-react';
import { contentService } from '../../services/contentService';

const ENTITY_TYPES = [
  'product', 'module', 'feature', 'persona', 'industry', 'market',
  'problem', 'search_intent', 'topic', 'claim', 'evidence', 'source',
  'citation', 'brand_rule',
];

export function KnowledgeMappingPanel({ contentItemId, mappings = {}, onRefresh, canEdit = false }) {
  const [addType, setAddType]   = useState('product');
  const [addId, setAddId]       = useState('');
  const [adding, setAdding]     = useState(false);
  const [error, setError]       = useState(null);

  const handleAdd = async (e) => {
    e.preventDefault();
    if (!addId || !addType) return;
    setAdding(true);
    setError(null);
    try {
      await contentService.addMapping(contentItemId, addType, parseInt(addId));
      setAddId('');
      onRefresh?.();
    } catch (err) {
      setError(err.message);
    } finally {
      setAdding(false);
    }
  };

  const handleRemove = async (type, entityId) => {
    try {
      await contentService.removeMapping(contentItemId, type, entityId);
      onRefresh?.();
    } catch (err) {
      setError(err.message);
    }
  };

  const hasAnyMappings = ENTITY_TYPES.some((t) => (mappings[t] || []).length > 0);

  return (
    <div style={{ fontSize: 13 }}>
      {error && <div style={{ color: '#ef4444', marginBottom: 8 }}>{error}</div>}

      {!hasAnyMappings && <div style={{ color: '#9ca3af', marginBottom: 8 }}>No knowledge mappings yet.</div>}

      <div style={{ display: 'flex', flexDirection: 'column', gap: 6, marginBottom: canEdit ? 12 : 0 }}>
        {ENTITY_TYPES.map((type) => {
          const ids = mappings[type] || [];
          if (ids.length === 0) return null;
          return (
            <div key={type} style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
              <span style={{
                background: '#f3f4f6',
                color: '#374151',
                borderRadius: 4,
                padding: '2px 8px',
                fontSize: 11,
                fontWeight: 600,
                minWidth: 100,
              }}>
                {type.replace(/_/g, ' ')}
              </span>
              <div style={{ display: 'flex', gap: 4, flexWrap: 'wrap' }}>
                {ids.map((id) => (
                  <span key={id} style={{
                    background: '#dbeafe',
                    color: '#1e40af',
                    borderRadius: 4,
                    padding: '1px 6px',
                    fontSize: 11,
                    display: 'flex',
                    alignItems: 'center',
                    gap: 3,
                  }}>
                    #{id}
                    {canEdit && (
                      <button
                        style={{ background: 'none', border: 'none', cursor: 'pointer', padding: 0, display: 'flex' }}
                        onClick={() => handleRemove(type, id)}
                      >
                        <Trash2 size={10} color="#1e40af" />
                      </button>
                    )}
                  </span>
                ))}
              </div>
            </div>
          );
        })}
      </div>

      {canEdit && (
        <form onSubmit={handleAdd} style={{ display: 'flex', gap: 8, alignItems: 'center' }}>
          <select
            value={addType}
            onChange={(e) => setAddType(e.target.value)}
            style={{ borderRadius: 4, border: '1px solid #e5e7eb', padding: '4px 8px', fontSize: 12 }}
          >
            {ENTITY_TYPES.map((t) => (
              <option key={t} value={t}>{t.replace(/_/g, ' ')}</option>
            ))}
          </select>
          <input
            type="number"
            value={addId}
            onChange={(e) => setAddId(e.target.value)}
            placeholder="Entity ID"
            style={{ borderRadius: 4, border: '1px solid #e5e7eb', padding: '4px 8px', fontSize: 12, width: 100 }}
          />
          <button type="submit" className="btn btn-primary btn-sm" disabled={adding || !addId}>
            <Plus size={12} /> Add
          </button>
        </form>
      )}
    </div>
  );
}
