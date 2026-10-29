<?php

namespace App\Http\Controllers;

use App\Services\Catalog;
use App\Services\StudySetup;
use Illuminate\Http\Request;

class StudySetupController extends Controller
{
    public function start(Request $request, StudySetup $setup)
    {
        $data = $request->validate([
            'mode' => 'required|in:practice,flashcards,exam', 'count' => 'required|integer|between:1,100',
            'subjects' => 'required|array|min:1|max:12', 'subjects.*' => 'required|distinct|in:'.implode(',', Catalog::SUBJECTS),
            'topics' => 'present|array|max:500', 'topics.*' => 'required|string|distinct|max:600',
            'difficulty' => 'required|in:All,Easy,Average,Difficult', 'pool' => 'required|in:all,new,missed,low,bookmarks',
            'minutes' => 'required|integer|between:1,360',
        ]);

        return redirect($setup->start($request->user(), $data));
    }
}
