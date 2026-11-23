<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Modules\ClientMobileApp\App\Models\MobilePopup;
use Tests\TestCase;

class MobilePopupsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['database.connections.mysql' => config('database.connections.sqlite')]);
        DB::purge('mysql');

        $migration = require base_path('Modules/ClientMobileApp/Database/migrations/2026_11_24_000000_create_mobile_popups_tables.php');
        $migration->down();
        $migration->up();
        $titleMigration = require base_path('Modules/ClientMobileApp/Database/migrations/2026_11_25_000000_add_title_to_mobile_popup_messages_table.php');
        $titleMigration->up();
    }

    public function test_it_returns_only_current_active_popups_in_order_with_ordered_messages(): void
    {
        $second = MobilePopup::create(['title' => 'Second', 'is_active' => true, 'sort_order' => 2]);
        $second->messages()->create(['image_url' => 'https://example.com/promo.png']);

        $first = MobilePopup::create([
            'title' => 'First',
            'is_active' => true,
            'show_decline_button' => true,
            'decline_label' => 'Decline',
            'sort_order' => 1,
        ]);
        $first->messages()->create(['title' => 'Page two', 'html_content' => '<p>Page 2</p>', 'sort_order' => 2]);
        $first->messages()->create(['html_content' => '<p>Page 1</p>', 'sort_order' => 1]);

        MobilePopup::create(['title' => 'Inactive', 'is_active' => false])
            ->messages()->create(['html_content' => '<p>Hidden</p>']);
        MobilePopup::create(['title' => 'Expired', 'is_active' => true, 'ends_at' => now()->subDay()])
            ->messages()->create(['html_content' => '<p>Old</p>']);
        MobilePopup::create(['title' => 'Empty', 'is_active' => true]);

        $response = $this->getJson('/api/v1/mobile/popups')->assertOk();

        $this->assertSame(['First', 'Second'], array_column($response->json('popups'), 'title'));
        $this->assertTrue($response->json('popups.0.show_decline_button'));
        $this->assertSame(
            ['<p>Page 1</p>', '<p>Page 2</p>'],
            array_column($response->json('popups.0.messages'), 'html_content')
        );
        $this->assertSame(['First', 'Page two'], array_column($response->json('popups.0.messages'), 'title'));
        $this->assertSame('https://example.com/promo.png', $response->json('popups.1.messages.0.image_url'));
        $this->assertSame('Second', $response->json('popups.1.messages.0.title'));

        $first->update(['title' => 'Updated popup title']);
        $this->getJson('/api/v1/mobile/popups')
            ->assertJsonPath('popups.0.messages.0.title', 'Updated popup title')
            ->assertJsonPath('popups.0.messages.1.title', 'Page two');
    }
}
