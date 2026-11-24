<?php

use App\Models\MembershipPlan;
use App\Models\RegulationVersion;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;

touch('/tmp/activefit-checkout-browser.sqlite');
if (! is_dir('/tmp/activefit-checkout-browser-sessions')) {
    mkdir('/tmp/activefit-checkout-browser-sessions', 0700);
}
require __DIR__.'/browser-app.php';
Artisan::call('migrate:fresh', ['--force' => true]);
MembershipPlan::factory()->create(['name' => 'Karnet miesięczny', 'price_grosz' => 16000, 'is_active' => true, 'is_public' => true]);
MembershipPlan::factory()->entries(8)->create(['name' => 'Karnet na 8 wejść', 'price_grosz' => 12050, 'is_active' => true, 'is_public' => true]);
$version = new RegulationVersion;
$version->forceFill(['version_number' => 1, 'body' => '<p>Testowy regulamin zakupów.</p>', 'published_at' => now()->subMinute(), 'created_by_user_id' => User::factory()->admin()->create()->id])->save();
echo 'Disposable checkout browser fixtures ready.'.PHP_EOL;
