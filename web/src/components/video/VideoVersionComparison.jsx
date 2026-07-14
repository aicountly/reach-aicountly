/**
 * Simple side-by-side version comparison for video script content.
 *
 * Props:
 *  - versionA {object} — The baseline version.
 *  - versionB {object} — The newer version to compare.
 */
export function VideoVersionComparison({ versionA, versionB }) {
  if (!versionA || !versionB) {
    return <p className="muted">Select two versions to compare.</p>;
  }

  const contentA = versionA.content_json ?? {};
  const contentB = versionB.content_json ?? {};

  const scenesA = Array.isArray(contentA.scenes) ? contentA.scenes : [];
  const scenesB = Array.isArray(contentB.scenes) ? contentB.scenes : [];

  const changed = (a, b) => JSON.stringify(a) !== JSON.stringify(b);

  return (
    <div className="version-comparison">
      <div className="version-comparison__header">
        <div className="version-comparison__col">
          <strong>v{versionA.version_number}</strong>
          <span className="text-muted ml-2">
            {versionA.created_at ? new Date(versionA.created_at).toLocaleDateString() : ''}
          </span>
        </div>
        <div className="version-comparison__col">
          <strong>v{versionB.version_number}</strong>
          <span className="text-muted ml-2">
            {versionB.created_at ? new Date(versionB.created_at).toLocaleDateString() : ''}
          </span>
        </div>
      </div>

      <div className="version-comparison__row">
        <div className={`version-comparison__col ${changed(contentA.hook_text, contentB.hook_text) ? 'changed' : ''}`}>
          <h4>Hook</h4>
          <p>{contentA.hook_text || '—'}</p>
        </div>
        <div className={`version-comparison__col ${changed(contentA.hook_text, contentB.hook_text) ? 'changed' : ''}`}>
          <h4>Hook</h4>
          <p>{contentB.hook_text || '—'}</p>
        </div>
      </div>

      <div className="version-comparison__scenes">
        <h4>Scenes</h4>
        <div className="version-comparison__scenes-grid">
          <div>
            {scenesA.map((scene, i) => (
              <div
                key={i}
                className={`scene-card ${changed(scene, scenesB[i]) ? 'scene-card--changed' : ''}`}
              >
                <div className="scene-card__type">{scene.scene_type} #{scene.order}</div>
                <p className="scene-card__vo">{scene.voice_over_text}</p>
                <p className="scene-card__visual text-muted">{scene.visual_direction}</p>
              </div>
            ))}
          </div>
          <div>
            {scenesB.map((scene, i) => (
              <div
                key={i}
                className={`scene-card ${changed(scene, scenesA[i]) ? 'scene-card--changed' : ''}`}
              >
                <div className="scene-card__type">{scene.scene_type} #{scene.order}</div>
                <p className="scene-card__vo">{scene.voice_over_text}</p>
                <p className="scene-card__visual text-muted">{scene.visual_direction}</p>
              </div>
            ))}
          </div>
        </div>
      </div>

      <div className="version-comparison__row">
        <div className={`version-comparison__col ${changed(contentA.cta_text, contentB.cta_text) ? 'changed' : ''}`}>
          <h4>CTA</h4>
          <p>{contentA.cta_text || '—'}</p>
        </div>
        <div className={`version-comparison__col ${changed(contentA.cta_text, contentB.cta_text) ? 'changed' : ''}`}>
          <h4>CTA</h4>
          <p>{contentB.cta_text || '—'}</p>
        </div>
      </div>
    </div>
  );
}
