<?php

namespace App\Http\Controllers\Registration;

use App\Http\Controllers\Controller;
use App\Models\Family;
use App\Models\FamilyMember;
use App\Models\SamparkArea;
use App\Models\Society;
use App\Services\AuditTrail;
use App\Services\OrganizationalScope;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class FamilyController extends Controller
{
    public function index(Request $request, OrganizationalScope $scope): Response
    {
        $user = $request->user();
        $centerIds = $scope->centers($user)->pluck('id');
        $query = Family::query()->with(['center:id,name,code', 'area:id,name', 'society:id,name', 'groupAssignments' => fn ($q) => $q->where('status', 'active')->with('group:id,group_code')])->withCount([
            'members as members_count' => fn ($q) => $q->where('status', 'active'),
            'members as male_count' => fn ($q) => $q->where('status', 'active')->where('gender', 'male'),
            'members as female_count' => fn ($q) => $q->where('status', 'active')->where('gender', 'female'),
        ])->whereIn('center_id', $centerIds);

        if ($request->filled('gender') && in_array($request->input('gender'), ['male', 'female'], true)) {
            $query->whereHas('members', fn ($q) => $q->where('status', 'active')->where('gender', $request->input('gender')));
        }

        if ($request->filled('search')) {
            $term = trim((string) $request->string('search'));
            $query->where(fn ($q) => $q->where('external_family_id', 'ilike', "%{$term}%")
                ->orWhere('manual_reference', 'ilike', "%{$term}%")
                ->orWhere('head_name', 'ilike', "%{$term}%")
                ->orWhere('head_mobile', 'ilike', "%{$term}%"));
        }
        foreach (['center_id', 'source', 'status'] as $filter) if ($request->filled($filter)) $query->where($filter, $request->input($filter));

        return Inertia::render('registration/families', [
            'families' => $query->latest()->paginate(25)->withQueryString(),
            'centers' => $scope->centers($user)->orderBy('name')->get(['id', 'name', 'code']),
            'areas' => SamparkArea::query()->whereIn('center_id', $centerIds)->orderBy('name')->get(['id', 'center_id', 'name']),
            'societies' => Society::query()->whereIn('center_id', $centerIds)->orderBy('name')->get(['id', 'center_id', 'sampark_area_id', 'name']),
            'filters' => $request->only(['search', 'center_id', 'source', 'status', 'gender']),
        ]);
    }

    public function store(Request $request, OrganizationalScope $scope): RedirectResponse
    {
        $allowedCenters = $scope->centers($request->user())->pluck('id')->all();
        $data = $request->validate([
            'center_id' => ['required', Rule::in($allowedCenters)],
            'head_name' => ['required', 'string', 'max:255'],
            'head_mobile' => ['nullable', 'string', 'max:30'],
            'address' => ['nullable', 'string', 'max:2000'],
            'city_village' => ['nullable', 'string', 'max:255'],
            'sampark_area_id' => ['nullable', 'integer', 'exists:sampark_areas,id'],
            'society_id' => ['nullable', 'integer', 'exists:societies,id'],
            'members' => ['array', 'max:30'],
            'members.*.name' => ['required', 'string', 'max:255'],
            'members.*.gender' => ['required', Rule::in(['male', 'female'])],
            'members.*.age' => ['nullable', 'integer', 'min:0', 'max:120'],
            'members.*.mobile' => ['nullable', 'string', 'max:30'],
            'members.*.relationship' => ['nullable', 'string', 'max:100'],
            'members.*.is_head' => ['boolean'],
        ]);

        if (collect($data['members'] ?? [])->where('is_head', true)->count() > 1) {
            throw ValidationException::withMessages(['members' => 'Only one Family Member can be marked as Head.']);
        }

        if (! empty($data['sampark_area_id'])) {
            $area = SamparkArea::query()->findOrFail($data['sampark_area_id']);
            if ($area->center_id !== (int) $data['center_id']) {
                throw ValidationException::withMessages(['sampark_area_id' => 'Area must belong to the selected Center.']);
            }
        }
        if (! empty($data['society_id'])) {
            if (empty($data['sampark_area_id'])) {
                throw ValidationException::withMessages(['sampark_area_id' => 'Select the Sampark Area when assigning a Society.']);
            }
            $society = Society::query()->findOrFail($data['society_id']);
            if ($society->center_id !== (int) $data['center_id'] || $society->sampark_area_id !== (int) $data['sampark_area_id']) {
                throw ValidationException::withMessages(['society_id' => 'Society must belong to the selected Center and Area.']);
            }
        }

        DB::transaction(function () use ($data, $request): void {
            $family = Family::query()->create([
                'center_id' => $data['center_id'], 'sampark_area_id' => $data['sampark_area_id'] ?? null,
                'society_id' => $data['society_id'] ?? null, 'source' => 'manual', 'head_name' => $data['head_name'],
                'head_mobile' => $data['head_mobile'] ?? null, 'address' => $data['address'] ?? null,
                'city_village' => $data['city_village'] ?? null, 'status' => 'active', 'registered_at' => now(),
                'registered_by' => $request->user()->id,
            ]);
            $family->update(['manual_reference' => sprintf('HF-%s-%06d', $family->center->code, $family->id)]);
            foreach ($data['members'] ?? [] as $member) $family->members()->create($member + ['status' => 'active']);
        });
        return back()->with('success', 'Manual Sankalp Family registered successfully.');
    }

    public function show(Request $request, Family $family): Response
    {
        abort_unless($request->user()->canAccessCenterId($family->center_id), 403);
        $family->load(['center:id,name,code', 'area:id,name', 'society:id,name', 'members' => fn ($q) => $q->orderByDesc('is_head')->orderBy('name')]);
        return Inertia::render('registration/family-detail', [
            'family' => $family,
            'areas' => SamparkArea::query()->where('center_id', $family->center_id)->where('status', 'active')->orderBy('name')->get(['id', 'name']),
            'societies' => Society::query()->where('center_id', $family->center_id)->where('status', 'active')->orderBy('name')->get(['id', 'sampark_area_id', 'name']),
        ]);
    }

    public function update(Request $request, Family $family, AuditTrail $audit): RedirectResponse
    {
        abort_unless($request->user()->canAccessCenterId($family->center_id), 403);
        $data = $request->validate([
            'head_name' => ['required', 'string', 'max:255'],
            'head_mobile' => ['nullable', 'string', 'max:30'],
            'address' => ['nullable', 'string', 'max:2000'],
            'city_village' => ['nullable', 'string', 'max:255'],
            'sampark_area_id' => ['nullable', 'integer', 'exists:sampark_areas,id'],
            'society_id' => ['nullable', 'integer', 'exists:societies,id'],
            'status' => ['required', Rule::in(['active', 'inactive', 'pending_verification'])],
            'change_reason' => ['required', 'string', 'max:1000'],
            'members' => ['array', 'max:30'],
            'members.*.id' => ['nullable', 'integer'],
            'members.*.name' => ['required', 'string', 'max:255'],
            'members.*.gender' => ['required', Rule::in(['male', 'female'])],
            'members.*.age' => ['nullable', 'integer', 'min:0', 'max:120'],
            'members.*.mobile' => ['nullable', 'string', 'max:30'],
            'members.*.relationship' => ['nullable', 'string', 'max:100'],
            'members.*.is_head' => ['boolean'],
            'members.*.status' => ['required', Rule::in(['active', 'inactive'])],
        ]);

        $data['sampark_area_id'] = ! empty($data['sampark_area_id']) ? (int) $data['sampark_area_id'] : null;
        $data['society_id'] = ! empty($data['society_id']) ? (int) $data['society_id'] : null;
        $this->validateLocation($family->center_id, $data['sampark_area_id'], $data['society_id']);
        if ($data['status'] === 'pending_verification' && $family->source !== 'karyakar_reported') {
            throw ValidationException::withMessages(['status' => 'Only a Karyakar-reported Family can be pending verification.']);
        }
        if ($data['status'] === 'inactive' && $family->groupAssignments()->where('status', 'active')->exists()) {
            throw ValidationException::withMessages(['status' => 'An actively assigned Family cannot be made inactive. Transfer or close the active assignment first.']);
        }
        $members = collect($data['members'] ?? [])->map(function (array $member): array {
            $member['id'] = ! empty($member['id']) ? (int) $member['id'] : null;
            $member['age'] = ($member['age'] ?? '') === '' || ($member['age'] ?? null) === null ? null : (int) $member['age'];
            $member['is_head'] = (bool) ($member['is_head'] ?? false);
            return $member;
        })->all();
        if (collect($members)->where('is_head', true)->count() > 1) {
            throw ValidationException::withMessages(['members' => 'Only one Family Member can be marked as Head.']);
        }
        $memberIds = collect($members)->pluck('id')->filter()->map(fn ($id) => (int) $id);
        if ($memberIds->duplicates()->isNotEmpty()) {
            throw ValidationException::withMessages(['members' => 'The same Family Member cannot be submitted twice.']);
        }
        if ($memberIds->isNotEmpty() && FamilyMember::query()->whereIn('id', $memberIds)->where('family_id', '!=', $family->id)->exists()) {
            throw ValidationException::withMessages(['members' => 'A submitted Family Member does not belong to this Family.']);
        }
        if ($memberIds->count() !== FamilyMember::query()->whereIn('id', $memberIds)->where('family_id', $family->id)->count()) {
            throw ValidationException::withMessages(['members' => 'A submitted Family Member could not be found.']);
        }

        DB::transaction(function () use ($family, $data, $members, $audit): void {
            $locked = Family::query()->lockForUpdate()->findOrFail($family->id);
            $oldFamily = $locked->only(['head_name', 'head_mobile', 'address', 'city_village', 'sampark_area_id', 'society_id', 'status']);
            $newFamily = collect($data)->only(array_keys($oldFamily))->all();
            $locked->fill($newFamily);
            if ($locked->isDirty()) {
                $locked->saveQuietly();
                $audit->record('family', 'family_details_updated', Family::class, (string) $locked->id, $oldFamily, $newFamily, $data['change_reason'], centerId: $locked->center_id);
            }

            foreach ($members as $memberData) {
                $memberId = $memberData['id'];
                unset($memberData['id']);
                if ($memberId === null) {
                    $member = new FamilyMember($memberData + ['family_id' => $locked->id]);
                    $member->saveQuietly();
                    $audit->record('family_member', 'family_member_created', FamilyMember::class, (string) $member->id, [], $member->getAttributes(), $data['change_reason'], centerId: $locked->center_id);
                    continue;
                }
                $member = FamilyMember::query()->where('family_id', $locked->id)->lockForUpdate()->findOrFail($memberId);
                $oldMember = $member->only(['name', 'gender', 'age', 'mobile', 'relationship', 'is_head', 'status']);
                $newMember = collect($memberData)->only(array_keys($oldMember))->all();
                if ($member->karyakar()->exists() && ($oldMember['gender'] !== $newMember['gender'] || (int) ($oldMember['age'] ?? -1) !== (int) ($newMember['age'] ?? -1))) {
                    throw ValidationException::withMessages(['members' => 'Age/Gender for a member already linked to a Sankalp Karyakar requires a Correction Request so Group/category impact can be reviewed.']);
                }
                $member->fill($newMember);
                if ($member->isDirty()) {
                    $member->saveQuietly();
                    $audit->record('family_member', 'family_member_updated', FamilyMember::class, (string) $member->id, $oldMember, $newMember, $data['change_reason'], centerId: $locked->center_id);
                }
            }
        });

        return back()->with('success', 'Sankalp Family details updated with an audited change reason.');
    }

    private function validateLocation(int $centerId, ?int $areaId, ?int $societyId): void
    {
        if ($areaId !== null) {
            $area = SamparkArea::query()->findOrFail($areaId);
            if ((int) $area->center_id !== $centerId) {
                throw ValidationException::withMessages(['sampark_area_id' => 'Area must belong to the Family Center.']);
            }
        }
        if ($societyId !== null) {
            if ($areaId === null) {
                throw ValidationException::withMessages(['sampark_area_id' => 'Select the Sampark Area when assigning a Society.']);
            }
            $society = Society::query()->findOrFail($societyId);
            if ((int) $society->center_id !== $centerId || (int) $society->sampark_area_id !== $areaId) {
                throw ValidationException::withMessages(['society_id' => 'Society must belong to the Family Center and selected Area.']);
            }
        }
    }
}
