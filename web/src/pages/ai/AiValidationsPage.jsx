import { Link } from 'react-router-dom';

export default function AiValidationsPage() {
  return (
    <div className="space-y-4">
      <h1 className="text-xl font-bold text-gray-900">AI Validation Runs</h1>
      <p className="text-xs text-gray-500">
        Validation results are linked to content versions. Only humans can waive findings.
        Use the Content Studio to review findings attached to a specific content item.
      </p>
      <div className="bg-gray-50 border border-gray-200 rounded-lg p-4">
        <p className="text-sm text-gray-600">
          Navigate to a content item in the{' '}
          <Link to="/content" className="text-blue-600 hover:underline">Content Studio</Link>{' '}
          to view its validation history.
        </p>
      </div>
      <div className="text-xs text-amber-700 bg-amber-50 border border-amber-200 rounded px-3 py-2">
        AI cannot approve content, waive validation findings, or send campaigns. All decisions require human review.
      </div>
    </div>
  );
}
