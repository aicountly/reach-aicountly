<?php

namespace App\Controllers\Api\V1;

use App\Controllers\BaseApiController;
use App\Libraries\DashboardSummaryService;

class DashboardController extends BaseApiController
{
    /** Marketing dashboard tiles + upcoming calendar. */
    public function summary()
    {
        return $this->ok((new DashboardSummaryService())->summary());
    }

    /**
     * The owner's command centre: health, bot activity, impact, rankings,
     * projections, recommendations. `?sections=a,b` limits the build,
     * `?fresh=1` bypasses the per-section cache.
     */
    public function commandCentre()
    {
        $only  = array_values(array_filter(array_map('trim', explode(',', (string) ($this->request->getGet('sections') ?? '')))));
        $fresh = in_array((string) $this->request->getGet('fresh'), ['1', 'true'], true);

        return $this->ok((new \App\Libraries\Dashboard\CommandCentreService())->build($only, $fresh));
    }

    /**
     * "Let the bot fix it" on a "What to do next" card: POST {card_id}. The
     * card is looked up again from live data (RecommendationFixer), so the
     * request carries nothing but its id.
     */
    public function commandCentreFix()
    {
        $cardId = trim((string) ($this->input()['card_id'] ?? ''));
        if ($cardId === '' || mb_strlen($cardId) > 80) {
            return $this->fail('card_id is required.', 422);
        }
        $user   = $this->user() ?? [];
        $result = (new \App\Libraries\Dashboard\RecommendationFixer())->fix($cardId, [
            'id' => $this->userId(), 'name' => $user['name'] ?? ($user['display_name'] ?? null), 'email' => $user['email'] ?? null,
        ]);
        foreach (\App\Libraries\Dashboard\CommandCentreService::SECTIONS as $section) {
            \Config\Services::cache()->delete('reach_cc_' . $section);
        }
        $http = (int) $result['http'];
        unset($result['http']);

        return $http < 300 ? $this->ok($result + ['message' => $result['message']]) : $this->fail($result['message'], $http, $result);
    }

    /**
     * Drill-down behind one "What the bot did" counter:
     * `?kind=<counter key>|all&window=24h|7d|30d&page=&per_page=`. Same
     * scope as the counter, so the list holds exactly the rows it counts;
     * failed jobs come grouped by job type.
     */
    public function commandCentreActivity()
    {
        $kind   = trim((string) ($this->request->getGet('kind') ?? ''));
        $kind   = $kind === 'all' ? '' : $kind;
        $window = trim((string) ($this->request->getGet('window') ?? '7d'));
        [$page, $perPage] = $this->pagination(25, 100);

        $activity = new \App\Libraries\Dashboard\CommandCentreActivity();
        if (\App\Libraries\Dashboard\CommandCentreActivity::window($window) === null) {
            return $this->fail('Unknown window: use 24h, 7d or 30d.', 422);
        }
        if ($kind !== '' && ! in_array($kind, $activity->kinds(), true)) {
            return $this->fail('Unknown activity kind: ' . mb_substr($kind, 0, 60), 422);
        }

        return $this->ok($activity->drilldown($kind, $window, $page, $perPage));
    }

    /** Command centre → Rankings drill-down: every tracked keyword, our position, competitors. */
    public function commandCentreKeywords()
    {
        return $this->ok((new \App\Libraries\Dashboard\RankingsDrilldown())->keywords());
    }

    /** Command centre → Rankings drill-down: AI-visibility questions, assistants and who was named. */
    public function commandCentreAiAnswers()
    {
        $days = (int) ($this->request->getGet('days') ?? 28);

        return $this->ok((new \App\Libraries\Dashboard\RankingsDrilldown())->aiAnswers($days > 0 ? $days : 28));
    }

    /** Used by the sidebar to badge menu items. */
    public function counts()
    {
        return $this->ok((new DashboardSummaryService())->counts());
    }
}
