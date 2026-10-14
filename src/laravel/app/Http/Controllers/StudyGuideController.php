<?php

namespace App\Http\Controllers;

use App\Services\Catalog;
use App\Services\Study;
use App\Services\StudyGuide;
use Illuminate\Http\Request;

class StudyGuideController extends Controller
{
    private function preferences(Request $r): array
    {
        return $r->validate(['minutes' => 'required|integer|in:5,15,30', 'subjects' => 'required|array|min:1|max:'.count(Catalog::SUBJECTS),
            'subjects.*' => 'required|distinct|in:'.implode(',', Catalog::SUBJECTS)]);
    }

    public function preview(Request $r, StudyGuide $guide)
    {
        $d = $this->preferences($r);

        return response()->json($guide->plan($r->user(), $d['subjects'], $d['minutes']));
    }

    public function start(Request $r, StudyGuide $guide, Study $study)
    {
        $d = $this->preferences($r);
        $plan = $guide->plan($r->user(), $d['subjects'], $d['minutes']);
        abort_unless($plan['count'], 422, 'No questions are available in these subjects.');
        $a = $study->start($r->user(), ['mode' => 'tutor', 'count' => $plan['count'], 'subjects' => $d['subjects'],
            'seed' => random_int(0, 4294967295), 'topics' => [], 'difficulty' => 'All', 'balance' => false,
            'pool' => 'all', 'minutes' => $d['minutes'], 'selected_ids' => $plan['selected_ids'], 'guide' => 'today']);

        return redirect('/attempts/'.$a->id);
    }
}
