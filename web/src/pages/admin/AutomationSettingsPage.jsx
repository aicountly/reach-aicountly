import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { useLocation, useNavigate } from 'react-router-dom';
import {
  Activity, ChevronDown, ChevronsDownUp, ChevronsUpDown, PlugZap, RotateCcw, Save, Search, SearchX, X,
} from 'lucide-react';
import { adminService } from '../../services/adminService';
import { usePermission } from '../../hooks/usePermission';
import { Loader } from '../../components/common/Loader';
import { Alert } from '../../components/common/Alert';
import { SettingField } from './automationSettings/SettingField';
import { LiveCheckReport, SectionLiveChecks, StatusDot } from './automationSettings/LiveChecks';
import {
  buildPayload, buildSections, checkMatters, formatRanAt, isDirty, matchesQuery, payloadJsonError, readCollapsed,
  resolveSectionId, rowIsMissing, sectionStatus, subgroupsOf, writeCollapsed,
} from './automationSettings/model';

const ALL = '__all__';

function plural(n, one, many = `${one}s`) {
  return `${n} ${n === 1 ? one : many}`;
}

function prefersReducedMotion() {
  try {
    return Boolean(window.matchMedia?.('(prefers-reduced-motion: reduce)')?.matches);
  } catch {
    return false;
  }
}

function isTypingTarget(el) {
  if (!el) return false;
  const tag = el.tagName;
  return tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT' || el.isContentEditable;
}

/**
 * Settings → AI & Automation. Secrets are write-only: the API only says
 * whether one is set and where it comes from, and a field left empty is not
 * sent, so saving never touches a key nobody retyped.
 *
 * Layout: an overview strip (keys configured, live checks, what needs
 * attention), a sticky section navigator with search (a rail on wide
 * screens, a chip bar on narrow ones; `#section` deep links), collapsible
 * section cards, and a sticky save bar while anything is unsaved.
 */
export function AutomationSettingsPage() {
  const { has } = usePermission();
  const canManage = has('settings.manage');
  const location = useLocation();
  const navigate = useNavigate();

  const [rows, setRows]               = useState([]);
  const [edits, setEdits]             = useState({});
  const [models, setModels]           = useState([]);
  const [loading, setLoading]         = useState(true);
  const [loadError, setLoadError]     = useState(null);
  const [saving, setSaving]           = useState(null);
  const [toast, setToast]             = useState(null);
  const [tests, setTests]             = useState({});
  const [results, setResults]         = useState({});
  const [livePending, setLivePending] = useState({});
  const [liveErrors, setLiveErrors]   = useState({});
  const [fullReport, setFullReport]   = useState(null);
  const [fullRunning, setFullRunning] = useState(false);
  const [fullFailure, setFullFailure] = useState(null);
  const [query, setQuery]             = useState('');
  const [collapsed, setCollapsed]     = useState(readCollapsed);
  const [activeId, setActiveId]       = useState(null);
  const [pendingJump, setPendingJump] = useState(null);

  const sectionEls  = useRef({});
  const toggleEls   = useRef({});
  const navListRef  = useRef(null);
  const searchRef   = useRef(null);
  const handledHash = useRef(null);
  const spyLock     = useRef(null);

  const loadModels = useCallback(
    () => adminService.geminiModels().then((m) => setModels(Array.isArray(m) ? m : [])).catch(() => setModels([])),
    [],
  );

  useEffect(() => {
    Promise.all([
      adminService.automationSettings().then((d) => setRows(Array.isArray(d) ? d : [])),
      loadModels(),
    ])
      .catch((e) => setLoadError(e.message))
      .finally(() => setLoading(false));
  }, [loadModels]);

  // ── derived ──────────────────────────────────────────────────────────────
  const sections  = useMemo(() => buildSections(rows), [rows]);
  const rowsByKey = useMemo(() => Object.fromEntries(rows.map((r) => [r.key, r])), [rows]);
  const searching = query.trim() !== '';

  const view = useMemo(() => sections.map((s) => ({
    ...s,
    visible: searching ? s.rows.filter((r) => matchesQuery(r, query)) : s.rows,
    dirtyKeys: s.rows.filter((r) => isDirty(r, edits[r.key])).map((r) => r.key),
    missing: s.rows.filter((r) => rowIsMissing(r, rowsByKey)).length,
    status: sectionStatus(s, rowsByKey, results),
  })), [sections, searching, query, edits, rowsByKey, results]);

  const shown      = view.filter((s) => s.visible.length > 0);
  const shownIds   = shown.map((s) => s.id).join(' ');
  const matchTotal = shown.reduce((n, s) => n + s.visible.length, 0);
  const dirtyView  = view.filter((s) => s.dirtyKeys.length > 0);
  const dirtyTotal = dirtyView.reduce((n, s) => n + s.dirtyKeys.length, 0);
  const attention  = view.filter((s) => s.status.level !== 'ok');

  const secrets        = rows.filter((r) => r.type === 'secret');
  const secretsSet     = secrets.filter((r) => r.is_set).length;
  const requiredMissed = secrets.filter((r) => rowIsMissing(r, rowsByKey)).length;

  const checkList = Object.values(results);
  const checksOk  = checkList.filter((r) => r.ok).length;
  const checksBad = checkList.length - checksOk;
  const lastRan   = checkList.map((r) => r.ran_at).filter(Boolean).sort().at(-1) ?? null;

  // ── navigation: jump, deep links, scroll-spy ─────────────────────────────
  const latest = useRef({ view, searching });
  latest.current = { view, searching };

  const jumpTo = useCallback((id, { smooth = true } = {}) => {
    const { view: v, searching: s } = latest.current;
    const target = v.find((x) => x.id === id);
    if (!target) return;
    if (s && target.visible.length === 0) setQuery('');
    setCollapsed((prev) => {
      if (!prev.has(id)) return prev;
      const next = new Set(prev);
      next.delete(id);
      return next;
    });
    setActiveId(id);
    spyLock.current = id;
    setPendingJump({ id, smooth });
  }, []);

  const goTo = (e, id) => {
    e.preventDefault();
    handledHash.current = id;
    navigate({ pathname: location.pathname, search: location.search, hash: `#${id}` }, { replace: true, preventScrollReset: true });
    jumpTo(id);
  };

  // `/admin/ai-automation#social` (from a link, a bookmark or another page).
  useEffect(() => {
    if (loading) return;
    const raw = location.hash.replace(/^#/, '');
    if (!raw || raw === handledHash.current) return;
    const first = handledHash.current === null;
    handledHash.current = raw;
    const id = resolveSectionId(raw, sections);
    if (id) jumpTo(id, { smooth: !first });
  }, [loading, location.hash, sections, jumpTo]);

  // After the DOM has the (expanded, unfiltered) target: move focus to its heading and scroll.
  useEffect(() => {
    if (!pendingJump) return;
    const el = sectionEls.current[pendingJump.id];
    if (!el) return;
    spyLock.current = pendingJump.id;
    toggleEls.current[pendingJump.id]?.focus?.({ preventScroll: true });
    if (typeof el.scrollIntoView === 'function') {
      el.scrollIntoView({ behavior: pendingJump.smooth && !prefersReducedMotion() ? 'smooth' : 'auto', block: 'start' });
    }
  }, [pendingJump]);

  // Scroll-spy: the active section is the last one whose top has passed the sticky bars.
  useEffect(() => {
    if (loading) return undefined;
    const ids = shownIds ? shownIds.split(' ') : [];
    let frame = 0;
    const compute = () => {
      frame = 0;
      if (spyLock.current) return;
      let current = ids[0] ?? null;
      for (const id of ids) {
        const el = sectionEls.current[id];
        if (!el) continue;
        const rect = el.getBoundingClientRect();
        if (rect.height === 0 && rect.top === 0) continue; // not laid out
        const margin = parseFloat(window.getComputedStyle(el).scrollMarginTop) || 80;
        if (rect.top - margin <= 8) current = id;
        else break;
      }
      const doc = document.documentElement;
      if (ids.length && window.innerHeight + window.scrollY >= doc.scrollHeight - 4 && doc.scrollHeight > window.innerHeight) {
        current = ids[ids.length - 1];
      }
      if (current) setActiveId(current);
    };
    const onScroll = () => {
      if (!frame) frame = window.requestAnimationFrame(compute);
    };
    // A jump keeps its section highlighted until the person scrolls themselves.
    const release = () => { spyLock.current = null; };
    const userInput = ['wheel', 'touchstart', 'mousedown', 'keydown'];
    window.addEventListener('scroll', onScroll, { passive: true });
    window.addEventListener('resize', onScroll);
    userInput.forEach((t) => window.addEventListener(t, release, { passive: true }));
    onScroll();
    return () => {
      window.removeEventListener('scroll', onScroll);
      window.removeEventListener('resize', onScroll);
      userInput.forEach((t) => window.removeEventListener(t, release));
      if (frame) window.cancelAnimationFrame(frame);
    };
  }, [loading, shownIds]);

  // Keep the active chip visible when the navigator is a horizontal bar.
  useEffect(() => {
    const bar = navListRef.current;
    if (!bar || bar.scrollWidth <= bar.clientWidth) return;
    const chip = bar.querySelector('[aria-current="location"]');
    if (!chip) return;
    const left = chip.offsetLeft - (bar.clientWidth - chip.offsetWidth) / 2;
    if (typeof bar.scrollTo === 'function') bar.scrollTo({ left, behavior: prefersReducedMotion() ? 'auto' : 'smooth' });
  }, [activeId]);

  // "/" focuses the search box.
  useEffect(() => {
    const onKey = (e) => {
      if (e.key !== '/' || e.metaKey || e.ctrlKey || e.altKey || isTypingTarget(e.target)) return;
      e.preventDefault();
      searchRef.current?.focus();
    };
    window.addEventListener('keydown', onKey);
    return () => window.removeEventListener('keydown', onKey);
  }, []);

  useEffect(() => { writeCollapsed(collapsed); }, [collapsed]);

  const hasUnsaved = dirtyTotal > 0;
  useEffect(() => {
    if (!hasUnsaved) return undefined;
    const warn = (e) => { e.preventDefault(); e.returnValue = ''; return ''; };
    window.addEventListener('beforeunload', warn);
    return () => window.removeEventListener('beforeunload', warn);
  }, [hasUnsaved]);

  useEffect(() => {
    if (!toast || toast.variant === 'danger') return undefined;
    const t = window.setTimeout(() => setToast(null), 6000);
    return () => window.clearTimeout(t);
  }, [toast]);

  // ── editing & saving ─────────────────────────────────────────────────────
  const setEdit = useCallback((key, value) => setEdits((prev) => ({ ...prev, [key]: value })), []);

  const discard = (scopeRows) => {
    const keys = new Set(scopeRows.map((r) => r.key));
    setEdits((prev) => Object.fromEntries(Object.entries(prev).filter(([k]) => !keys.has(k))));
  };

  /** One PUT of the real changes in `scopeRows` (a section, or every row for the save bar). */
  const save = async (scopeRows, scopeName, override = null) => {
    const body = override ?? buildPayload(scopeRows, edits);
    if (!override) {
      const why = payloadJsonError(scopeRows, body);
      if (why) { setToast({ variant: 'danger', text: why }); return; }
    }
    const count = Object.keys(body).length;
    if (count === 0) {
      setToast({ variant: 'info', text: scopeName ? `Nothing changed in ${scopeName}.` : 'Nothing to save.' });
      return;
    }
    setSaving(scopeName ?? ALL);
    setToast(null);
    try {
      const d = await adminService.updateAutomationSettings(body);
      if (Array.isArray(d)) setRows(d);
      setEdits((prev) => Object.fromEntries(Object.entries(prev).filter(([k]) => !(k in body))));
      setToast({ variant: 'success', text: scopeName ? `${scopeName} saved.` : `Saved ${plural(count, 'change')}.` });
      if (Object.keys(body).some((k) => k.startsWith('ai.gemini'))) loadModels();
    } catch (e) {
      setToast({ variant: 'danger', text: e.message });
    } finally {
      setSaving(null);
    }
  };

  const clearSecret = (section, row) => {
    if (!window.confirm(`Clear the saved ${row.label}? The server .env value (if any) will be used instead.`)) return;
    save(section.rows, section.name, { [row.key]: '' });
  };

  // ── checks ───────────────────────────────────────────────────────────────
  const mergeReport = (report) => setResults((prev) => {
    const next = { ...prev };
    for (const r of report?.results ?? []) next[r.target] = { ...r, ran_at: report.ran_at ?? null };
    return next;
  });

  const runTest = async (target) => {
    setTests((prev) => ({ ...prev, [target]: { pending: true } }));
    try {
      const r = await adminService.testAutomationProvider(target);
      setTests((prev) => ({ ...prev, [target]: r }));
    } catch (e) {
      setTests((prev) => ({ ...prev, [target]: { ok: false, message: e.message } }));
    }
  };

  const markTargets = (setter, targets, value) => setter((prev) => {
    const next = { ...prev };
    for (const t of targets) next[t] = value;
    return next;
  });

  /** Live checks for some targets (a section's button). */
  const runLive = async (targets) => {
    markTargets(setLivePending, targets, true);
    markTargets(setLiveErrors, targets, null);
    try {
      mergeReport(await adminService.runLiveChecks(targets));
    } catch (e) {
      markTargets(setLiveErrors, targets, e.message);
    } finally {
      markTargets(setLivePending, targets, false);
    }
  };

  /** "Run live checks": every integration. */
  const runAll = async () => {
    setFullRunning(true);
    setFullFailure(null);
    try {
      const report = await adminService.runLiveChecks();
      setFullReport(report);
      mergeReport(report);
    } catch (e) {
      setFullFailure(e.message);
    } finally {
      setFullRunning(false);
    }
  };

  const toggleSection = (id) => {
    if (searching) return;
    setCollapsed((prev) => {
      const next = new Set(prev);
      if (next.has(id)) next.delete(id); else next.add(id);
      return next;
    });
  };

  if (loading) return <Loader />;

  const current = activeId ?? shown[0]?.id ?? null;

  return (
    <div className={`as-page${hasUnsaved ? ' as-page--dirty' : ''}`}>
      <div className="page-header">
        <div>
          <h1>AI &amp; Automation</h1>
          <p className="page-header__subtitle">
            Keys, the model that vets and finalises content, image generation, daily volumes and reports.
            The vetting model&apos;s decision is final — approved content is published without further review.
          </p>
        </div>
      </div>

      {loadError && <Alert variant="danger">{loadError}</Alert>}

      {/* ── Overview ── */}
      <section className="as-summary" aria-labelledby="as-summary-title" data-testid="automation-summary">
        <h2 id="as-summary-title" className="as-sr-only">Overview</h2>
        <div className="as-tile">
          <div className="as-tile__label">Provider keys</div>
          <div className="as-tile__value" data-testid="summary-keys">{secretsSet}/{secrets.length}</div>
          <div className="as-tile__hint">
            configured{requiredMissed > 0 ? ` · ${requiredMissed} needed ${requiredMissed === 1 ? 'is' : 'are'} missing` : ''}
          </div>
        </div>

        {canManage && (
          <div className="as-tile as-tile--live">
            <div className="as-tile__label">Live checks</div>
            <div className={`as-tile__value${checksBad ? ' text-danger' : ''}`} data-testid="summary-checks">
              {checkList.length ? `${checksOk} OK · ${checksBad} FAIL` : 'Not run yet'}
            </div>
            <div className="as-tile__hint">
              {lastRan ? `Last run ${formatRanAt(lastRan)}` : 'One cheap authenticated request per integration.'}
            </div>
            <div className="as-tile__actions">
              <button type="button" className="btn btn-primary btn-sm" onClick={runAll} disabled={fullRunning}>
                <Activity size={14} aria-hidden="true" /> {fullRunning ? 'Running live checks… (up to a minute)' : 'Run live checks'}
              </button>
            </div>
            <details className="as-tile__about">
              <summary>What does this check?</summary>
              <p>
                Calls Shotstack, YouTube, DataForSEO, Gemini, OpenAI, OpenAI TTS and social-api.ai with the credentials saved here (or in the server .env):
                one cheap authenticated request each, plus a one-second voice sample. Nothing is uploaded, rendered or posted.
                For a Shotstack test render run <code>php spark reach:integrations-check --render-test</code> on the server.
              </p>
            </details>
          </div>
        )}

        <div className="as-tile as-tile--attention">
          <div className="as-tile__label">Needs attention</div>
          {attention.length > 0 ? (
            <ul className="as-quicklinks">
              {attention.map((s) => (
                <li key={s.id}>
                  <a href={`#${s.id}`} onClick={(e) => goTo(e, s.id)} className={`as-quicklink as-quicklink--${s.status.level}`} title={s.status.reasons.join('; ')}>
                    <StatusDot status={s.status} />
                    <span>{s.name}</span>
                    <span className="as-quicklink__why">{s.status.reasons[0]}{s.status.reasons.length > 1 ? ` +${s.status.reasons.length - 1}` : ''}</span>
                  </a>
                </li>
              ))}
            </ul>
          ) : (
            <p className="as-tile__hint">Everything needed is set{checkList.length ? ' and the live checks pass' : ''}.</p>
          )}
        </div>
      </section>

      {canManage && fullFailure && <Alert variant="danger">{fullFailure}</Alert>}
      {canManage && fullReport && (
        <details className="card as-report" open>
          <summary className="as-report__summary">Live check results (all integrations)</summary>
          <div className="as-report__body">
            <LiveCheckReport report={fullReport} />
          </div>
        </details>
      )}

      <div className="as-body">
        <div className="as-layout">
          {/* ── Section navigator ── */}
          <nav className="as-nav" aria-label="Settings sections">
            <div className="as-nav__search">
              <Search size={15} aria-hidden="true" className="as-nav__search-icon" />
              <input
                ref={searchRef}
                type="search"
                aria-label="Search settings"
                placeholder="Search settings…"
                value={query}
                onChange={(e) => setQuery(e.target.value)}
                onKeyDown={(e) => { if (e.key === 'Escape') setQuery(''); }}
                data-testid="settings-search"
              />
              {searching ? (
                <button type="button" className="as-nav__clear" onClick={() => { setQuery(''); searchRef.current?.focus(); }} aria-label="Clear search">
                  <X size={14} aria-hidden="true" />
                </button>
              ) : <kbd className="as-kbd" aria-hidden="true">/</kbd>}
            </div>
            <ul className="as-nav__list" ref={navListRef}>
              {view.map((s) => {
                const count = searching ? s.visible.length : s.rows.length;
                const active = current === s.id;
                const Icon = s.icon;
                return (
                  <li key={s.id}>
                    <a
                      href={`#${s.id}`}
                      onClick={(e) => goTo(e, s.id)}
                      className={`as-nav__item${active ? ' as-nav__item--active' : ''}${searching && count === 0 ? ' as-nav__item--empty' : ''}`}
                      aria-current={active ? 'location' : undefined}
                      data-testid={`nav-${s.id}`}
                    >
                      <Icon size={15} aria-hidden="true" className="as-nav__icon" />
                      <span className="as-nav__label">{s.name}</span>
                      {s.dirtyKeys.length > 0 && (
                        <span className="as-nav__dirty" title="Unsaved changes"><span className="as-sr-only">(unsaved changes)</span></span>
                      )}
                      <span className="as-nav__count">{count}</span>
                      <StatusDot status={s.status} />
                    </a>
                  </li>
                );
              })}
            </ul>
            <div className="as-nav__tools">
              <button type="button" className="btn btn-secondary btn-sm" onClick={() => setCollapsed(new Set())} aria-label="Expand all sections" title="Expand all sections">
                <ChevronsUpDown size={13} aria-hidden="true" /> <span className="as-nav__tool-text">Expand all</span>
              </button>
              <button type="button" className="btn btn-secondary btn-sm" onClick={() => setCollapsed(new Set(sections.map((s) => s.id)))} aria-label="Collapse all sections" title="Collapse all sections">
                <ChevronsDownUp size={13} aria-hidden="true" /> <span className="as-nav__tool-text">Collapse all</span>
              </button>
            </div>
            <p className="as-nav__legend" aria-hidden="true">
              <span className="as-dot as-dot--ok" /> set <span className="as-dot as-dot--warning" /> missing <span className="as-dot as-dot--error" /> check failed
            </p>
          </nav>

          {/* ── Sections ── */}
          <div className="as-content">
            {searching && (
              <p className="as-search-summary" role="status">
                {matchTotal > 0
                  ? `${plural(matchTotal, 'setting')} in ${plural(shown.length, 'section')} match “${query.trim()}”.`
                  : ''}
              </p>
            )}
            {searching && matchTotal === 0 && (
              <div className="as-empty" data-testid="search-empty">
                <SearchX size={28} aria-hidden="true" />
                <p>No settings match “{query.trim()}”.</p>
                <p className="text-sm text-muted">Search looks at each setting&apos;s label, key and help text.</p>
                <button type="button" className="btn btn-secondary btn-sm" onClick={() => setQuery('')}>Clear search</button>
              </div>
            )}

            {shown.map((s) => {
              const open = searching || !collapsed.has(s.id);
              const Icon = s.icon;
              const sectionDirty = s.dirtyKeys.length;
              const optionalChecks = s.checks.filter((t) => !checkMatters(t, rowsByKey));
              const busy = saving === s.name;
              return (
                <section
                  key={s.id}
                  id={s.id}
                  ref={(el) => { sectionEls.current[s.id] = el; }}
                  className={`card as-section as-section--${s.status.level}`}
                  aria-labelledby={`${s.id}-title`}
                  data-testid={`section-${s.id}`}
                >
                  <div className="as-section__header">
                    <h2 className="as-section__title" id={`${s.id}-title`}>
                      <button
                        type="button"
                        ref={(el) => { toggleEls.current[s.id] = el; }}
                        className="as-section__toggle"
                        aria-expanded={open}
                        aria-controls={`${s.id}-body`}
                        aria-disabled={searching ? 'true' : undefined}
                        title={searching ? 'Clear the search to collapse sections' : (open ? 'Collapse section' : 'Expand section')}
                        onClick={() => toggleSection(s.id)}
                      >
                        <ChevronDown size={16} aria-hidden="true" className={`as-section__chev${open ? '' : ' as-section__chev--closed'}`} />
                        <Icon size={17} aria-hidden="true" className="as-section__icon" />
                        <span>{s.name}</span>
                      </button>
                    </h2>
                    <div className="as-section__badges">
                      <StatusDot status={s.status} />
                      <span className="badge badge--muted as-badge">
                        {searching ? `${s.visible.length} of ${s.rows.length} match` : plural(s.rows.length, 'setting')}
                      </span>
                      {s.missing > 0 && <span className="badge badge--warning as-badge">{s.missing} missing</span>}
                      {sectionDirty > 0 && (
                        <span className="badge badge--warning as-badge" data-testid={`dirty-${s.id}`}>{sectionDirty} unsaved</span>
                      )}
                    </div>
                  </div>
                  {s.description && <p className="as-section__desc">{s.description}</p>}
                  <SectionLiveChecks targets={s.checks} results={results} pending={livePending} errors={liveErrors} optional={optionalChecks} />

                  <div id={`${s.id}-body`} className="as-section__body" hidden={!open}>
                    {subgroupsOf(s, s.visible).map((g) => (
                      <div key={g.id} className={`as-subgroup${g.title ? '' : ' as-subgroup--plain'}`} data-testid={`subgroup-${s.id}-${g.id}`}>
                        {g.title && <h3 className="as-subgroup__title">{g.title}</h3>}
                        {g.description && <p className="as-subgroup__desc">{g.description}</p>}
                        <div className={`as-fields${g.compact ? ' as-fields--compact' : ''}`}>
                          {g.rows.map((row) => (
                            <SettingField
                              key={row.key}
                              row={row}
                              edit={edits[row.key]}
                              dirty={isDirty(row, edits[row.key])}
                              missing={rowIsMissing(row, rowsByKey)}
                              models={models}
                              onChange={setEdit}
                              onClearSecret={(r) => clearSecret(s, r)}
                              wide={g.pairs ? false : undefined}
                            />
                          ))}
                        </div>
                        {g.liveCheck && (
                          <div className="as-subgroup__actions">
                            <button
                              type="button"
                              className="btn btn-secondary btn-sm"
                              onClick={() => runLive(g.liveCheck.targets)}
                              disabled={g.liveCheck.targets.some((t) => livePending[t])}
                            >
                              <Activity size={14} aria-hidden="true" /> {g.liveCheck.targets.some((t) => livePending[t]) ? 'Checking…' : g.liveCheck.label}
                            </button>
                          </div>
                        )}
                      </div>
                    ))}

                    <div className="as-section__footer">
                      {s.tests.map((target) => (
                        <button key={target} type="button" className="btn btn-secondary btn-sm" onClick={() => runTest(target)} disabled={tests[target]?.pending}>
                          <PlugZap size={14} aria-hidden="true" /> Test {target}
                        </button>
                      ))}
                      {canManage && s.liveChecks.map(({ targets, label }) => (
                        <button
                          key={label}
                          type="button"
                          className="btn btn-secondary btn-sm"
                          onClick={() => runLive(targets)}
                          disabled={targets.some((t) => livePending[t])}
                        >
                          <Activity size={14} aria-hidden="true" /> {targets.some((t) => livePending[t]) ? 'Checking…' : label}
                        </button>
                      ))}
                      <span className="as-section__spacer" />
                      {sectionDirty > 0 && (
                        <button type="button" className="btn btn-secondary btn-sm" onClick={() => discard(s.rows)}>
                          <RotateCcw size={14} aria-hidden="true" /> Discard changes
                        </button>
                      )}
                      <button type="button" className="btn btn-primary btn-sm" onClick={() => save(s.rows, s.name)} disabled={busy}>
                        <Save size={14} aria-hidden="true" /> {busy ? 'Saving…' : `Save ${s.name}`}
                      </button>
                    </div>
                    {s.tests.map((target) => tests[target] && !tests[target].pending && (
                      <Alert key={target} variant={tests[target].ok ? 'success' : 'danger'}>
                        {target}: {tests[target].message}
                      </Alert>
                    ))}
                  </div>
                </section>
              );
            })}
          </div>
        </div>
      </div>

      {hasUnsaved && (
        <div className="as-savebar" role="region" aria-label="Unsaved changes" data-testid="save-bar">
          <div className="as-savebar__text">
            <strong>{plural(dirtyTotal, 'unsaved change')}</strong>
            <span className="as-savebar__where">
              {' in '}
              {dirtyView.map((s, i) => (
                <span key={s.id}>
                  {i > 0 ? ', ' : ''}
                  <a href={`#${s.id}`} onClick={(e) => goTo(e, s.id)}>{s.name}</a>
                </span>
              ))}
            </span>
          </div>
          <div className="as-savebar__actions">
            <button type="button" className="btn btn-secondary btn-sm" onClick={() => discard(rows)} disabled={saving !== null}>
              <RotateCcw size={14} aria-hidden="true" /> Discard
            </button>
            <button type="button" className="btn btn-primary btn-sm" onClick={() => save(rows, null)} disabled={saving !== null}>
              <Save size={14} aria-hidden="true" /> {saving === ALL ? 'Saving…' : 'Save all changes'}
            </button>
          </div>
        </div>
      )}

      <div className="as-toast-region" aria-live="polite">
        {toast && (
          <div className={`as-toast as-toast--${toast.variant}`} role={toast.variant === 'danger' ? 'alert' : 'status'} data-testid="settings-toast">
            <span>{toast.text}</span>
            <button type="button" className="as-toast__close" onClick={() => setToast(null)} aria-label="Dismiss message">
              <X size={14} aria-hidden="true" />
            </button>
          </div>
        )}
      </div>
    </div>
  );
}
