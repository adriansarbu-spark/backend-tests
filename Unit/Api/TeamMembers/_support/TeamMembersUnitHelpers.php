<?php

declare(strict_types=1);

/**
 * @return array{0: Registry, 1: TeamMembersLoadStub}
 */
function tm_registry_with_stubs(
    object $customer,
    TeamMembersTeamInvitationModelStub $invitationModel,
    ?TeamMembersRepresentativeStub $representativeModel = null,
): array {
    $registry = new Registry();
    $representativeModel = $representativeModel ?? new TeamMembersRepresentativeStub(true);
    $load = new TeamMembersLoadStub($registry, $invitationModel, $representativeModel);
    $registry->set('load', $load);
    $registry->set('customer', $customer);
    $registry->set('request', (object) [
        'get'    => [],
        'server' => [],
    ]);

    return [$registry, $load];
}

/**
 * Team members controller with route permissions granted (Dimension 3 gate).
 */
function tm_make_controller(Registry $registry): TestableControllerPublicAPIV1TeamMembers
{
    $controller = new TestableControllerPublicAPIV1TeamMembers($registry);
    $controller->permission = (object) [
        'get'  => ['publicapi/v1/team/members'],
        'post' => ['publicapi/v1/team/members'],
    ];

    return $controller;
}

final class TeamMembersLoadStub
{
    /** @var list<string> */
    public array $loadedModels = [];

    public function __construct(
        private readonly Registry $registry,
        private readonly TeamMembersTeamInvitationModelStub $invitationModel,
        private readonly TeamMembersRepresentativeStub $representativeModel,
    ) {
    }

    public function model(string $route): void
    {
        $this->loadedModels[] = $route;
        if ($route === 'account/team_invitation') {
            $this->registry->set('model_account_team_invitation', $this->invitationModel);
        } elseif ($route === 'company/representative') {
            $this->registry->set('model_company_representative', $this->representativeModel);
        }
    }
}
