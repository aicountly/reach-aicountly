import { useCallback, useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { BookOpen, Tag, Users, ShieldAlert } from 'lucide-react';
import { knowledgeService } from '../../services/knowledgeService';
import { Loader } from '../../components/common/Loader';
import { ROUTES } from '../../constants/routes';
import { usePermission } from '../../hooks/usePermission';

function StatCard({ icon, label, count, to, color = '#3b82f6' }) {
  const Icon = icon;
  return (
    <Link to={to} style={{ textDecoration: 'none' }}>
      <div style={{
        border: '1px solid var(--color-border, #e5e7eb)', borderRadius: 12,
        padding: '1.25rem 1.5rem', background: 'var(--color-surface, #fff)',
        display: 'flex', alignItems: 'center', gap: 16, cursor: 'pointer',
        transition: 'box-shadow 0.15s',
      }}>
        <div style={{ background: `${color}20`, borderRadius: 10, padding: '0.6rem' }}>
          <Icon size={22} color={color} />
        </div>
        <div>
          <div style={{ fontSize: 24, fontWeight: 700 }}>{count ?? '—'}</div>
          <div style={{ fontSize: 13, color: '#6b7280' }}>{label}</div>
        </div>
      </div>
    </Link>
  );
}

function totalOrNull(result) {
  return result.status === 'fulfilled' && result.value ? (result.value.total ?? 0) : null;
}

export function KnowledgeIndexPage() {
  const { has } = usePermission();
  const [stats, setStats] = useState({});
  const [loading, setLoading] = useState(true);

  const canProducts = has('product.view');
  const canPersonas = has('persona.view');
  const canClaims = has('claim.view');
  const canSources = has('source.view');

  const loadStats = useCallback(() => {
    let cancelled = false;
    setLoading(true);
    Promise.allSettled([
      canProducts ? knowledgeService.listProducts({ limit: 1 }) : Promise.resolve(null),
      canPersonas ? knowledgeService.listPersonas({ limit: 1 }) : Promise.resolve(null),
      canClaims ? knowledgeService.listClaims({ limit: 1 }) : Promise.resolve(null),
      canSources ? knowledgeService.listSources({ limit: 1 }) : Promise.resolve(null),
    ]).then(([p, ps, c, s]) => {
      if (cancelled) return;
      setStats({
        products: totalOrNull(p),
        personas: totalOrNull(ps),
        claims: totalOrNull(c),
        sources: totalOrNull(s),
      });
    }).finally(() => {
      if (!cancelled) setLoading(false);
    });
    return () => { cancelled = true; };
  }, [canProducts, canPersonas, canClaims, canSources]);

  useEffect(() => loadStats(), [loadStats]);

  return (
    <div>
      <div className="page-header">
        <div>
          <h1 style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
            <BookOpen size={22} /> Knowledge Foundation
          </h1>
          <p className="text-sm text-muted">
            Authoritative, approved-only product knowledge that grounds AI-generated content.
          </p>
        </div>
      </div>

      {loading ? <Loader label="Loading stats…" /> : (
        <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fill, minmax(200px, 1fr))', gap: 16 }}>
          {has('product.view') && <StatCard icon={Tag}       label="Products"    count={stats.products} to={ROUTES.KNOWLEDGE_PRODUCTS}   color="#3b82f6" />}
          {has('persona.view') && <StatCard icon={Users}     label="Personas"    count={stats.personas} to={ROUTES.KNOWLEDGE_PERSONAS}   color="#8b5cf6" />}
          {has('claim.view')   && <StatCard icon={ShieldAlert} label="Claims"    count={stats.claims}   to={ROUTES.KNOWLEDGE_CLAIMS}     color="#ef4444" />}
          {has('source.view')  && <StatCard icon={BookOpen}  label="Sources"     count={stats.sources}  to={ROUTES.KNOWLEDGE_SOURCES}    color="#10b981" />}
        </div>
      )}

      <div style={{ marginTop: '2rem', padding: '1rem', background: '#fffbeb', border: '1px solid #fde68a', borderRadius: 8 }}>
        <strong>Phase 1 — Knowledge Foundation</strong>
        <p className="text-sm" style={{ margin: '4px 0 0' }}>
          All knowledge records start as <em>draft</em> and must pass through review before grounding AI content.
          No AI provider calls are made from this module. Records marked <em>planned</em> are never represented as available.
        </p>
      </div>
    </div>
  );
}
