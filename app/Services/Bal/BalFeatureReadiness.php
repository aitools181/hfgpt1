<?php

namespace App\Services\Bal;

use App\Models\Center;
use App\Models\User;
use App\Services\OrganizationalScope;
use Illuminate\Support\Facades\Schema;

class BalFeatureReadiness
{
    private const TABLES = [
        'bal_group_sequences',
        'bal_groups',
        'bal_group_children',
        'bal_group_supervisors',
        'bal_completion_reports',
    ];

    public function __construct(private readonly OrganizationalScope $scope)
    {
    }

    public function missingTables(): array
    {
        return array_values(array_filter(self::TABLES, fn (string $table): bool => ! Schema::hasTable($table)));
    }

    public function fallbackDashboard(User $user): array
    {
        $femaleLocked = $user->hasRole('bn_karyalay_admin');

        return [
            'filters' => [
                'center_id' => null,
                'gender' => $femaleLocked ? 'female' : null,
                'category' => null,
                'status' => null,
                'date_from' => null,
                'date_to' => null,
                'female_scope_locked' => $femaleLocked,
            ],
            'scopeLabel' => $this->scopeLabel($user),
            'summary' => [
                'groups' => 0,
                'activeGroups' => 0,
                'children' => 0,
                'sanchalaks' => 0,
                'reports' => 0,
                'familiesVisited' => 0,
                'familiesCompleted' => 0,
                'completionRate' => 0.0,
            ],
            'centerPerformance' => [],
            'zonePerformance' => [],
            'groupPerformance' => [],
            'childGenderDistribution' => [
                ['label' => 'Bal (Male)', 'key' => 'male', 'value' => 0],
                ['label' => 'Balika (Female)', 'key' => 'female', 'value' => 0],
            ],
            'sanchalakCategoryDistribution' => [],
            'completionTrend' => [],
        ];
    }

    public function fallbackOptions(User $user): array
    {
        $centerIds = $this->scope->centers($user)->pluck('id');
        return [
            'centers' => Center::query()->whereIn('id', $centerIds)->orderBy('name')->get(['id', 'name', 'code']),
            'categories' => [],
        ];
    }

    private function scopeLabel(User $user): string
    {
        if ($user->hasRole('super_admin')) return 'Karyalay / organization-wide Bal Pravruti';
        if ($user->hasRole('bn_karyalay_admin')) return 'BN Karyalay - female Sanchalak analysis scope';
        if ($user->hasRole('zonal_admin')) return 'Assigned Zone Bal Pravruti';
        if ($user->hasRole('center_admin')) return 'Assigned Center Bal Pravruti';
        if ($user->hasRole('nirdeshak')) return 'Assigned Nirdeshak Bal Pravruti Groups';
        if ($user->hasRole('nirikshak')) return 'Assigned Nirikshak Bal Pravruti Groups';
        if ($user->hasRole('sanchalak')) return 'My assigned Bal Pravruti Groups';
        return 'Assigned Bal Pravruti scope';
    }
}
