<?php

namespace App\Services;

use App\Models\User;

class TourGuide
{
    public static function for(User $user): array
    {
        $guide = json_decode(file_get_contents(resource_path('tour.json')), true, 512, JSON_THROW_ON_ERROR);

        return ['title' => FeatureUpdates::label()['tour_name'], 'version' => $guide['version'],
            'stops' => array_values(array_filter($guide['stops'], fn ($stop) => ($stop['audience'] ?? 'all') === 'all' || $user->role === 'admin'))];
    }
}
