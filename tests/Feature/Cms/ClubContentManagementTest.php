<?php

use App\Actions\Audit\RecordAudit;
use App\Actions\Cms\ManageClubContent;
use App\Models\AuditLog;
use App\Models\ClassScheduleSlot;
use App\Models\ClassType;
use App\Models\ClubContentState;
use App\Models\ClubSetting;
use App\Models\OfferTile;
use App\Models\OpeningHour;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Symfony\Component\Process\Process;

uses(DatabaseMigrations::class);
beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
});
function clubRevision(): int
{
    return ClubContentState::findOrFail(1)->revision;
}
function clubHours(): array
{
    return array_map(fn (int $day): array => ['weekday' => $day, 'status' => 'unknown', 'opens_at' => '', 'closes_at' => ''], range(1, 7));
}
function clubType(User $actor, bool $published = false): ClassType
{
    return app(ManageClubContent::class)->saveType($actor, null, clubRevision(), ['name' => 'Zajęcia testowe', 'description' => 'Opis zajęć', 'is_published' => $published]);
}
function clubSlot(User $actor, ClassType $type, bool $published = false): ClassScheduleSlot
{
    return app(ManageClubContent::class)->saveSlot($actor, null, clubRevision(), ['class_type_id' => $type->id, 'weekday' => 2, 'starts_at' => '18:00', 'ends_at' => '19:00', 'instructor_name' => 'Testowy prowadzący', 'is_published' => $published]);
}

test('empty contact and schedule pages expose unknown hours and no demo content', function () {
    $this->actingAs($this->admin)->get(route('admin.cms.contact.index'))->assertInertia(fn (Assert $page) => $page->where('contact.club_name', null)->has('hours', 7)->where('hours.0.status', 'unknown')->where('hours.0.opens_at', '')->where('revision', 0));
    $this->get(route('admin.cms.contact.edit'))->assertInertia(fn (Assert $page) => $page->component('admin/cms/contact/Form'));
    $this->get(route('admin.cms.schedule.index'))->assertInertia(fn (Assert $page) => $page->where('types.total', 0)->where('slots.total', 0));
    $this->get(route('admin.cms.schedule.types.create'))->assertInertia(fn (Assert $page) => $page->where('type', null));
    $this->get(route('admin.cms.schedule.slots.create'))->assertInertia(fn (Assert $page) => $page->where('slot', null)->where('typeOptions', [])->has('weekdays', 7));
    expect(ClubSetting::count())->toBe(0)->and(OpeningHour::count())->toBe(0);
});

test('all contact and schedule routes enforce active verified admin access', function (string $role) {
    $type = clubType($this->admin);
    $slot = clubSlot($this->admin, $type);
    $user = match ($role) {
        'customer' => User::factory()->create(), 'employee' => User::factory()->employee()->create(), 'suspended' => User::factory()->admin()->create(['suspended_at' => now()]), 'unverified' => User::factory()->admin()->unverified()->create(), default => null
    };
    $status = in_array($role, ['guest', 'unverified'], true) ? 302 : 403;
    foreach (['admin.cms.contact.index', 'admin.cms.contact.edit', 'admin.cms.schedule.index'] as $name) {
        if ($user) {
            $this->actingAs($user);
        } $this->get(route($name))->assertStatus($status);
    }
    if ($user) {
        $this->actingAs($user);
    }
    $this->patch(route('admin.cms.contact.update'), ['revision' => clubRevision(), 'hours' => clubHours()])->assertStatus($status);
    foreach (['types' => $type, 'slots' => $slot] as $kind => $item) {
        foreach (['create' => 'get', 'edit' => 'get', 'store' => 'post', 'update' => 'patch', 'visibility' => 'patch', 'destroy' => 'delete'] as $action => $method) {
            if ($user) {
                $this->actingAs($user);
            }
            $this->{$method}(route('admin.cms.schedule.'.$kind.'.'.$action, in_array($action, ['create', 'store'], true) ? [] : $item), ['revision' => clubRevision()])->assertStatus($status);
        }
    }
    expect(ClubSetting::count())->toBe(0)->and(ClassType::count())->toBe(1)->and(ClassScheduleSlot::count())->toBe(1);
})->with(['guest', 'customer', 'employee', 'suspended', 'unverified']);

test('contact and hours save atomically and appear publicly with closed and unknown days without times', function () {
    $hours = clubHours();
    $hours[0] = ['weekday' => 1, 'status' => 'open', 'opens_at' => '07:30', 'closes_at' => '21:00'];
    $hours[6] = ['weekday' => 7, 'status' => 'closed', 'opens_at' => '06:00', 'closes_at' => '23:00'];
    $this->actingAs($this->admin)->patch(route('admin.cms.contact.update'), ['revision' => 0, 'club_name' => 'Klub testowy', 'contact_email' => 'KLUB@EXAMPLE.TEST', 'contact_phone' => '0048 (600) 700-800', 'street_address' => 'Ulica Testowa 1', 'postal_code' => '00-000', 'city' => 'Miejscowość testowa', 'map_url' => 'https://example.test/map', 'hours' => $hours, 'id' => 99, 'social_links' => ['bad' => 'javascript:alert(1)']])->assertSessionHasNoErrors()->assertRedirect(route('admin.cms.contact.index'));
    expect(ClubSetting::sole()->id)->toBe(1)->and(ClubSetting::sole()->contact_email)->toBe('klub@example.test')->and(ClubSetting::sole()->contact_phone)->toBe('+48600700800')->and(OpeningHour::count())->toBe(7)->and(OpeningHour::where('weekday', 7)->sole()->opens_at)->toBeNull()->and(clubRevision())->toBe(1);
    $this->get(route('public.contact'))->assertInertia(fn (Assert $page) => $page->where('club.name', 'Klub testowy')->where('club.phoneHref', 'tel:+48600700800')->where('club.mapUrl', 'https://example.test/map')->where('openingHours.0.opensAt', '07:30')->where('openingHours.1.opensAt', null)->where('openingHours.6.closed', true)->where('openingHours.6.closesAt', null));
    $this->get(route('home'))->assertInertia(fn (Assert $page) => $page->where('club.email', 'klub@example.test')->where('openingHours.6.closed', true));
    $entry = AuditLog::where('action', 'club.contact_updated')->sole();
    expect($entry->actor_user_id)->toBe($this->admin->id)->and(json_encode($entry->getAttributes()))->not->toContain('klub@example.test')->not->toContain('Ulica Testowa');
});

test('contact rejects invalid fields and incomplete or duplicate weekday intervals', function (string $kind) {
    $data = ['revision' => 0, 'hours' => clubHours()];
    $error = match ($kind) {
        'email' => 'contact_email', 'phone' => 'contact_phone', 'url', 'credentials' => 'map_url', 'missing-day' => 'hours', 'duplicate-day' => 'hours.1.weekday', 'day-range' => 'hours.0.weekday', 'status' => 'hours.0.status', 'missing-start' => 'hours.0.opens_at', default => 'hours.0.closes_at'
    };
    match ($kind) {
        'email' => $data['contact_email'] = 'invalid', 'phone' => $data['contact_phone'] = 'not a phone', 'url' => $data['map_url'] = 'javascript:alert(1)', 'credentials' => $data['map_url'] = 'https://user:password@example.test/',
        'missing-day' => array_pop($data['hours']), 'duplicate-day' => $data['hours'][1]['weekday'] = 1, 'day-range' => $data['hours'][0]['weekday'] = 8, 'status' => $data['hours'][0]['status'] = 'holiday',
        'missing-start' => $data['hours'][0] = ['weekday' => 1, 'status' => 'open', 'opens_at' => '', 'closes_at' => '18:00'],
        default => $data['hours'][0] = ['weekday' => 1, 'status' => 'open', 'opens_at' => '20:00', 'closes_at' => $kind === 'equal' ? '20:00' : '07:00'],
    };
    $this->actingAs($this->admin)->patch(route('admin.cms.contact.update'), $data)->assertSessionHasErrors($error);
    expect(ClubSetting::count())->toBe(0)->and(OpeningHour::count())->toBe(0)->and(clubRevision())->toBe(0);
})->with(['email', 'phone', 'url', 'credentials', 'missing-day', 'duplicate-day', 'day-range', 'status', 'missing-start', 'equal', 'overnight']);

test('changing an open day to closed or unknown clears persisted old hours and accepts empty contact', function (string $status) {
    $hours = clubHours();
    $hours[0] = ['weekday' => 1, 'status' => 'open', 'opens_at' => '08:00', 'closes_at' => '20:00'];
    $this->actingAs($this->admin)->patch(route('admin.cms.contact.update'), ['revision' => 0, 'hours' => $hours])->assertSessionHasNoErrors();
    $hours[0]['status'] = $status;
    $this->patch(route('admin.cms.contact.update'), ['revision' => clubRevision(), 'club_name' => '', 'contact_email' => '', 'contact_phone' => '', 'map_url' => '', 'hours' => $hours])->assertSessionHasNoErrors();
    $row = OpeningHour::where('weekday', 1)->sole();
    expect($row->opens_at)->toBeNull()->and($row->closes_at)->toBeNull()->and($row->is_closed)->toBe($status === 'closed')->and(ClubSetting::sole()->contact_email)->toBeNull();
})->with(['closed', 'unknown']);

test('schedule type and slot create edit visibility and delete reflect both public publication gates', function () {
    $this->actingAs($this->admin)->post(route('admin.cms.schedule.types.store'), ['name' => 'Testowe zajęcia', 'description' => 'Opis tekstowy', 'is_published' => false, 'revision' => 0])->assertSessionHasNoErrors()->assertRedirect();
    $type = ClassType::sole();
    $data = ['class_type_id' => $type->id, 'weekday' => 2, 'starts_at' => '18:00', 'ends_at' => '19:00', 'instructor_name' => 'Prowadząca testowa', 'is_published' => true, 'revision' => clubRevision()];
    $this->post(route('admin.cms.schedule.slots.store'), $data)->assertSessionHasNoErrors()->assertRedirect();
    $slot = ClassScheduleSlot::sole();
    $this->get(route('public.schedule'))->assertInertia(fn (Assert $page) => $page->has('slots', 0));
    $this->patch(route('admin.cms.schedule.types.visibility', $type), ['revision' => clubRevision(), 'is_published' => true])->assertSessionHasNoErrors();
    $this->get(route('public.schedule'))->assertInertia(fn (Assert $page) => $page->has('slots', 1)->where('slots.0.startsAt', '18:00')->where('slots.0.instructor', 'Prowadząca testowa'));
    $this->patch(route('admin.cms.schedule.types.update', $type), ['name' => 'Nowa nazwa', 'description' => 'Nowy opis', 'is_published' => true, 'revision' => clubRevision()])->assertSessionHasNoErrors()->assertRedirect();
    $this->patch(route('admin.cms.schedule.slots.update', $slot), [...$data, 'weekday' => 5, 'starts_at' => '17:00', 'revision' => clubRevision()])->assertSessionHasNoErrors()->assertRedirect();
    $this->get(route('public.schedule'))->assertInertia(fn (Assert $page) => $page->where('slots.0.name', 'Nowa nazwa')->where('slots.0.dayLabel', 'Piątek')->where('slots.0.startsAt', '17:00'));
    $this->patch(route('admin.cms.schedule.slots.visibility', $slot), ['revision' => clubRevision(), 'is_published' => false])->assertSessionHasNoErrors();
    $this->get(route('public.schedule'))->assertInertia(fn (Assert $page) => $page->has('slots', 0));
    $this->delete(route('admin.cms.schedule.slots.destroy', $slot), ['revision' => clubRevision()])->assertSessionHasNoErrors()->assertRedirect();
    $this->delete(route('admin.cms.schedule.types.destroy', $type), ['revision' => clubRevision()])->assertSessionHasNoErrors()->assertRedirect();
    expect(ClassType::count())->toBe(0)->and(ClassScheduleSlot::count())->toBe(0)->and(AuditLog::where('action', 'schedule.slot_deleted')->sole()->actor_user_id)->toBe($this->admin->id);
});

test('slot validation rejects invalid times type day and malformed visibility', function (string $kind) {
    $type = clubType($this->admin);
    $data = ['revision' => clubRevision(), 'class_type_id' => $type->id, 'weekday' => 2, 'starts_at' => '18:00', 'ends_at' => '19:00', 'is_published' => false];
    $field = match ($kind) {
        'type' => 'class_type_id', 'day' => 'weekday', 'visibility' => 'is_published', 'start' => 'starts_at', default => 'ends_at'
    };
    $data[$field] = match ($kind) {
        'type' => 999, 'day' => 8, 'visibility' => 'invalid', 'start' => '25:00', 'equal' => '18:00', default => '17:00'
    };
    $this->actingAs($this->admin)->post(route('admin.cms.schedule.slots.store'), $data)->assertSessionHasErrors($field);
    expect(ClassScheduleSlot::count())->toBe(0);
})->with(['type', 'day', 'visibility', 'start', 'equal', 'end']);

test('nonempty class type cannot be deleted but slots can be transferred without cascading removal', function () {
    $type = clubType($this->admin);
    $slot = clubSlot($this->admin, $type);
    $other = clubType($this->admin);
    $this->actingAs($this->admin)->delete(route('admin.cms.schedule.types.destroy', $type), ['revision' => clubRevision()])->assertSessionHasErrors('class_type_id');
    $this->patch(route('admin.cms.schedule.slots.update', $slot), ['revision' => clubRevision(), 'class_type_id' => $other->id, 'weekday' => 3, 'starts_at' => '12:00', 'ends_at' => '13:00', 'is_published' => false])->assertSessionHasNoErrors();
    $this->delete(route('admin.cms.schedule.types.destroy', $type), ['revision' => clubRevision()])->assertSessionHasNoErrors();
    expect($slot->fresh()->class_type_id)->toBe($other->id)->and(ClassScheduleSlot::count())->toBe(1);
});

test('all club writes reject stale shared revisions without overwriting data', function (string $operation) {
    $type = clubType($this->admin);
    $slot = clubSlot($this->admin, $type);
    $revision = clubRevision();
    clubType($this->admin);
    $this->actingAs($this->admin);
    $response = match ($operation) {
        'contact' => $this->patch(route('admin.cms.contact.update'), ['revision' => $revision, 'hours' => clubHours()]),
        'type-create' => $this->post(route('admin.cms.schedule.types.store'), ['revision' => $revision, 'name' => 'Changed', 'is_published' => true]),
        'type-update' => $this->patch(route('admin.cms.schedule.types.update', $type), ['revision' => $revision, 'name' => 'Changed', 'is_published' => true]),
        'type-visibility' => $this->patch(route('admin.cms.schedule.types.visibility', $type), ['revision' => $revision, 'is_published' => true]),
        'type-delete' => $this->delete(route('admin.cms.schedule.types.destroy', $type), ['revision' => $revision]),
        'slot-create' => $this->post(route('admin.cms.schedule.slots.store'), ['revision' => $revision, 'class_type_id' => $type->id, 'weekday' => 2, 'starts_at' => '12:00', 'ends_at' => '13:00', 'is_published' => true]),
        'slot-update' => $this->patch(route('admin.cms.schedule.slots.update', $slot), ['revision' => $revision, 'class_type_id' => $type->id, 'weekday' => 2, 'starts_at' => '12:00', 'ends_at' => '13:00', 'is_published' => true]),
        'slot-visibility' => $this->patch(route('admin.cms.schedule.slots.visibility', $slot), ['revision' => $revision, 'is_published' => true]),
        default => $this->delete(route('admin.cms.schedule.slots.destroy', $slot), ['revision' => $revision]),
    };
    $response->assertSessionHasErrors('revision');
    expect(ClassType::count())->toBe(2)->and(ClassScheduleSlot::count())->toBe(1)->and(ClubSetting::count())->toBe(0)->and($slot->fresh()->starts_at)->toStartWith('18:00');
})->with(['contact', 'type-create', 'type-update', 'type-visibility', 'type-delete', 'slot-create', 'slot-update', 'slot-visibility', 'slot-delete']);

test('audit failure rolls back contact hours and revision and business action enforces authorization', function () {
    $employee = User::factory()->employee()->create();
    expect(fn () => app(ManageClubContent::class)->saveContact($employee, 0, ['club_name' => 'Denied'], clubHours()))->toThrow(AuthorizationException::class);
    $this->mock(RecordAudit::class)->shouldReceive('handle')->once()->andThrow(new RuntimeException('Audit unavailable'));
    expect(fn () => app(ManageClubContent::class)->saveContact($this->admin, 0, ['club_name' => 'Rolled back'], clubHours()))->toThrow(RuntimeException::class);
    expect(ClubSetting::count())->toBe(0)->and(OpeningHour::count())->toBe(0)->and(clubRevision())->toBe(0);
});

test('two administrators cannot concurrently save the same club revision', function (string $mode) {
    if (DB::getDriverName() !== 'mysql') {
        $this->markTestSkipped('Real row locking is verified in the MySQL suite.');
    }
    $itemId = 0;

    $revision = clubRevision();
    $other = User::factory()->admin()->create();
    $barrier = tempnam(sys_get_temp_dir(), 'club-race-');
    unlink($barrier);
    $db = config('database.connections.mysql');
    $env = ['APP_ENV' => 'testing', 'DB_CONNECTION' => 'mysql', 'DB_URL' => '', 'DB_HOST' => $db['host'], 'DB_PORT' => (string) $db['port'], 'DB_DATABASE' => 'activefit_testing', 'DB_USERNAME' => $db['username'], 'DB_PASSWORD' => $db['password'], 'CACHE_STORE' => 'array'];
    $processes = [];
    try {
        foreach ([$this->admin, $other] as $index => $actor) {
            $worker = (string) ($index + 1);
            $process = new Process([PHP_BINARY, base_path('tests/Support/Cms/club-mutate.php'), $mode, (string) $actor->id, (string) $itemId, (string) $revision, $barrier, $worker], base_path(), $env, timeout: 15);
            $process->start();
            $processes[] = $process;
        }
        $deadline = microtime(true) + 5;
        while ((! file_exists($barrier.'.1') || ! file_exists($barrier.'.2')) && microtime(true) < $deadline) {
            usleep(10000);
        }touch($barrier);
        $results = [];
        foreach ($processes as $process) {
            $process->wait();
            expect($process->isSuccessful())->toBeTrue($process->getErrorOutput());
            $results[] = trim($process->getOutput());
        }
        sort($results);
        expect($results)->toBe(['conflict', 'success'])->and(clubRevision())->toBe($revision + 1);
        if ($mode === 'contact') {
            expect(ClubSetting::count())->toBe(1)->and(OpeningHour::count())->toBe(7);
        } else {
            expect(ClassType::count())->toBe(1);
        }
    } finally {
        foreach ($processes as $process) {
            if ($process->isRunning()) {
                $process->stop();
            }
        }foreach ([$barrier, $barrier.'.1', $barrier.'.2'] as $path) {
            if (file_exists($path)) {
                unlink($path);
            }
        }
    }
})->with(['contact', 'type']);

test('schedule lists paginate independently and keep source times and safe typed data', function () {
    for ($i = 0; $i < 13; $i++) {
        clubType($this->admin);
    }
    $type = ClassType::firstOrFail();
    for ($i = 0; $i < 21; $i++) {
        clubSlot($this->admin, $type);
    }
    $this->actingAs($this->admin)->get(route('admin.cms.schedule.index', ['page' => 2, 'types_page' => 2]))->assertInertia(fn (Assert $page) => $page->where('types.total', 13)->has('types.data', 1)->where('slots.total', 21)->has('slots.data', 1)->where('slots.data.0.starts_at', '18:00')->where('slots.data.0.typePublished', false)->missing('slots.data.0.created_at')->missing('types.data.0.updated_at'));
    $slot = ClassScheduleSlot::firstOrFail();
    $this->get(route('admin.cms.schedule.slots.edit', $slot))->assertInertia(fn (Assert $page) => $page->where('slot.weekday', 2)->where('slot.starts_at', '18:00')->where('typeOptions.0.is_published', false)->missing('typeOptions.0.created_at'));
    $this->get(route('admin.cms.schedule.types.edit', $type))->assertInertia(fn (Assert $page) => $page->where('type.name', $type->name));
});

test('public schedule is visible by default without club settings', function () {
    $this->get(route('home'))->assertInertia(fn (Assert $page) => $page->where('showSchedule', true));
    $this->get(route('public.schedule'))->assertOk();
    $this->actingAs($this->admin)->get(route('admin.cms.schedule.index'))->assertInertia(fn (Assert $page) => $page->where('isScheduleVisible', true));
});

test('admin hides and restores the whole public schedule while preserving class data and contact settings', function () {
    $type = clubType($this->admin, true);
    $slot = clubSlot($this->admin, $type, true);
    ClubSetting::forceCreate(['id' => 1, 'club_name' => 'Klub testowy', 'contact_email' => 'club@example.test']);
    $this->actingAs($this->admin)->patch(route('admin.cms.schedule.visibility'), ['revision' => clubRevision(), 'is_schedule_visible' => false])->assertSessionHasNoErrors()->assertRedirect();
    expect(ClubSetting::sole()->is_schedule_visible)->toBeFalse()->and(ClubSetting::sole()->club_name)->toBe('Klub testowy')->and($slot->fresh()->is_published)->toBeTrue()->and($type->fresh()->is_published)->toBeTrue();
    $this->get(route('public.schedule'))->assertNotFound();
    foreach (['home', 'public.contact', 'public.offer'] as $route) {
        $this->get(route($route))->assertInertia(fn (Assert $page) => $page->where('showSchedule', false));
    }
    $this->get(route('admin.cms.schedule.index'))->assertInertia(fn (Assert $page) => $page->where('isScheduleVisible', false)->where('slots.total', 1));
    $this->patch(route('admin.cms.contact.update'), ['revision' => clubRevision(), 'club_name' => 'Nowa nazwa', 'hours' => clubHours()])->assertSessionHasNoErrors();
    expect(ClubSetting::sole()->is_schedule_visible)->toBeFalse();
    $this->patch(route('admin.cms.schedule.visibility'), ['revision' => clubRevision(), 'is_schedule_visible' => true])->assertSessionHasNoErrors();
    $this->get(route('public.schedule'))->assertInertia(fn (Assert $page) => $page->has('slots', 1)->where('slots.0.id', $slot->id));
    expect(AuditLog::where('action', 'club.schedule_visibility_updated')->count())->toBe(2);
});

test('schedule visibility requires an active verified admin', function (string $role) {
    $user = match ($role) {
        'customer' => User::factory()->create(), 'employee' => User::factory()->employee()->create(), 'suspended' => User::factory()->admin()->create(['suspended_at' => now()]), 'unverified' => User::factory()->admin()->unverified()->create(), default => null
    };
    if ($user) {
        $this->actingAs($user);
    }
    $this->patch(route('admin.cms.schedule.visibility'), ['revision' => 0, 'is_schedule_visible' => false])->assertStatus(in_array($role, ['guest', 'unverified'], true) ? 302 : 403);
    expect(ClubSetting::count())->toBe(0)->and(clubRevision())->toBe(0);
})->with(['guest', 'customer', 'employee', 'suspended', 'unverified']);

test('schedule visibility rejects invalid boolean and stale revision', function () {
    $this->actingAs($this->admin)->patch(route('admin.cms.schedule.visibility'), ['revision' => 0, 'is_schedule_visible' => 'invalid'])->assertSessionHasErrors('is_schedule_visible');
    clubType($this->admin);
    $this->patch(route('admin.cms.schedule.visibility'), ['revision' => 0, 'is_schedule_visible' => false])->assertSessionHasErrors('revision');
    expect(ClubSetting::count())->toBe(0);
});

test('schedule visibility rolls back setting and revision on audit failure', function () {
    $this->mock(RecordAudit::class)->shouldReceive('handle')->once()->andThrow(new RuntimeException('Audit unavailable'));
    expect(fn () => app(ManageClubContent::class)->scheduleVisibility($this->admin, 0, false))->toThrow(RuntimeException::class);
    expect(ClubSetting::count())->toBe(0)->and(clubRevision())->toBe(0);
});

test('hidden schedule removes optional offer detail link without rewriting the offer', function () {
    $tile = OfferTile::forceCreate(['title' => 'Testowa oferta', 'link_url' => '/grafik', 'is_published' => true]);
    app(ManageClubContent::class)->scheduleVisibility($this->admin, 0, false);
    $this->get(route('public.offer.show', $tile))->assertInertia(fn (Assert $page) => $page->where('offer.nextHref', null));
    expect($tile->fresh()->link_url)->toBe('/grafik');
});
