import { FileText, TrendingUp, TrendingDown } from 'lucide-react';

const SAMPLE_PAGES = [
  { url: '/blog/accounting-software-guide', clicks: 892, impressions: 18400, position: 2.1 },
  { url: '/blog/bookkeeping-tips', clicks: 634, impressions: 12800, position: 3.8 },
  { url: '/features/invoicing', clicks: 412, impressions: 5600, position: 4.2 },
  { url: '/pricing', clicks: 387, impressions: 2100, position: 1.4 },
  { url: '/blog/gst-guide-india', clicks: 298, impressions: 7200, position: 5.7 },
];

export default function SearchPagePerformancePage() {
  return (
    <div className="p-6 space-y-6">
      <div className="flex items-center gap-3">
        <FileText className="h-7 w-7 text-blue-600" />
        <div>
          <h1 className="text-2xl font-bold text-gray-900">Page Performance</h1>
          <p className="text-sm text-gray-500">How individual pages perform in search results</p>
        </div>
      </div>
      <div className="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden">
        <table className="w-full text-sm">
          <thead className="bg-gray-50 text-xs text-gray-500 uppercase">
            <tr>
              <th className="px-4 py-3 text-left">Page</th>
              <th className="px-4 py-3 text-right">Clicks</th>
              <th className="px-4 py-3 text-right">Impressions</th>
              <th className="px-4 py-3 text-right">Avg Position</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-gray-100">
            {SAMPLE_PAGES.map((p, i) => (
              <tr key={i} className="hover:bg-gray-50">
                <td className="px-4 py-3 font-mono text-xs text-gray-700">{p.url}</td>
                <td className="px-4 py-3 text-right font-medium text-gray-800">{p.clicks.toLocaleString()}</td>
                <td className="px-4 py-3 text-right text-gray-500">{p.impressions.toLocaleString()}</td>
                <td className="px-4 py-3 text-right">
                  <span className={`font-medium ${p.position <= 3 ? 'text-green-600' : p.position <= 10 ? 'text-yellow-600' : 'text-red-600'}`}>
                    {p.position.toFixed(1)}
                  </span>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </div>
  );
}
