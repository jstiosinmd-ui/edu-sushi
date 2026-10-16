<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

require_once __DIR__.'/../../deploy/check-release.php';

class ReleaseNotesTest extends TestCase
{
    private function release(): array
    {
        return json_decode(file_get_contents(__DIR__.'/../../resources/release.json'), true, 512, JSON_THROW_ON_ERROR);
    }

    public function test_application_changes_require_a_new_version_and_actual_notes(): void
    {
        $old = $this->release();
        $this->assertSame([], sushiReleaseErrors($old));
        $this->assertSame([], sushiReleaseErrors($old, $old, false));
        $this->assertNotEmpty(sushiReleaseErrors($old, $old, true));
        $next = [...$old, 'version' => '99.0', 'id' => 'sushi-99.0'];
        $this->assertNotEmpty(sushiReleaseErrors($next, $old, true));
        $next['summary'] = 'A meaningful explanation of a new learner feature.';
        $this->assertSame([], sushiReleaseErrors($next, $old, true));
        $next['version'] = '1.0';
        $next['id'] = 'sushi-1.0';
        $this->assertNotEmpty(sushiReleaseErrors($next, $old, true));
    }

    public function test_release_notes_require_learner_content_and_local_links(): void
    {
        $release = $this->release();
        $this->assertNotEmpty(sushiReleaseErrors([...$release, 'features' => []]));
        $this->assertNotEmpty(sushiReleaseErrors([...$release, 'features' => [['title' => 'Admin', 'text' => 'Only admin', 'url' => '/admin', 'audience' => 'admin']]]));
        $release['features'][0]['url'] = '//another-site.example';
        $this->assertNotEmpty(sushiReleaseErrors($release));
    }

    public function test_every_guide_screenshot_is_a_real_distinct_full_size_image(): void
    {
        $root = dirname(__DIR__, 2);
        $guide = json_decode(file_get_contents($root.'/resources/tour.json'), true, 512, JSON_THROW_ON_ERROR);
        $hashes = [];
        foreach ($guide['stops'] as $stop) {
            $this->assertNotEmpty($stop['steps']);
            $this->assertNotEmpty($stop['screens']);
            foreach ($stop['screens'] as $screen) {
                $this->assertStringStartsWith('/guide-screens/v'.$guide['version'].'/', $screen['src']);
                $this->assertNotEmpty($screen['alt']);
                $this->assertNotEmpty($screen['caption']);
                $file = $root.'/public'.$screen['src'];
                $this->assertFileExists($file);
                $size = getimagesize($file);
                $this->assertSame(IMAGETYPE_JPEG, $size[2]);
                $this->assertGreaterThanOrEqual(900, $size[0]);
                $this->assertGreaterThanOrEqual(500, $size[1]);
                $hashes[] = hash_file('sha256', $file);
            }
        }
        $this->assertSame(count($hashes), count(array_unique($hashes)));
    }
}
