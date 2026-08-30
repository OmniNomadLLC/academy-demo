<?php

namespace Tests\Unit\Services;

use App\Services\SkillCirclePresenter;
use Illuminate\Support\Collection;
use Tests\TestCase;

class SkillCirclePresenterTest extends TestCase
{
    public function test_it_normalizes_scores_to_percentages(): void
    {
        $presenter = app(SkillCirclePresenter::class);

        $result = $presenter->present(collect([
            ['skill' => 'behaviour', 'label' => 'Behaviour', 'score' => 7.5, 'is_empty' => false],
            ['skill' => 'language', 'label' => 'Language', 'score' => 0, 'is_empty' => true],
        ]));

        $this->assertSame(75, $result[0]['percentage']);
        $this->assertSame(0, $result[1]['percentage']);
        $this->assertTrue($result[1]['is_empty']);
    }
}
