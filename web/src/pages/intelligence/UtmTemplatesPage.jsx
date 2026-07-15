import { useState } from 'react';
import { Tag, Plus, CheckCircle } from 'lucide-react';

const SAMPLE_TEMPLATES = [
  { id: 1, name: 'Blog organic', utm_source: 'google', utm_medium: 'organic', utm_campaign_template: '{campaign_name}', is_active: true },
  { id: 2, name: 'Email newsletter', utm_source: 'newsletter', utm_medium: 'email', utm_campaign_template: 'newsletter-{month}', is_active: true },
  { id: 3, name: 'LinkedIn posts', utm_source: 'linkedin', utm_medium: 'social', utm_campaign_template: '{campaign_id}-linkedin', is_active: true },
  { id: 4, name: 'WhatsApp broadcast', utm_source: 'whatsapp', utm_medium: 'messaging', utm_campaign_template: 'wa-{campaign_id}', is_active: false },
];

export default function UtmTemplatesPage() {
  const [showForm, setShowForm] = useState(false);

  return (
    <div className="p-6 space-y-6">
      <div className="flex items-center justify-between">
        <div className="flex items-center gap-3">
          <Tag className="h-7 w-7 text-indigo-600" />
          <div>
            <h1 className="text-2xl font-bold text-gray-900">UTM Templates</h1>
            <p className="text-sm text-gray-500">Governed UTM parameter templates for attribution tracking</p>
          </div>
        </div>
        <button
          onClick={() => setShowForm(v => !v)}
          className="flex items-center gap-2 px-4 py-2 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700 text-sm"
        >
          <Plus className="h-4 w-4" /> New Template
        </button>
      </div>

      <div className="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden">
        <table className="w-full text-sm">
          <thead className="bg-gray-50 text-xs text-gray-500 uppercase">
            <tr>
              <th className="px-4 py-3 text-left">Name</th>
              <th className="px-4 py-3 text-left">Source</th>
              <th className="px-4 py-3 text-left">Medium</th>
              <th className="px-4 py-3 text-left">Campaign Template</th>
              <th className="px-4 py-3 text-center">Active</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-gray-100">
            {SAMPLE_TEMPLATES.map(t => (
              <tr key={t.id} className="hover:bg-gray-50">
                <td className="px-4 py-3 font-medium text-gray-800">{t.name}</td>
                <td className="px-4 py-3 text-gray-600">{t.utm_source}</td>
                <td className="px-4 py-3 text-gray-600">{t.utm_medium}</td>
                <td className="px-4 py-3 font-mono text-xs text-gray-500">{t.utm_campaign_template}</td>
                <td className="px-4 py-3 text-center">
                  {t.is_active
                    ? <CheckCircle className="h-4 w-4 text-green-500 mx-auto" />
                    : <span className="text-gray-300 text-xs">—</span>}
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </div>
  );
}
