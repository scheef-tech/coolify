<?php

use App\Livewire\SharedVariables\Environment\Secrets as SecretsComponent;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\SharedEnvironmentVariable;
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

    $this->project = Project::factory()->create(['team_id' => $this->team->id]);
    $this->environment = Environment::factory()->create([
        'project_id' => $this->project->id,
        'name' => 'staging',
    ]);
});

it('mounts and lists existing secrets only', function () {
    SharedEnvironmentVariable::create([
        'key' => 'JWT_SECRET',
        'value' => 'secret-value',
        'type' => 'environment',
        'team_id' => $this->team->id,
        'environment_id' => $this->environment->id,
        'is_shown_once' => true,
    ]);
    SharedEnvironmentVariable::create([
        'key' => 'PLAIN_VAR',
        'value' => 'plain-value',
        'type' => 'environment',
        'team_id' => $this->team->id,
        'environment_id' => $this->environment->id,
        'is_shown_once' => false,
    ]);

    Livewire::test(SecretsComponent::class, [
        'project_uuid' => $this->project->uuid,
        'environment_uuid' => $this->environment->uuid,
    ])
        ->assertSet('view', 'normal')
        ->assertSet('showingAddForm', false)
        ->assertSee('JWT_SECRET')
        ->assertDontSee('PLAIN_VAR');
});

it('toggles the add form open and closed', function () {
    Livewire::test(SecretsComponent::class, [
        'project_uuid' => $this->project->uuid,
        'environment_uuid' => $this->environment->uuid,
    ])
        ->call('toggleAddForm')
        ->assertSet('showingAddForm', true)
        ->call('toggleAddForm')
        ->assertSet('showingAddForm', false);
});

it('creates a secret with is_shown_once=true', function () {
    Livewire::test(SecretsComponent::class, [
        'project_uuid' => $this->project->uuid,
        'environment_uuid' => $this->environment->uuid,
    ])
        ->set('newKey', 'NEW_SECRET')
        ->set('newValue', 'value-once')
        ->set('newComment', 'For tests')
        ->call('saveSecret')
        ->assertHasNoErrors();

    $secret = SharedEnvironmentVariable::where('key', 'NEW_SECRET')
        ->where('environment_id', $this->environment->id)
        ->first();

    expect($secret)->not->toBeNull();
    expect((bool) $secret->is_shown_once)->toBeTrue();
    expect($secret->value)->toBe('value-once');
    expect($secret->comment)->toBe('For tests');
});

it('rejects creating a secret with an invalid key pattern', function () {
    Livewire::test(SecretsComponent::class, [
        'project_uuid' => $this->project->uuid,
        'environment_uuid' => $this->environment->uuid,
    ])
        ->set('newKey', 'lowercase_key')
        ->set('newValue', 'whatever')
        ->call('saveSecret')
        ->assertHasErrors(['newKey']);

    expect(SharedEnvironmentVariable::where('key', 'lowercase_key')->first())->toBeNull();
});

it('refuses to overwrite an existing non-secret shared variable', function () {
    SharedEnvironmentVariable::create([
        'key' => 'PLAIN_KEY',
        'value' => 'plain',
        'type' => 'environment',
        'team_id' => $this->team->id,
        'environment_id' => $this->environment->id,
        'is_shown_once' => false,
    ]);

    Livewire::test(SecretsComponent::class, [
        'project_uuid' => $this->project->uuid,
        'environment_uuid' => $this->environment->uuid,
    ])
        ->set('newKey', 'PLAIN_KEY')
        ->set('newValue', 'attempt-overwrite')
        ->call('saveSecret');

    $stillPlain = SharedEnvironmentVariable::where('key', 'PLAIN_KEY')
        ->where('environment_id', $this->environment->id)
        ->first();
    expect((bool) $stillPlain->is_shown_once)->toBeFalse();
    expect($stillPlain->value)->toBe('plain');
});

it('switches between normal and developer view', function () {
    Livewire::test(SecretsComponent::class, [
        'project_uuid' => $this->project->uuid,
        'environment_uuid' => $this->environment->uuid,
    ])
        ->assertSet('view', 'normal')
        ->call('switchView')
        ->assertSet('view', 'dev')
        ->call('switchView')
        ->assertSet('view', 'normal');
});

it('developer view textarea shows keys with empty values', function () {
    SharedEnvironmentVariable::create([
        'key' => 'API_KEY',
        'value' => 'real-value',
        'type' => 'environment',
        'team_id' => $this->team->id,
        'environment_id' => $this->environment->id,
        'is_shown_once' => true,
    ]);

    $component = Livewire::test(SecretsComponent::class, [
        'project_uuid' => $this->project->uuid,
        'environment_uuid' => $this->environment->uuid,
    ]);

    $variables = $component->get('variables');
    expect($variables)->toContain('API_KEY=');
    expect($variables)->not->toContain('real-value');
});

it('developer view bulk submit upserts non-empty values and skips empty ones', function () {
    SharedEnvironmentVariable::create([
        'key' => 'EXISTING',
        'value' => 'old',
        'type' => 'environment',
        'team_id' => $this->team->id,
        'environment_id' => $this->environment->id,
        'is_shown_once' => true,
    ]);

    Livewire::test(SecretsComponent::class, [
        'project_uuid' => $this->project->uuid,
        'environment_uuid' => $this->environment->uuid,
    ])
        ->set('variables', "EXISTING=new\nNEW_ONE=fresh\nUNTOUCHED=")
        ->call('submitDevView')
        ->assertHasNoErrors();

    $existing = SharedEnvironmentVariable::where('key', 'EXISTING')->where('environment_id', $this->environment->id)->first();
    expect($existing->value)->toBe('new');

    $newOne = SharedEnvironmentVariable::where('key', 'NEW_ONE')->where('environment_id', $this->environment->id)->first();
    expect($newOne)->not->toBeNull();
    expect($newOne->value)->toBe('fresh');
    expect((bool) $newOne->is_shown_once)->toBeTrue();

    expect(SharedEnvironmentVariable::where('key', 'UNTOUCHED')->where('environment_id', $this->environment->id)->first())->toBeNull();
});

it('developer view with deleteMissingOnBulk removes absent secrets', function () {
    SharedEnvironmentVariable::create([
        'key' => 'KEEP',
        'value' => 'k',
        'type' => 'environment',
        'team_id' => $this->team->id,
        'environment_id' => $this->environment->id,
        'is_shown_once' => true,
    ]);
    SharedEnvironmentVariable::create([
        'key' => 'DROP',
        'value' => 'd',
        'type' => 'environment',
        'team_id' => $this->team->id,
        'environment_id' => $this->environment->id,
        'is_shown_once' => true,
    ]);

    Livewire::test(SecretsComponent::class, [
        'project_uuid' => $this->project->uuid,
        'environment_uuid' => $this->environment->uuid,
    ])
        ->set('variables', 'KEEP=still-here')
        ->set('deleteMissingOnBulk', true)
        ->call('submitDevView');

    expect(SharedEnvironmentVariable::where('key', 'KEEP')->where('environment_id', $this->environment->id)->first())->not->toBeNull();
    expect(SharedEnvironmentVariable::where('key', 'DROP')->where('environment_id', $this->environment->id)->first())->toBeNull();
});
