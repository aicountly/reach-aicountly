import { useEffect, useState } from 'react';
import { Bot, ShieldCheck } from 'lucide-react';
import { botService } from '../../services/botService';

export function BotModeBadge({ mode: modeProp }) {
  const [mode, setMode] = useState(modeProp || null);
  useEffect(() => {
    if (modeProp) { setMode(modeProp); return; }
    let cancelled = false;
    botService.getSettings()
      .then((d) => { if (!cancelled) setMode(d?.mode || 'confirm'); })
      .catch(() => { if (!cancelled) setMode('confirm'); });
    return () => { cancelled = true; };
  }, [modeProp]);

  if (!mode) return null;
  const isAuto = mode === 'auto';
  const Icon = isAuto ? Bot : ShieldCheck;
  const label = isAuto ? 'Auto' : 'Confirm';
  const cls = isAuto ? 'bot-mode-badge bot-mode-badge--auto' : 'bot-mode-badge bot-mode-badge--confirm';
  return (
    <span className={cls} title={`Reach Bot mode: ${label}`}>
      <Icon size={12} /> Bot: {label}
    </span>
  );
}
