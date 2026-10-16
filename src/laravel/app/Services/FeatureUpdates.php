<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;

class FeatureUpdates
{
    public static function release(): array
    {
        return json_decode(file_get_contents(resource_path('release.json')), true, 512, JSON_THROW_ON_ERROR);
    }

    public static function id(): string
    {
        return self::release()['id'];
    }

    public static function label(): array
    {
        return array_intersect_key(self::release(), array_flip(['id', 'version', 'name', 'released_on', 'tour_name']));
    }

    public static function for(User $user): array
    {
        $release = self::release();
        $history = json_decode(file_get_contents(resource_path('release-history.json')), true, 512, JSON_THROW_ON_ERROR);
        $visible = fn ($entry) => [...array_intersect_key($entry, array_flip(['id', 'version', 'name', 'released_on', 'summary'])),
            'features' => array_values(array_filter($entry['features'], fn ($feature) => ($feature['audience'] ?? 'all') === 'all' || $user->role === 'admin'))];
        usort($history, fn ($a, $b) => version_compare($b['version'], $a['version']));

        return [...self::label(), 'release' => $release['id'], 'title' => $release['name'], 'summary' => $release['summary'],
            'features' => $visible($release)['features'],
            'history' => array_values(array_filter(array_map($visible, $history), fn ($entry) => $entry['features'] && version_compare($entry['version'], $release['version'], '<'))),
            'unread' => ! DB::table('feature_update_reads')->where('user_id', $user->id)->where('release', $release['id'])->exists()];
    }
}
