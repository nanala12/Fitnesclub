<?php

namespace App\Http\Controllers\Admin\Cms;

use App\Actions\Cms\ManageClubContent;
use App\Actions\PublicSite\PublicSiteContent;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Cms\ClubContentRequest;
use App\Models\ClassScheduleSlot;
use App\Models\ClassType;
use App\Models\ClubContentState;
use App\Models\ClubSetting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class ClassScheduleController extends Controller
{
    public function index(Request $request, PublicSiteContent $content): Response
    {
        $this->authorize();
        $request->validate(['page' => ['sometimes', 'integer', 'min:1'], 'types_page' => ['sometimes', 'integer', 'min:1']]);

        return DB::transaction(function () use ($content): Response {
            $state = ClubContentState::lock();
            $types = ClassType::withCount('slots')->orderBy('name')->orderBy('id')->paginate(12, pageName: 'types_page')->withQueryString()
                ->through(fn (ClassType $type): array => [...$this->typeData($type), 'slotsCount' => $type->slots_count]);
            $slots = ClassScheduleSlot::with('classType')->orderBy('weekday')->orderBy('starts_at')->orderBy('id')->paginate(20)->withQueryString()
                ->through(fn (ClassScheduleSlot $slot): array => [...$this->slotData($slot), 'typeName' => $slot->classType->name, 'typePublished' => $slot->classType->is_published, 'dayLabel' => PublicSiteContent::WEEKDAYS[$slot->weekday]]);

            return Inertia::render('admin/cms/schedule/Index', ['types' => $types, 'slots' => $slots, 'revision' => $state->revision, 'isScheduleVisible' => $content->scheduleVisible()]);
        });
    }

    public function visibility(ClubContentRequest $request, ManageClubContent $club): RedirectResponse
    {
        $club->scheduleVisibility($request->user(), $request->integer('revision'), $request->boolean('is_schedule_visible'));

        return $this->redirect($request, $request->boolean('is_schedule_visible') ? 'Grafik jest widoczny na stronie klubu.' : 'Publiczny grafik i przycisk w menu zostały ukryte.');
    }

    public function typeCreate(): Response
    {
        return $this->typeForm(null);
    }

    public function typeEdit(ClassType $classType): Response
    {
        return $this->typeForm($classType);
    }

    public function typeStore(ClubContentRequest $request, ManageClubContent $club): RedirectResponse
    {
        $club->saveType($request->user(), null, $request->integer('revision'), $this->typeInput($request));

        return $this->redirect($request, 'Rodzaj zajęć został dodany.');
    }

    public function typeUpdate(ClubContentRequest $request, ClassType $classType, ManageClubContent $club): RedirectResponse
    {
        $club->saveType($request->user(), $classType->id, $request->integer('revision'), $this->typeInput($request));

        return $this->redirect($request, 'Rodzaj zajęć został zapisany.');
    }

    public function typeVisibility(ClubContentRequest $request, ClassType $classType, ManageClubContent $club): RedirectResponse
    {
        $club->visibility($request->user(), true, $classType->id, $request->integer('revision'), $request->boolean('is_published'));

        return $this->redirect($request, 'Widoczność rodzaju zajęć została zapisana.');
    }

    public function typeDestroy(ClubContentRequest $request, ClassType $classType, ManageClubContent $club): RedirectResponse
    {
        $club->delete($request->user(), true, $classType->id, $request->integer('revision'));

        return $this->redirect($request, 'Rodzaj zajęć został usunięty.');
    }

    public function slotCreate(): Response
    {
        return $this->slotForm(null);
    }

    public function slotEdit(ClassScheduleSlot $classScheduleSlot): Response
    {
        return $this->slotForm($classScheduleSlot);
    }

    public function slotStore(ClubContentRequest $request, ManageClubContent $club): RedirectResponse
    {
        $club->saveSlot($request->user(), null, $request->integer('revision'), $this->slotInput($request));

        return $this->redirect($request, 'Termin zajęć został dodany.');
    }

    public function slotUpdate(ClubContentRequest $request, ClassScheduleSlot $classScheduleSlot, ManageClubContent $club): RedirectResponse
    {
        $club->saveSlot($request->user(), $classScheduleSlot->id, $request->integer('revision'), $this->slotInput($request));

        return $this->redirect($request, 'Termin zajęć został zapisany.');
    }

    public function slotVisibility(ClubContentRequest $request, ClassScheduleSlot $classScheduleSlot, ManageClubContent $club): RedirectResponse
    {
        $club->visibility($request->user(), false, $classScheduleSlot->id, $request->integer('revision'), $request->boolean('is_published'));

        return $this->redirect($request, 'Widoczność terminu została zapisana.');
    }

    public function slotDestroy(ClubContentRequest $request, ClassScheduleSlot $classScheduleSlot, ManageClubContent $club): RedirectResponse
    {
        $club->delete($request->user(), false, $classScheduleSlot->id, $request->integer('revision'));

        return $this->redirect($request, 'Termin zajęć został usunięty.');
    }

    private function typeForm(?ClassType $type): Response
    {
        $this->authorize();

        return DB::transaction(function () use ($type): Response {
            $state = ClubContentState::lock();
            $type?->refresh();

            return Inertia::render('admin/cms/schedule/TypeForm', ['type' => $type === null ? null : $this->typeData($type), 'revision' => $state->revision]);
        });
    }

    private function slotForm(?ClassScheduleSlot $slot): Response
    {
        $this->authorize();

        return DB::transaction(function () use ($slot): Response {
            $state = ClubContentState::lock();
            $slot?->refresh();
            $weekdays = [];
            foreach (PublicSiteContent::WEEKDAYS as $value => $label) {
                $weekdays[] = ['value' => $value, 'label' => $label];
            }

            return Inertia::render('admin/cms/schedule/SlotForm', ['slot' => $slot === null ? null : $this->slotData($slot), 'revision' => $state->revision,
                'typeOptions' => ClassType::orderBy('name')->orderBy('id')->get(['id', 'name', 'is_published']), 'weekdays' => $weekdays]);
        });
    }

    /** @return array<string, mixed> */
    private function typeData(ClassType $type): array
    {
        return ['id' => $type->id, 'name' => $type->name, 'description' => $type->description, 'is_published' => $type->is_published];
    }

    /** @return array<string, mixed> */
    private function slotData(ClassScheduleSlot $slot): array
    {
        return ['id' => $slot->id, 'class_type_id' => $slot->class_type_id, 'weekday' => $slot->weekday, 'starts_at' => substr($slot->starts_at, 0, 5), 'ends_at' => substr($slot->ends_at, 0, 5), 'instructor_name' => $slot->instructor_name, 'is_published' => $slot->is_published];
    }

    /** @return array{name:string,description:?string,is_published:bool} */
    private function typeInput(ClubContentRequest $request): array
    {
        return ['name' => $request->validated('name'), 'description' => $request->validated('description'), 'is_published' => $request->boolean('is_published')];
    }

    /** @return array{class_type_id:int,weekday:int,starts_at:string,ends_at:string,instructor_name:?string,is_published:bool} */
    private function slotInput(ClubContentRequest $request): array
    {
        return ['class_type_id' => $request->integer('class_type_id'), 'weekday' => $request->integer('weekday'), 'starts_at' => $request->validated('starts_at'), 'ends_at' => $request->validated('ends_at'), 'instructor_name' => $request->validated('instructor_name'), 'is_published' => $request->boolean('is_published')];
    }

    private function authorize(): void
    {
        Gate::authorize('manage', ClubSetting::class);
    }

    private function redirect(Request $request, string $message): RedirectResponse
    {
        Inertia::flash('toast', ['type' => 'success', 'message' => $message]);

        return to_route('admin.cms.schedule.index', ['page' => min($request->integer('page', 1), max(1, (int) ceil(ClassScheduleSlot::count() / 20))),
            'types_page' => min($request->integer('types_page', 1), max(1, (int) ceil(ClassType::count() / 12)))]);
    }
}
