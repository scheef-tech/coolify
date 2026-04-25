<?php

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

    session(['currentTeam' => $this->team]);

    $this->token = $this->user->createToken('test-token', ['*']);
    $this->bearerToken = $this->token->plainTextToken;

    $this->project = Project::factory()->create(['team_id' => $this->team->id]);
    $this->environment = Environment::factory()->create([
        'project_id' => $this->project->id,
        'name' => 'staging',
    ]);
});

describe('GET /api/v1/projects/{uuid}/secrets', function () {
    test('lists project scoped environment secrets without values', function () {
        SharedEnvironmentVariable::create([
            'key' => 'JWT_SECRET',
            'value' => 'top-secret',
            'type' => 'environment',
            'team_id' => $this->team->id,
            'environment_id' => $this->environment->id,
            'is_shown_once' => true,
        ]);
        SharedEnvironmentVariable::create([
            'key' => 'PLAIN_ENV',
            'value' => 'plain-value',
            'type' => 'environment',
            'team_id' => $this->team->id,
            'environment_id' => $this->environment->id,
            'is_shown_once' => false,
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->bearerToken,
            'Content-Type' => 'application/json',
        ])->getJson("/api/v1/projects/{$this->project->uuid}/secrets?environment={$this->environment->uuid}");

        $response->assertStatus(200);
        $response->assertJsonFragment(['key' => 'JWT_SECRET']);
        $response->assertJsonMissing(['key' => 'PLAIN_ENV']);
        $response->assertJsonMissingPath('0.value');
    });

    test('returns 422 when environment query parameter is missing', function () {
        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->bearerToken,
            'Content-Type' => 'application/json',
        ])->getJson("/api/v1/projects/{$this->project->uuid}/secrets");

        $response->assertStatus(422);
    });

    test('does not resolve environments outside target project', function () {
        $otherProject = Project::factory()->create(['team_id' => $this->team->id]);
        $otherEnvironment = Environment::factory()->create([
            'project_id' => $otherProject->id,
            'name' => 'isolated',
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->bearerToken,
            'Content-Type' => 'application/json',
        ])->getJson("/api/v1/projects/{$this->project->uuid}/secrets?environment={$otherEnvironment->uuid}");

        $response->assertNotFound();
    });
});

describe('PUT /api/v1/projects/{uuid}/secrets/{key}', function () {
    test('creates and updates a secret in the selected environment', function () {
        $headers = [
            'Authorization' => 'Bearer '.$this->bearerToken,
            'Content-Type' => 'application/json',
        ];

        $createResponse = $this->withHeaders($headers)->putJson(
            "/api/v1/projects/{$this->project->uuid}/secrets/API_TOKEN?environment={$this->environment->name}",
            [
                'value' => 'initial-value',
                'comment' => 'Token for API access',
            ]
        );

        $createResponse->assertStatus(201);
        $createResponse->assertJsonFragment(['key' => 'API_TOKEN']);
        $createResponse->assertJsonMissing(['value' => 'initial-value']);

        $secret = SharedEnvironmentVariable::where('key', 'API_TOKEN')
            ->where('environment_id', $this->environment->id)
            ->where('team_id', $this->team->id)
            ->first();

        expect($secret)->not->toBeNull();
        expect($secret->value)->toBe('initial-value');
        expect((bool) $secret->is_shown_once)->toBeTrue();

        $updateResponse = $this->withHeaders($headers)->putJson(
            "/api/v1/projects/{$this->project->uuid}/secrets/API_TOKEN?environment={$this->environment->name}",
            [
                'value' => 'updated-value',
            ]
        );

        $updateResponse->assertStatus(201);

        $secret->refresh();
        expect($secret->value)->toBe('updated-value');
        expect((bool) $secret->is_shown_once)->toBeTrue();
    });

    test('does not allow overwriting a non-secret shared variable', function () {
        SharedEnvironmentVariable::create([
            'key' => 'PLAIN_ENV',
            'value' => 'plain-value',
            'type' => 'environment',
            'team_id' => $this->team->id,
            'environment_id' => $this->environment->id,
            'is_shown_once' => false,
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->bearerToken,
            'Content-Type' => 'application/json',
        ])->putJson(
            "/api/v1/projects/{$this->project->uuid}/secrets/PLAIN_ENV?environment={$this->environment->name}",
            [
                'value' => 'should-fail',
            ]
        );

        $response->assertStatus(409);
    });
});

describe('DELETE /api/v1/projects/{uuid}/secrets/{key}', function () {
    test('deletes a secret in the selected environment', function () {
        $secret = SharedEnvironmentVariable::create([
            'key' => 'DELETE_ME',
            'value' => 'remove-this',
            'type' => 'environment',
            'team_id' => $this->team->id,
            'environment_id' => $this->environment->id,
            'is_shown_once' => true,
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->bearerToken,
            'Content-Type' => 'application/json',
        ])->deleteJson("/api/v1/projects/{$this->project->uuid}/secrets/{$secret->key}?environment={$this->environment->uuid}");

        $response->assertStatus(200);
        $response->assertJson(['message' => 'Secret deleted.']);
        expect(SharedEnvironmentVariable::where('id', $secret->id)->first())->toBeNull();
    });
});

describe('GET /api/v1/projects/{uuid}/secrets/values', function () {
    test('returns 403 DEV_PULL_NOT_ALLOWED when env is not dev-pullable', function () {
        SharedEnvironmentVariable::create([
            'key' => 'JWT_SECRET',
            'value' => 'top-secret',
            'type' => 'environment',
            'team_id' => $this->team->id,
            'environment_id' => $this->environment->id,
            'is_shown_once' => true,
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->bearerToken,
            'Content-Type' => 'application/json',
        ])->getJson("/api/v1/projects/{$this->project->uuid}/secrets/values?environment={$this->environment->name}");

        $response->assertStatus(403);
        $response->assertJsonFragment(['error' => 'DEV_PULL_NOT_ALLOWED']);
    });

    test('returns secret values when env is dev-pullable', function () {
        $this->environment->is_dev_pullable = true;
        $this->environment->save();

        SharedEnvironmentVariable::create([
            'key' => 'JWT_SECRET',
            'value' => 'top-secret',
            'type' => 'environment',
            'team_id' => $this->team->id,
            'environment_id' => $this->environment->id,
            'is_shown_once' => true,
        ]);
        SharedEnvironmentVariable::create([
            'key' => 'PLAIN_VAR',
            'value' => 'plain',
            'type' => 'environment',
            'team_id' => $this->team->id,
            'environment_id' => $this->environment->id,
            'is_shown_once' => false,
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->bearerToken,
            'Content-Type' => 'application/json',
        ])->getJson("/api/v1/projects/{$this->project->uuid}/secrets/values?environment={$this->environment->name}");

        $response->assertStatus(200);
        $response->assertJsonFragment(['key' => 'JWT_SECRET']);
        $response->assertJsonFragment(['value' => 'top-secret']);
        // Non-secret shared variables must not appear.
        $response->assertJsonMissing(['key' => 'PLAIN_VAR']);
        $response->assertJsonPath('environment.is_dev_pullable', true);
    });
});

describe('GET /api/v1/projects/{uuid}/secrets/{key}/value', function () {
    test('returns 403 when env is not dev-pullable', function () {
        SharedEnvironmentVariable::create([
            'key' => 'JWT_SECRET',
            'value' => 'top-secret',
            'type' => 'environment',
            'team_id' => $this->team->id,
            'environment_id' => $this->environment->id,
            'is_shown_once' => true,
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->bearerToken,
            'Content-Type' => 'application/json',
        ])->getJson("/api/v1/projects/{$this->project->uuid}/secrets/JWT_SECRET/value?environment={$this->environment->name}");

        $response->assertStatus(403);
        $response->assertJsonFragment(['error' => 'DEV_PULL_NOT_ALLOWED']);
    });

    test('returns the value when env is dev-pullable', function () {
        $this->environment->is_dev_pullable = true;
        $this->environment->save();

        SharedEnvironmentVariable::create([
            'key' => 'JWT_SECRET',
            'value' => 'top-secret',
            'type' => 'environment',
            'team_id' => $this->team->id,
            'environment_id' => $this->environment->id,
            'is_shown_once' => true,
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->bearerToken,
            'Content-Type' => 'application/json',
        ])->getJson("/api/v1/projects/{$this->project->uuid}/secrets/JWT_SECRET/value?environment={$this->environment->name}");

        $response->assertStatus(200);
        $response->assertJsonFragment(['key' => 'JWT_SECRET']);
        $response->assertJsonFragment(['value' => 'top-secret']);
    });

    test('returns 404 for unknown key on a dev-pullable env', function () {
        $this->environment->is_dev_pullable = true;
        $this->environment->save();

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->bearerToken,
            'Content-Type' => 'application/json',
        ])->getJson("/api/v1/projects/{$this->project->uuid}/secrets/MISSING/value?environment={$this->environment->name}");

        $response->assertStatus(404);
    });

    test('does not return values for non-secret shared variables', function () {
        $this->environment->is_dev_pullable = true;
        $this->environment->save();

        SharedEnvironmentVariable::create([
            'key' => 'PLAIN_VAR',
            'value' => 'plain',
            'type' => 'environment',
            'team_id' => $this->team->id,
            'environment_id' => $this->environment->id,
            'is_shown_once' => false,
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->bearerToken,
            'Content-Type' => 'application/json',
        ])->getJson("/api/v1/projects/{$this->project->uuid}/secrets/PLAIN_VAR/value?environment={$this->environment->name}");

        $response->assertStatus(404);
    });
});

describe('PATCH /api/v1/projects/{uuid}/secrets/bulk', function () {
    test('creates and updates secrets in a single call', function () {
        SharedEnvironmentVariable::create([
            'key' => 'EXISTING_SECRET',
            'value' => 'old-value',
            'type' => 'environment',
            'team_id' => $this->team->id,
            'environment_id' => $this->environment->id,
            'is_shown_once' => true,
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->bearerToken,
            'Content-Type' => 'application/json',
        ])->patchJson(
            "/api/v1/projects/{$this->project->uuid}/secrets/bulk?environment={$this->environment->name}",
            [
                'data' => [
                    ['key' => 'EXISTING_SECRET', 'value' => 'new-value'],
                    ['key' => 'NEW_SECRET', 'value' => 'fresh', 'comment' => 'first time'],
                ],
            ]
        );

        $response->assertStatus(200);
        $response->assertJsonMissing(['value' => 'new-value']);
        $response->assertJsonMissing(['value' => 'fresh']);
        expect($response->json('upserted'))->toHaveCount(2);
        expect($response->json('deleted'))->toBe([]);

        $existing = SharedEnvironmentVariable::where('key', 'EXISTING_SECRET')
            ->where('environment_id', $this->environment->id)
            ->first();
        expect($existing->value)->toBe('new-value');
        expect((bool) $existing->is_shown_once)->toBeTrue();

        $created = SharedEnvironmentVariable::where('key', 'NEW_SECRET')
            ->where('environment_id', $this->environment->id)
            ->first();
        expect($created)->not->toBeNull();
        expect($created->value)->toBe('fresh');
        expect((bool) $created->is_shown_once)->toBeTrue();
    });

    test('delete_missing=true removes existing secrets absent from the submitted set', function () {
        SharedEnvironmentVariable::create([
            'key' => 'KEEP_ME',
            'value' => 'keep',
            'type' => 'environment',
            'team_id' => $this->team->id,
            'environment_id' => $this->environment->id,
            'is_shown_once' => true,
        ]);
        SharedEnvironmentVariable::create([
            'key' => 'DROP_ME',
            'value' => 'drop',
            'type' => 'environment',
            'team_id' => $this->team->id,
            'environment_id' => $this->environment->id,
            'is_shown_once' => true,
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->bearerToken,
            'Content-Type' => 'application/json',
        ])->patchJson(
            "/api/v1/projects/{$this->project->uuid}/secrets/bulk?environment={$this->environment->name}",
            [
                'data' => [
                    ['key' => 'KEEP_ME', 'value' => 'still-here'],
                ],
                'delete_missing' => true,
            ]
        );

        $response->assertStatus(200);
        expect($response->json('deleted'))->toBe(['DROP_ME']);

        expect(SharedEnvironmentVariable::where('key', 'KEEP_ME')->where('environment_id', $this->environment->id)->first())->not->toBeNull();
        expect(SharedEnvironmentVariable::where('key', 'DROP_ME')->where('environment_id', $this->environment->id)->first())->toBeNull();
    });

    test('delete_missing default (false) preserves existing secrets', function () {
        SharedEnvironmentVariable::create([
            'key' => 'PRESERVED',
            'value' => 'still-here',
            'type' => 'environment',
            'team_id' => $this->team->id,
            'environment_id' => $this->environment->id,
            'is_shown_once' => true,
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->bearerToken,
            'Content-Type' => 'application/json',
        ])->patchJson(
            "/api/v1/projects/{$this->project->uuid}/secrets/bulk?environment={$this->environment->name}",
            [
                'data' => [
                    ['key' => 'OTHER_SECRET', 'value' => 'new'],
                ],
            ]
        );

        $response->assertStatus(200);
        expect($response->json('deleted'))->toBe([]);
        expect(SharedEnvironmentVariable::where('key', 'PRESERVED')->where('environment_id', $this->environment->id)->first())->not->toBeNull();
    });

    test('rejects bulk when any key collides with a non-secret shared variable', function () {
        SharedEnvironmentVariable::create([
            'key' => 'PLAIN_VAR',
            'value' => 'plain',
            'type' => 'environment',
            'team_id' => $this->team->id,
            'environment_id' => $this->environment->id,
            'is_shown_once' => false,
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->bearerToken,
            'Content-Type' => 'application/json',
        ])->patchJson(
            "/api/v1/projects/{$this->project->uuid}/secrets/bulk?environment={$this->environment->name}",
            [
                'data' => [
                    ['key' => 'NEW_SECRET', 'value' => 'fine'],
                    ['key' => 'PLAIN_VAR', 'value' => 'attempt-overwrite'],
                ],
            ]
        );

        $response->assertStatus(409);
        $response->assertJsonFragment(['conflicting_keys' => ['PLAIN_VAR']]);
        // No partial writes — the safe key must not have been created either.
        expect(SharedEnvironmentVariable::where('key', 'NEW_SECRET')->where('environment_id', $this->environment->id)->first())->toBeNull();
    });

    test('rejects invalid key pattern with 422', function () {
        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->bearerToken,
            'Content-Type' => 'application/json',
        ])->patchJson(
            "/api/v1/projects/{$this->project->uuid}/secrets/bulk?environment={$this->environment->name}",
            [
                'data' => [
                    ['key' => 'lowercase_key', 'value' => 'x'],
                ],
            ]
        );

        $response->assertStatus(422);
    });

    test('rejects empty data array with 422', function () {
        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->bearerToken,
            'Content-Type' => 'application/json',
        ])->patchJson(
            "/api/v1/projects/{$this->project->uuid}/secrets/bulk?environment={$this->environment->name}",
            ['data' => []]
        );

        $response->assertStatus(422);
    });
});
