import { Eraser, ExternalLink, Wand2 } from 'lucide-react';
import { jsonObjectError } from '../../../utils/jsonObject';
import { displayValue, formatJson, networkOf } from './model';

const SOURCE_LABEL = { settings: 'saved here', env: 'from server .env', default: 'default', unset: 'not set' };
const SOURCE_TONE = { settings: 'primary', env: 'info', default: 'muted', unset: 'muted' };

const CHOICE_LABEL = {
  social_api: 'social_api — through social-api.ai',
  direct: 'direct — Reach’s own Google OAuth upload',
};

function SourceBadge({ row, missing }) {
  if (row.type === 'secret') {
    if (row.is_set) {
      return <span className="badge badge--success as-badge">set ({SOURCE_LABEL[row.source] ?? row.source})</span>;
    }
    return <span className={`badge ${missing ? 'badge--warning' : 'badge--muted'} as-badge`}>not set</span>;
  }
  const tone = row.source === 'unset' && missing ? 'warning' : (SOURCE_TONE[row.source] ?? 'muted');
  return (
    <span className={`badge badge--${tone} as-badge`} title="Where the current value comes from">
      {SOURCE_LABEL[row.source] ?? row.source}
    </span>
  );
}

/**
 * One setting. Controlled by the page: `edit` is undefined until somebody
 * changes the field. A secret input always starts empty — the API never
 * returns a secret, so there is nothing to show or send back.
 */
export function SettingField({ row, edit, dirty, missing, models, onChange, onClearSecret, wide: wideProp }) {
  const id = `setting-${row.key}`;
  const helpId = row.help ? `${id}-help` : undefined;
  const value = edit ?? displayValue(row);
  const set = (v) => onChange(row.key, v);
  const wide = wideProp ?? (row.type === 'json_object' || row.type === 'email_list');
  const jsonError = row.type === 'json_object' && edit !== undefined ? jsonObjectError(edit) : null;
  const classes = ['as-field', `as-field--${row.type}`];
  if (wide) classes.push('as-field--wide');
  if (dirty) classes.push('as-field--dirty');

  const head = (
    <div className="as-field__head">
      {row.type === 'bool' ? (
        <input
          id={id}
          type="checkbox"
          className="as-field__check"
          checked={edit !== undefined ? Boolean(edit) : displayValue(row)}
          onChange={(e) => set(e.target.checked)}
          aria-describedby={helpId}
        />
      ) : null}
      <label className="as-field__label" htmlFor={id} id={`${id}-label`}>{row.label}</label>
      <SourceBadge row={row} missing={missing} />
      {dirty && <span className="badge badge--warning as-badge">edited</span>}
    </div>
  );

  let control = null;
  if (row.type === 'secret') {
    control = (
      <input
        id={id}
        type="password"
        autoComplete="new-password"
        placeholder={row.is_set ? 'Leave blank to keep the current key' : 'Paste key'}
        value={edit ?? ''}
        onChange={(e) => set(e.target.value)}
        className="settings-row__input"
        aria-describedby={helpId}
      />
    );
  } else if (row.type === 'int') {
    control = (
      <input id={id} type="number" min="0" value={value} onChange={(e) => set(e.target.value)} className="settings-row__input" aria-describedby={helpId} />
    );
  } else if (row.type === 'model') {
    control = (
      <>
        <input
          id={id}
          list={`${id}-models`}
          placeholder={row.key === 'ai.vetting.model.default' ? 'e.g. gemini-2.5-flash' : 'Blank = default vetting model'}
          value={value}
          onChange={(e) => set(e.target.value)}
          className="settings-row__input"
          spellCheck={false}
          aria-describedby={helpId}
        />
        <datalist id={`${id}-models`}>
          {models.map((m) => <option key={m.id} value={m.id}>{m.label}</option>)}
        </datalist>
      </>
    );
  } else if (row.type === 'choice') {
    control = (
      <select id={id} value={value} onChange={(e) => set(e.target.value)} className="settings-row__input" aria-describedby={helpId}>
        {(row.options ?? []).map((o) => <option key={o} value={o}>{CHOICE_LABEL[o] ?? o}</option>)}
      </select>
    );
  } else if (row.type === 'json_object') {
    const lines = String(value).split('\n').length;
    const pretty = formatJson(value);
    control = (
      <>
        <textarea
          id={id}
          rows={Math.min(12, Math.max(3, lines))}
          placeholder='Blank = none. e.g. {"key": "value"} — keys from docs.social-api.ai'
          value={value}
          onChange={(e) => set(e.target.value)}
          className="settings-row__input settings-row__input--json"
          spellCheck={false}
          aria-invalid={jsonError ? 'true' : undefined}
          aria-describedby={helpId}
        />
        {jsonError && <span className="text-sm text-error" role="alert">{jsonError}</span>}
        <div className="as-field__tools">
          <button
            type="button"
            className="btn btn-secondary btn-sm"
            onClick={() => pretty !== null && set(pretty)}
            disabled={pretty === null || pretty === value}
            aria-describedby={`${id}-label`}
          >
            <Wand2 size={13} aria-hidden="true" /> Format JSON
          </button>
          {row.key.startsWith('social.socialapi.platform_data.') && (
            <a className="as-field__doc" href="https://docs.social-api.ai" target="_blank" rel="noopener noreferrer">
              docs.social-api.ai → Platforms → {networkOf(row.key)} <ExternalLink size={12} aria-hidden="true" />
            </a>
          )}
        </div>
      </>
    );
  } else if (row.type !== 'bool') {
    control = (
      <input id={id} value={value} onChange={(e) => set(e.target.value)} className="settings-row__input" spellCheck={false} aria-describedby={helpId} />
    );
  }

  return (
    <div className={classes.join(' ')} data-testid={`setting-${row.key}`}>
      {head}
      {control}
      {row.help && <p className="as-field__help" id={helpId}>{row.help}</p>}
      <div className="as-field__meta">
        <code className="as-field__key">{row.key}</code>
        {row.type === 'secret' && row.is_set && row.source === 'settings' && (
          <button type="button" className="btn btn-secondary btn-sm" onClick={() => onClearSecret(row)}>
            <Eraser size={13} aria-hidden="true" /> Clear saved key
          </button>
        )}
      </div>
    </div>
  );
}
