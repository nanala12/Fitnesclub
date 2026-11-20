<?php

namespace App\Http\Controllers\Admin\Cms;

use App\Actions\Cms\ManageClubContent;
use App\Actions\PublicSite\PublicSiteContent;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Cms\ClubContentRequest;
use App\Models\ClubContentState;
use App\Models\ClubSetting;
use App\Models\OpeningHour;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class ContactSettingsController extends Controller
{
    public const array FIELDS = ['club_name', 'contact_email', 'contact_phone', 'street_address', 'postal_code', 'city', 'map_url'];

    public function index(): Response
    {
        return $this->page(false);
    }

    public function edit(): Response
    {
        return $this->page(true);
    }

    public function update(ClubContentRequest $request, ManageClubContent $club): RedirectResponse
    {
        $club->saveContact($request->user(), $request->integer('revision'), $request->safe()->only(self::FIELDS), $request->validated('hours'));
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Dane kontaktowe i godziny otwarcia zostały zapisane.']);

        return to_route('admin.cms.contact.index');
    }

    private function page(bool $edit): Response
    {
        Gate::authorize('manage', ClubSetting::class);

        return DB::transaction(function () use ($edit): Response {
            $state = ClubContentState::lock();
            $settings = ClubSetting::find(1);
            $contact = [];
            foreach (self::FIELDS as $field) {
                $contact[$field] = $settings?->getAttribute($field);
            }
            $rows = OpeningHour::whereBetween('weekday', [1, 7])->get()->keyBy('weekday');
            $hours = [];
            foreach (PublicSiteContent::WEEKDAYS as $weekday => $label) {
                $row = $rows->get($weekday);
                $hours[] = ['weekday' => $weekday, 'label' => $label, 'status' => $row === null ? 'unknown' : ($row->is_closed ? 'closed' : ($row->opens_at !== null && $row->closes_at !== null ? 'open' : 'unknown')),
                    'opens_at' => $row?->opens_at === null ? '' : substr($row->opens_at, 0, 5), 'closes_at' => $row?->closes_at === null ? '' : substr($row->closes_at, 0, 5)];
            }

            return Inertia::render('admin/cms/contact/'.($edit ? 'Form' : 'Index'), ['contact' => $contact, 'hours' => $hours, 'revision' => $state->revision]);
        });
    }
}
