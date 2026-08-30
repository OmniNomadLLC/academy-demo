<?php

namespace Tests\Unit;

use App\Livewire\Concerns\HasStudentProfileTabs;
use PHPUnit\Framework\TestCase;

class HasStudentProfileTabsTest extends TestCase
{
    /** @test */
    public function it_updates_tab_when_allowed(): void
    {
        $component = new class {
            use HasStudentProfileTabs;
        };

        $component->setProfileTab('skills');

        $this->assertSame('skills', $component->activeTab);
    }

    /** @test */
    public function it_ignores_unknown_tabs(): void
    {
        $component = new class {
            use HasStudentProfileTabs;
        };

        $component->activeTab = 'classes';
        $component->setProfileTab('bogus');

        $this->assertSame('classes', $component->activeTab);
    }
}
