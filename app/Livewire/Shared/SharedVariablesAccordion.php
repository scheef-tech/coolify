<?php

namespace App\Livewire\Shared;

use App\Models\Application;
use App\Models\Environment;
use App\Models\Project;
use App\Models\Server;
use App\Models\Service;
use App\Models\StandaloneClickhouse;
use App\Models\StandaloneDragonfly;
use App\Models\StandaloneKeydb;
use App\Models\StandaloneMariadb;
use App\Models\StandaloneMongodb;
use App\Models\StandaloneMysql;
use App\Models\StandalonePostgresql;
use App\Models\StandaloneRedis;
use App\Models\Team;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * Renders inherited shared-variable scopes for a given resource as a stack of
 * collapsible sections. Each section embeds the existing dedicated SharedVariables
 * component in compact mode, so add/edit/delete behavior is identical to the
 * full-screen pages — just inline.
 *
 * Pass `$context` as the model the user is currently looking at:
 *   - Application / Service / Standalone* → shows Environment, Project, Server, Team
 *   - Environment → shows Project, Server (if resolvable), Team
 *   - Project → shows Server (if resolvable), Team
 *   - Server → shows Team
 *
 * Permission gating is delegated to the embedded compact components — they each
 * `@can('view', ...)` and `@can('update', ...)` against their respective scope.
 * The accordion itself is silent on permissions; scopes the user can't view simply
 * render an empty/locked section via the embedded component's auth checks.
 */
class SharedVariablesAccordion extends Component
{
    use AuthorizesRequests;

    public mixed $context = null;

    public array $expandedScopes = [];

    public string $envTab = 'variables';

    protected $listeners = ['environmentVariableDeleted' => '$refresh', 'refreshEnvs' => '$refresh'];

    public function toggle(string $scope): void
    {
        if (in_array($scope, $this->expandedScopes, true)) {
            $this->expandedScopes = array_values(array_filter($this->expandedScopes, fn ($s) => $s !== $scope));
        } else {
            $this->expandedScopes[] = $scope;
        }
    }

    public function setEnvTab(string $tab): void
    {
        if (in_array($tab, ['variables', 'secrets'], true)) {
            $this->envTab = $tab;
        }
    }

    #[Computed]
    public function environment(): ?Environment
    {
        if ($this->context instanceof Environment) {
            return null; // current scope; not shown as inherited
        }
        if ($this->isResource()) {
            return data_get($this->context, 'environment');
        }

        return null;
    }

    #[Computed]
    public function project(): ?Project
    {
        if ($this->context instanceof Project) {
            return null; // current scope
        }
        if ($this->context instanceof Environment) {
            return $this->context->project;
        }
        if ($this->isResource()) {
            return data_get($this->context, 'environment.project');
        }

        return null;
    }

    #[Computed]
    public function server(): ?Server
    {
        if ($this->context instanceof Server) {
            return null; // current scope
        }
        // Resources expose their server via destination.server, services via direct server relation.
        if ($this->context instanceof Service) {
            return $this->context->server;
        }
        if ($this->context instanceof Application) {
            return data_get($this->context, 'destination.server');
        }
        if ($this->isStandaloneDatabase()) {
            return data_get($this->context, 'destination.server');
        }

        return null;
    }

    #[Computed]
    public function team(): Team
    {
        return currentTeam();
    }

    /**
     * Returns the scopes (in inheritance order: Environment → Project → Server → Team) that are
     * relevant for the current context. Each entry is a string key; the view dispatches to the
     * right embedded component based on the key.
     */
    #[Computed]
    public function scopes(): array
    {
        $available = [];

        if ($this->environment) {
            $available[] = 'environment';
        }
        if ($this->project) {
            $available[] = 'project';
        }
        if ($this->server) {
            $available[] = 'server';
        }
        // Team is always present.
        $available[] = 'team';

        return $available;
    }

    private function isResource(): bool
    {
        return $this->context instanceof Application
            || $this->context instanceof Service
            || $this->isStandaloneDatabase();
    }

    private function isStandaloneDatabase(): bool
    {
        return $this->context instanceof StandalonePostgresql
            || $this->context instanceof StandaloneRedis
            || $this->context instanceof StandaloneKeydb
            || $this->context instanceof StandaloneDragonfly
            || $this->context instanceof StandaloneClickhouse
            || $this->context instanceof StandaloneMongodb
            || $this->context instanceof StandaloneMysql
            || $this->context instanceof StandaloneMariadb;
    }

    public function render()
    {
        return view('livewire.shared.shared-variables-accordion');
    }
}
