import { Search } from 'lucide-react';

const SAMPLE_QUERIES = [
  { query: 'accounting software for small business', clicks: 234, impressions: 4820, ctr: 0.049, position: 3.2 },
  { query: 'best bookkeeping tools', clicks: 187, impressions: 3640, ctr: 0.051, position: 4.1 },
  { query: 'aicountly pricing', clicks: 162, impressions: 1240, ctr: 0.131, position: 1.8 },
  { query: 'invoice management software', clicks: 98, impressions: 2870, ctr: 0.034, position: 7.4 },
  { query: 'gst accounting india', clicks: 76, impressions: 1920, ctr: 0.040, position: 6.2 },
];

export default function SearchQueryPerformancePage() {
  return (
    <div className="p-6 space-y-6">
      <div className="flex items-center gap-3">
        <Search className="h-7 w-7 text-indigo-600" />
        <div>
          <h1 className="text-2xl font-bold text-gray-900">Query Performance</h1>
          <p className="text-sm text-gray-500">Search queries driving traffic to your content</p>
        </div>
      </div>
      <div className="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden">
        <table className="w-full text-sm">
          <thead className="bg-gray-50 text-xs text-gray-500 uppercase">
            <tr>
              <th className="px-4 py-3 text-left">Query</th>
              <th className="px-4 py-3 text-right">Clicks</th>
              <th className="px-4 py-3 text-right">Impressions</th>
              <th className="px-4 py-3 text-right">CTR</th>
              <th className="px-4 py-3 text-right">Avg Position</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-gray-100">
            {SAMPLE_QUERIES.map((q, i) => (
              <tr key={i} className="hover:bg-gray-50">
                <td className="px-4 py-3 text-gray-800 font-medium">{q.query}</td>
                <td className="px-4 py-3 text-right text-gray-700">{q.clicks.toLocaleString()}</td>
                <td className="px-4 py-3 text-right text-gray-500">{q.impressions.toLocaleString()}</td>
                <td className="px-4 py-3 text-right text-blue-600">{(q.ctr * 100).toFixed(1)}%</td>
                <td className="px-4 py-3 text-right">
                  <span className={`font-medium ${q.position <= 3 ? 'text-green-600' : q.position <= 10 ? 'text-yellow-600' : 'text-red-600'}`}>
                    {q.position.toFixed(1)}
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
