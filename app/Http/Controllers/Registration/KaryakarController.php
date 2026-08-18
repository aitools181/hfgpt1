<?php

namespace App\Http\Controllers\Registration;

use App\Http\Controllers\Controller;
use App\Models\Center;
use App\Models\FamilyMember;
use App\Models\Karyakar;
use App\Services\KaryakarCategory;
use App\Services\OrganizationalScope;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class KaryakarController extends Controller
{
    public function index(Request $request, OrganizationalScope $scope): Response
    {
        $centerIds = $scope->centers($request->user())->pluck('id');
        $query = Karyakar::query()->with(['center:id,name,code', 'family:id,external_family_id,manual_reference,head_name', 'member:id,name,external_member_id', 'groupAssignments' => fn ($q) => $q->where('status', 'active')->with('group:id,group_code,status')])->whereIn('center_id', $centerIds);
        if ($request->filled('search')) {
            $term = trim((string) $request->string('search'));
            $query->where(fn ($q) => $q->where('full_name', 'ilike', "%{$term}%")->orWhere('karyakar_reference', 'ilike', "%{$term}%")->orWhere('mobile', 'ilike', "%{$term}%"));
        }
        foreach (['center_id', 'gender', 'category', 'status', 'source'] as $filter) if ($request->filled($filter)) $query->where($filter, $request->input($filter));

        $members = FamilyMember::query()->with('family:id,center_id,external_family_id,manual_reference,head_name')
            ->where('status', 'active')
            ->whereHas('family', fn ($q) => $q->whereIn('center_id', $centerIds)->where('status', 'active'))
            ->whereDoesntHave('karyakar')->whereNotNull('gender')->whereNotNull('age')->orderBy('name')->limit(500)->get();

        return Inertia::render('registration/karyakars', [
            'karyakars' => $query->latest()->paginate(25)->withQueryString(),
            'centers' => $scope->centers($request->user())->orderBy('name')->get(['id', 'name', 'code']),
            'members' => $members,
            'categories' => KaryakarCategory::CATEGORIES,
            'filters' => $request->only(['search', 'center_id', 'gender', 'category', 'status', 'source']),
        ]);
    }

    public function store(Request $request, OrganizationalScope $scope, KaryakarCategory $category): RedirectResponse
    {
        $allowedCenters = $scope->centers($request->user())->pluck('id')->all();
        $data = $request->validate([
            'center_id' => ['required', Rule::in($allowedCenters)], 'full_name' => ['required', 'string', 'max:255'],
            'gender' => ['required', Rule::in(['male', 'female'])], 'age' => ['required', 'integer', 'min:0', 'max:120'],
            'mobile' => ['nullable', 'string', 'max:30'], 'email' => ['nullable', 'email', 'max:255'], 'address' => ['nullable', 'string', 'max:2000'],
            'preferred_area' => ['nullable', 'string', 'max:255'], 'experience_notes' => ['nullable', 'string', 'max:2000'],
        ]);
        $karyakar = DB::transaction(function () use ($data, $category, $request): Karyakar {
            Center::query()->whereKey((int) $data['center_id'])->lockForUpdate()->firstOrFail();
            return Karyakar::query()->create($data + [
                'karyakar_reference' => $this->nextReference((int) $data['center_id']), 'source' => 'manual',
                'category' => $category->calculate((int) $data['age'], $data['gender']), 'status' => 'pending', 'nominated_by' => $request->user()->id,
            ]);
        }, 3);
        return back()->with('success', "Karyakar {$karyakar->karyakar_reference} registered as Pending.");
    }

    public function nominate(Request $request, OrganizationalScope $scope, KaryakarCategory $category): RedirectResponse
    {
        $allowedCenters = $scope->centers($request->user())->pluck('id')->all();
        $data = $request->validate(['family_member_id' => ['required', 'integer', 'exists:family_members,id']]);
        $karyakar = DB::transaction(function () use ($data, $allowedCenters, $category, $request): Karyakar {
            $member = FamilyMember::query()->with('family.center')->whereKey($data['family_member_id'])->lockForUpdate()->firstOrFail();
            abort_unless(in_array((int) $member->family->center_id, array_map('intval', $allowedCenters), true), 403);
            Center::query()->whereKey($member->family->center_id)->lockForUpdate()->firstOrFail();
            abort_if($member->status !== 'active' || $member->family->status !== 'active', 422, 'Only an active Family Member from an active Family can be nominated.');
            abort_if($member->karyakar()->exists(), 422, 'This family member is already nominated as a Sankalp Karyakar.');
            abort_if($member->gender === null || $member->age === null, 422, 'Age and Gender are required before nomination.');

            return Karyakar::query()->create([
                'center_id' => $member->family->center_id, 'family_id' => $member->family_id, 'family_member_id' => $member->id,
                'karyakar_reference' => $this->nextReference($member->family->center_id), 'source' => 'family_nomination',
                'full_name' => $member->name, 'gender' => $member->gender, 'age' => $member->age,
                'category' => $category->calculate($member->age, $member->gender), 'mobile' => $member->mobile,
                'address' => $member->family->address, 'status' => 'pending', 'nominated_by' => $request->user()->id,
            ]);
        }, 3);
        $member = $karyakar->member;
        return back()->with('success', "{$member->name} nominated as {$karyakar->category}; approval is pending.");
    }

    public function decide(Request $request, Karyakar $karyakar): RedirectResponse
    {
        abort_unless($request->user()->canAccessCenterId($karyakar->center_id), 403);
        abort_unless($request->user()->hasPermission('approve_karyakar'), 403);
        $data = $request->validate(['decision' => ['required', Rule::in(['approved', 'rejected'])], 'decision_note' => ['nullable', 'string', 'max:2000']]);

        DB::transaction(function () use ($karyakar, $data, $request): void {
            $locked = Karyakar::query()->whereKey($karyakar->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== 'pending') {
                throw ValidationException::withMessages(['decision' => 'Only a Pending Sankalp Karyakar application can be Approved or Rejected.']);
            }
            $locked->update([
                'status' => $data['decision'], 'decision_note' => $data['decision_note'] ?? null,
                'approved_by' => $data['decision'] === 'approved' ? $request->user()->id : null,
                'approved_at' => $data['decision'] === 'approved' ? now() : null,
            ]);
        }, 3);

        return back()->with('success', "Karyakar {$data['decision']} successfully.");
    }

    private function nextReference(int $centerId): string
    {
        $centerCode = \App\Models\Center::query()->whereKey($centerId)->value('code');
        $next = ((int) Karyakar::query()->where('center_id', $centerId)->max('id')) + 1;
        do { $reference = sprintf('SK-%s-%06d', $centerCode, $next++); } while (Karyakar::query()->where('karyakar_reference', $reference)->exists());
        return $reference;
    }
}
