import { AlertCircle, Download } from 'lucide-react';

const UNATTRIBUTED = [
  { id: 1, lead_ref: 'lead-0091', email_hash: 'sha256:a1b2...', conversion_date: '2026-07-14', total_touchpoints: 0 },
  { id: 2, lead_ref: 'lead-0087', email_hash: 'sha256:c3d4...', conversion_date: '2026-07-13', total_touchpoints: 1 },
  { id: 3, lead_ref: 'lead-0083', email_hash: 'sha256:e5f6...', conversion_date: '2026-07-12', total_touchpoints: 0 },
];

export default function UnattributedLeadsPage() {
  return (
    <div className="p-6 space-y-6">
      <div className="flex items-center justify-between">
        <div className="flex items-center gap-3">
          <AlertCircle className="h-7 w-7 text-red-500" />
          <div>
            <h1 className="text-2xl font-bold text-gray-900">Unattributed Leads</h1>
            <p className="text-sm text-gray-500">Conversions with no attributable touchpoint</p>
          </div>
        </div>
        <button className="flex items-center gap-2 px-4 py-2 border border-gray-300 rounded-lg text-sm hover:bg-gray-50">
          <Download className="h-4 w-4" /> Export
        </button>
      </div>
      <div className="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden">
        <table className="w-full text-sm">
          <thead className="bg-gray-50 text-xs text-gray-500 uppercase">
            <tr>
              <th className="px-4 py-3 text-left">Lead Ref</th>
              <th className="px-4 py-3 text-left">Email (hashed)</th>
              <th className="px-4 py-3 text-left">Conversion Date</th>
              <th className="px-4 py-3 text-right">Touchpoints</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-gray-100">
            {UNATTRIBUTED.map(l => (
              <tr key={l.id} className="hover:bg-gray-50">
                <td className="px-4 py-3 font-mono text-xs text-gray-700">{l.lead_ref}</td>
                <td className="px-4 py-3 font-mono text-xs text-gray-500">{l.email_hash}</td>
                <td className="px-4 py-3 text-gray-600">{l.conversion_date}</td>
                <td className="px-4 py-3 text-right text-red-500 font-medium">{l.total_touchpoints}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
      <p className="text-xs text-gray-400">Email addresses are stored as one-way hashes. Raw PII is never retained in the attribution system.</p>
    </div>
  );
}
