import { ReachLogo } from '../components/brand/ReachLogo';
import {
  GATE_CONSOLE_REQUIRED,
  GATE_ERROR,
  GATE_NO_ACCESS,
  useAuth,
} from '../context/AuthContext';
import { consoleLoginUrl } from '../services/consoleAuth';

const APP_NAME = import.meta.env.VITE_APP_NAME || 'AICOUNTLY Reach';

export default function ControllerGate() {
  const { gateReason, gateMessage, retryAuth, ssoPending } = useAuth();

  const reason = gateReason || GATE_CONSOLE_REQUIRED;
  const isPending = ssoPending;

  return (
    <div style={{
      minHeight: '100vh',
      display: 'flex',
      alignItems: 'center',
      justifyContent: 'center',
      padding: 16,
      background: 'linear-gradient(135deg, #ecfdf5 0%, #f0fdf4 100%)',
    }}>
      <div style={{ width: 420, maxWidth: '100%' }}>
        <div style={{ marginBottom: 24, textAlign: 'center' }}>
          <ReachLogo height={44} />
          <div style={{ marginTop: 8, fontSize: '0.875rem', fontWeight: 600, color: 'var(--color-text)' }}>
            {APP_NAME}
          </div>
          <div className="text-xs text-muted">reach.aicountly.org · Console identity only</div>
        </div>

        <div className="card" style={{ padding: '1.25rem 1.5rem' }}>
          {isPending ? (
            <>
              <h1 style={{ fontSize: '1.125rem', fontWeight: 600 }}>Signing you in…</h1>
              <p className="text-sm text-muted mt-2">
                Checking your Console session and controller access.
              </p>
            </>
          ) : reason === GATE_NO_ACCESS ? (
            <>
              <h1 style={{ fontSize: '1.125rem', fontWeight: 600, color: 'var(--color-warning)' }}>
                Access not assigned
              </h1>
              <p className="text-sm text-muted mt-2">
                You are signed in to Console, but this account does not have access to the Reach controller app.
              </p>
              {gateMessage ? (
                <div className="alert alert-warning mt-3" style={{ fontSize: '0.75rem' }}>{gateMessage}</div>
              ) : null}
              <p className="text-xs text-muted mt-3">
                Ask a Console administrator to grant Reach access under Controller App Access, then click Retry.
              </p>
            </>
          ) : reason === GATE_ERROR ? (
            <>
              <h1 style={{ fontSize: '1.125rem', fontWeight: 600, color: 'var(--color-danger)' }}>
                Sign-in failed
              </h1>
              <p className="text-sm text-muted mt-2">
                {gateMessage || 'Could not complete Console sign-in for Reach Portal.'}
              </p>
            </>
          ) : (
            <>
              <h1 style={{ fontSize: '1.125rem', fontWeight: 600 }}>Sign in via Console</h1>
              <p className="text-sm text-muted mt-2">
                This portal does not use a local email/password login. Sign in at{' '}
                <strong>console.aicountly.org</strong>, then open Reach from Top Controller Apps or return here.
              </p>
              {gateMessage && gateMessage !== 'Sign in to Console first.' ? (
                <div className="alert alert-info mt-3" style={{ fontSize: '0.75rem' }}>{gateMessage}</div>
              ) : null}
            </>
          )}

          <div style={{ marginTop: 20, display: 'flex', flexDirection: 'column', gap: 8 }}>
            {reason === GATE_CONSOLE_REQUIRED ? (
              <a
                href={consoleLoginUrl()}
                className="btn btn-primary"
                style={{ justifyContent: 'center', textAlign: 'center' }}
              >
                Open Console sign-in
              </a>
            ) : null}
            <button
              type="button"
              className="btn btn-secondary"
              style={{ justifyContent: 'center' }}
              onClick={() => retryAuth()}
              disabled={isPending}
            >
              {isPending ? 'Checking…' : 'Retry'}
            </button>
          </div>
        </div>

        <p className="text-xs text-muted text-center mt-4">
          Marketing operations · superadmin only
        </p>
      </div>
    </div>
  );
}
