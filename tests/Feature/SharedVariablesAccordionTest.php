<?php

use App\Livewire\Shared\SharedVariablesAccordion;
use App\Models\Application;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\PreventRequestsDuringMaintenance;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    Config::set('cache.default', 'array');
    Config::set('app.maintenance.driver', 'file');
    Config::set('app.maintenance.store', null);
    Cache::clearResolvedInstances();
    $this->withoutMiddleware(PreventRequestsDuringMaintenance::class);
    InstanceSettings::unguarded(function () {
        InstanceSettings::query()->create(['id' => 0]);
    });

    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);
    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);

    $this->server = Server::factory()->create(['team_id' => $this->team->id]);
    $this->destination = StandaloneDocker::query()->where('server_id', $this->server->id)->firstOrFail();
    $this->project = Project::factory()->create(['team_id' => $this->team->id]);
    $this->environment = Environment::factory()->create([
        'project_id' => $this->project->id,
        'name' => 'staging',
    ]);
});

it('shows all four scopes when context is an application', function () {
    $app = Application::factory()->create([
        'environment_id' => $this->environment->id,
        'destination_id' => $this->destination->id,
        'destination_type' => $this->destination->getMorphClass(),
    ]);

    Livewire::test(SharedVariablesAccordion::class, ['context' => $app])
        ->assertSet('expandedScopes', [])
        ->assertSet('envTab', 'variables')
        ->assertSee('Environment shared variables')
        ->assertSee('Project shared variables')
        ->assertSee('Server shared variables')
        ->assertSee('Team shared variables');
});

it('shows project + server + team when context is an environment', function () {
    $component = Livewire::test(SharedVariablesAccordion::class, ['context' => $this->environment]);

    $scopes = $component->instance()->scopes();
    expect($scopes)->toContain('project');
    expect($scopes)->toContain('team');
    // Environment is the current scope, should not be in the inherited list
    expect($scopes)->not->toContain('environment');
});

it('shows only team when context is a server', function () {
    $component = Livewire::test(SharedVariablesAccordion::class, ['context' => $this->server]);

    $scopes = $component->instance()->scopes();
    expect($scopes)->toBe(['team']);
});

it('toggles a scope into and out of the expanded list', function () {
    $app = Application::factory()->create([
        'environment_id' => $this->environment->id,
        'destination_id' => $this->destination->id,
        'destination_type' => $this->destination->getMorphClass(),
    ]);

    Livewire::test(SharedVariablesAccordion::class, ['context' => $app])
        ->assertSet('expandedScopes', [])
        ->call('toggle', 'team')
        ->assertSet('expandedScopes', ['team'])
        ->call('toggle', 'project')
        ->assertSet('expandedScopes', ['team', 'project'])
        ->call('toggle', 'team')
        ->assertSet('expandedScopes', ['project']);
});

it('switches the environment sub-tab between variables and secrets', function () {
    $app = Application::factory()->create([
        'environment_id' => $this->environment->id,
        'destination_id' => $this->destination->id,
        'destination_type' => $this->destination->getMorphClass(),
    ]);

    Livewire::test(SharedVariablesAccordion::class, ['context' => $app])
        ->assertSet('envTab', 'variables')
        ->call('setEnvTab', 'secrets')
        ->assertSet('envTab', 'secrets')
        ->call('setEnvTab', 'variables')
        ->assertSet('envTab', 'variables');
});

it('rejects setEnvTab values outside the allowed set', function () {
    $app = Application::factory()->create([
        'environment_id' => $this->environment->id,
        'destination_id' => $this->destination->id,
        'destination_type' => $this->destination->getMorphClass(),
    ]);

    Livewire::test(SharedVariablesAccordion::class, ['context' => $app])
        ->call('setEnvTab', 'malicious-value')
        ->assertSet('envTab', 'variables');
});

it('resolves project from environment context', function () {
    $component = Livewire::test(SharedVariablesAccordion::class, ['context' => $this->environment]);

    expect($component->instance()->project()->id)->toBe($this->project->id);
});

it('resolves environment + project + server from application context', function () {
    $app = Application::factory()->create([
        'environment_id' => $this->environment->id,
        'destination_id' => $this->destination->id,
        'destination_type' => $this->destination->getMorphClass(),
    ]);

    $component = Livewire::test(SharedVariablesAccordion::class, ['context' => $app]);
    $instance = $component->instance();

    expect($instance->environment()->id)->toBe($this->environment->id);
    expect($instance->project()->id)->toBe($this->project->id);
    expect($instance->server()->id)->toBe($this->server->id);
});
