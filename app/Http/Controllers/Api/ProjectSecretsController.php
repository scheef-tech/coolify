<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Environment;
use App\Models\Project;
use App\Models\SharedEnvironmentVariable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use OpenApi\Attributes as OA;

class ProjectSecretsController extends Controller
{
    private const SECRET_KEY_PATTERN = '/^[A-Z][A-Z0-9_]*$/';

    private function resolveProjectAndEnvironment(Request $request, string $projectUuid): Project|JsonResponse
    {
        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }

        $project = Project::whereTeamId($teamId)->whereUuid($projectUuid)->first();
        if (! $project) {
            return response()->json(['message' => 'Project not found.'], 404);
        }

        $environmentIdentifier = $request->query('environment');
        if (blank($environmentIdentifier)) {
            return response()->json([
                'message' => 'Validation failed.',
                'errors' => [
                    'environment' => ['The environment query parameter is required.'],
                ],
            ], 422);
        }

        $environment = $project->environments()
            ->where(function ($query) use ($environmentIdentifier) {
                $query->where('name', $environmentIdentifier)
                    ->orWhere('uuid', $environmentIdentifier);
            })
            ->first();

        if (! $environment) {
            return response()->json(['message' => 'Environment not found.'], 404);
        }

        $project->setRelation('resolved_environment', $environment);

        return $project;
    }

    private function getResolvedEnvironment(Project $project): Environment
    {
        /** @var Environment $environment */
        $environment = $project->getRelation('resolved_environment');

        return $environment;
    }

    private function resolveSecretKey(string $rawKey): string|JsonResponse
    {
        $key = str($rawKey)->trim()->replace(' ', '_')->value();
        if (blank($key) || ! preg_match(self::SECRET_KEY_PATTERN, $key)) {
            return response()->json([
                'message' => 'Validation failed.',
                'errors' => [
                    'key' => ['The key must match ^[A-Z][A-Z0-9_]*$.'],
                ],
            ], 422);
        }

        return $key;
    }

    private function presentSecret(SharedEnvironmentVariable $secret): array
    {
        return [
            'key' => $secret->key,
            'comment' => $secret->comment,
            'is_literal' => $secret->is_literal,
            'is_multiline' => $secret->is_multiline,
            'is_shown_once' => $secret->is_shown_once,
            'created_at' => $secret->created_at,
            'updated_at' => $secret->updated_at,
        ];
    }

    private function ensureEnvironmentIsDevPullable(Environment $environment): ?JsonResponse
    {
        if (! $environment->is_dev_pullable) {
            return response()->json([
                'message' => 'Environment is not dev-pullable. Enable the dev-pullable flag on this environment in the Coolify UI to allow value reads.',
                'error' => 'DEV_PULL_NOT_ALLOWED',
            ], 403);
        }

        return null;
    }

    #[OA\Get(
        summary: 'List Project Secrets',
        description: 'List environment-scoped shared secrets for a project.',
        path: '/projects/{uuid}/secrets',
        operationId: 'list-project-secrets',
        security: [
            ['bearerAuth' => []],
        ],
        tags: ['Projects'],
        parameters: [
            new OA\Parameter(
                name: 'uuid',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'string'),
                description: 'Project UUID.'
            ),
            new OA\Parameter(
                name: 'environment',
                in: 'query',
                required: true,
                schema: new OA\Schema(type: 'string'),
                description: 'Environment name or UUID.'
            ),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Project secrets listed.'),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 404, ref: '#/components/responses/404'),
            new OA\Response(response: 422, ref: '#/components/responses/422'),
        ]
    )]
    public function secrets(Request $request, string $uuid)
    {
        $project = $this->resolveProjectAndEnvironment($request, $uuid);
        if ($project instanceof JsonResponse) {
            return $project;
        }

        $this->authorize('view', $project);
        $environment = $this->getResolvedEnvironment($project);
        $teamId = getTeamIdFromToken();

        $secrets = $environment->environment_variables()
            ->where('team_id', $teamId)
            ->where('is_shown_once', true)
            ->orderBy('key')
            ->get()
            ->map(fn ($secret) => $this->presentSecret($secret));

        return response()->json($secrets);
    }

    #[OA\Put(
        summary: 'Upsert Project Secret',
        description: 'Create or update an environment-scoped shared secret for a project.',
        path: '/projects/{uuid}/secrets/{key}',
        operationId: 'upsert-project-secret',
        security: [
            ['bearerAuth' => []],
        ],
        tags: ['Projects'],
        parameters: [
            new OA\Parameter(
                name: 'uuid',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'string'),
                description: 'Project UUID.'
            ),
            new OA\Parameter(
                name: 'key',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'string'),
                description: 'Secret key.'
            ),
            new OA\Parameter(
                name: 'environment',
                in: 'query',
                required: true,
                schema: new OA\Schema(type: 'string'),
                description: 'Environment name or UUID.'
            ),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\MediaType(
                mediaType: 'application/json',
                schema: new OA\Schema(
                    type: 'object',
                    required: ['value'],
                    properties: [
                        'value' => ['type' => 'string'],
                        'comment' => ['type' => 'string', 'nullable' => true],
                        'is_literal' => ['type' => 'boolean'],
                        'is_multiline' => ['type' => 'boolean'],
                    ],
                )
            )
        ),
        responses: [
            new OA\Response(response: 201, description: 'Project secret upserted.'),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 409, description: 'Secret key collides with an existing non-secret variable.'),
            new OA\Response(response: 404, ref: '#/components/responses/404'),
            new OA\Response(response: 422, ref: '#/components/responses/422'),
        ]
    )]
    public function upsert_secret(Request $request, string $uuid, string $key)
    {
        $project = $this->resolveProjectAndEnvironment($request, $uuid);
        if ($project instanceof JsonResponse) {
            return $project;
        }

        $this->authorize('update', $project);

        $validator = customApiValidator($request->all(), [
            'value' => 'required|string',
            'comment' => 'string|nullable|max:256',
            'is_literal' => 'boolean',
            'is_multiline' => 'boolean',
        ]);
        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $environment = $this->getResolvedEnvironment($project);
        $teamId = getTeamIdFromToken();
        $key = $this->resolveSecretKey($key);
        if ($key instanceof JsonResponse) {
            return $key;
        }

        $existingVariable = $environment->environment_variables()
            ->where('key', $key)
            ->where('team_id', $teamId)
            ->first();

        if ($existingVariable && ! $existingVariable->is_shown_once) {
            return response()->json([
                'message' => 'Cannot overwrite an existing non-secret shared variable with the secrets API.',
            ], 409);
        }

        $secret = $environment->environment_variables()->updateOrCreate(
            ['key' => $key, 'team_id' => $teamId],
            [
                'value' => $request->value,
                'comment' => $request->comment,
                'team_id' => $teamId,
                'type' => 'environment',
                'is_literal' => $request->boolean('is_literal', false),
                'is_multiline' => $request->boolean('is_multiline', false),
                'is_shown_once' => true,
            ]
        );

        return response()->json($this->presentSecret($secret))->setStatusCode(201);
    }

    #[OA\Get(
        summary: 'Fetch Project Secret Values (Dev-Pullable Only)',
        description: 'Fetch all secret values for a dev-pullable environment. Returns 403 with DEV_PULL_NOT_ALLOWED if the environment is not marked dev-pullable. Used by `coolster pull`.',
        path: '/projects/{uuid}/secrets/values',
        operationId: 'fetch-project-secret-values',
        security: [
            ['bearerAuth' => []],
        ],
        tags: ['Projects'],
        parameters: [
            new OA\Parameter(name: 'uuid', in: 'path', required: true, schema: new OA\Schema(type: 'string'), description: 'Project UUID.'),
            new OA\Parameter(name: 'environment', in: 'query', required: true, schema: new OA\Schema(type: 'string'), description: 'Environment name or UUID.'),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Secret values returned.'),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 403, description: 'Environment is not dev-pullable.'),
            new OA\Response(response: 404, ref: '#/components/responses/404'),
            new OA\Response(response: 422, ref: '#/components/responses/422'),
        ]
    )]
    public function fetch_secret_values(Request $request, string $uuid)
    {
        $project = $this->resolveProjectAndEnvironment($request, $uuid);
        if ($project instanceof JsonResponse) {
            return $project;
        }

        $this->authorize('view', $project);
        $environment = $this->getResolvedEnvironment($project);

        $blocked = $this->ensureEnvironmentIsDevPullable($environment);
        if ($blocked !== null) {
            return $blocked;
        }

        $teamId = getTeamIdFromToken();
        $secrets = $environment->environment_variables()
            ->where('team_id', $teamId)
            ->where('is_shown_once', true)
            ->orderBy('key')
            ->get()
            ->map(fn ($secret) => [
                'key' => $secret->key,
                'value' => $secret->value,
                'is_literal' => $secret->is_literal,
                'is_multiline' => $secret->is_multiline,
                'comment' => $secret->comment,
                'updated_at' => $secret->updated_at,
            ]);

        return response()->json([
            'environment' => [
                'name' => $environment->name,
                'uuid' => $environment->uuid,
                'is_dev_pullable' => true,
            ],
            'secrets' => $secrets,
        ]);
    }

    #[OA\Get(
        summary: 'Fetch Single Project Secret Value (Dev-Pullable Only)',
        description: 'Fetch a single secret value by key. Returns 403 with DEV_PULL_NOT_ALLOWED if the environment is not marked dev-pullable. Used by `coolster secret reveal`.',
        path: '/projects/{uuid}/secrets/{key}/value',
        operationId: 'fetch-project-secret-value',
        security: [
            ['bearerAuth' => []],
        ],
        tags: ['Projects'],
        parameters: [
            new OA\Parameter(name: 'uuid', in: 'path', required: true, schema: new OA\Schema(type: 'string'), description: 'Project UUID.'),
            new OA\Parameter(name: 'key', in: 'path', required: true, schema: new OA\Schema(type: 'string'), description: 'Secret key.'),
            new OA\Parameter(name: 'environment', in: 'query', required: true, schema: new OA\Schema(type: 'string'), description: 'Environment name or UUID.'),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Secret value returned.'),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 403, description: 'Environment is not dev-pullable.'),
            new OA\Response(response: 404, ref: '#/components/responses/404'),
            new OA\Response(response: 422, ref: '#/components/responses/422'),
        ]
    )]
    public function fetch_secret_value(Request $request, string $uuid, string $key)
    {
        $project = $this->resolveProjectAndEnvironment($request, $uuid);
        if ($project instanceof JsonResponse) {
            return $project;
        }

        $this->authorize('view', $project);
        $environment = $this->getResolvedEnvironment($project);

        $blocked = $this->ensureEnvironmentIsDevPullable($environment);
        if ($blocked !== null) {
            return $blocked;
        }

        $teamId = getTeamIdFromToken();
        $resolvedKey = $this->resolveSecretKey($key);
        if ($resolvedKey instanceof JsonResponse) {
            return $resolvedKey;
        }

        $secret = $environment->environment_variables()
            ->where('team_id', $teamId)
            ->where('is_shown_once', true)
            ->where('key', $resolvedKey)
            ->first();

        if (! $secret) {
            return response()->json(['message' => 'Secret not found.'], 404);
        }

        return response()->json([
            'key' => $secret->key,
            'value' => $secret->value,
            'is_literal' => $secret->is_literal,
            'is_multiline' => $secret->is_multiline,
            'comment' => $secret->comment,
            'updated_at' => $secret->updated_at,
        ]);
    }

    #[OA\Patch(
        summary: 'Bulk Upsert Project Secrets',
        description: 'Create or update many environment-scoped shared secrets in one call. Optionally deletes existing secrets whose keys are absent from the submitted set.',
        path: '/projects/{uuid}/secrets/bulk',
        operationId: 'bulk-upsert-project-secrets',
        security: [
            ['bearerAuth' => []],
        ],
        tags: ['Projects'],
        parameters: [
            new OA\Parameter(
                name: 'uuid',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'string'),
                description: 'Project UUID.'
            ),
            new OA\Parameter(
                name: 'environment',
                in: 'query',
                required: true,
                schema: new OA\Schema(type: 'string'),
                description: 'Environment name or UUID.'
            ),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\MediaType(
                mediaType: 'application/json',
                schema: new OA\Schema(
                    type: 'object',
                    required: ['data'],
                    properties: [
                        'data' => [
                            'type' => 'array',
                            'items' => new OA\Schema(
                                type: 'object',
                                required: ['key', 'value'],
                                properties: [
                                    'key' => ['type' => 'string'],
                                    'value' => ['type' => 'string'],
                                    'comment' => ['type' => 'string', 'nullable' => true],
                                    'is_literal' => ['type' => 'boolean'],
                                    'is_multiline' => ['type' => 'boolean'],
                                ],
                            ),
                        ],
                        'delete_missing' => [
                            'type' => 'boolean',
                            'default' => false,
                            'description' => 'When true, delete existing secrets whose keys are not in the submitted set.',
                        ],
                    ],
                )
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Project secrets upserted.'),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 409, description: 'One or more keys collide with non-secret shared variables.'),
            new OA\Response(response: 404, ref: '#/components/responses/404'),
            new OA\Response(response: 422, ref: '#/components/responses/422'),
        ]
    )]
    public function bulk_upsert_secrets(Request $request, string $uuid)
    {
        $project = $this->resolveProjectAndEnvironment($request, $uuid);
        if ($project instanceof JsonResponse) {
            return $project;
        }

        $this->authorize('update', $project);

        $validator = customApiValidator($request->all(), [
            'data' => 'required|array|min:1',
            'data.*.key' => 'required|string',
            'data.*.value' => 'required|string',
            'data.*.comment' => 'nullable|string|max:256',
            'data.*.is_literal' => 'boolean',
            'data.*.is_multiline' => 'boolean',
            'delete_missing' => 'boolean',
        ]);
        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $environment = $this->getResolvedEnvironment($project);
        $teamId = getTeamIdFromToken();

        $resolved = [];
        foreach ($request->input('data', []) as $item) {
            $key = $this->resolveSecretKey($item['key']);
            if ($key instanceof JsonResponse) {
                return $key;
            }
            $resolved[] = ['key' => $key, 'item' => $item];
        }

        $resolvedKeys = collect($resolved)->pluck('key')->all();

        $collisions = $environment->environment_variables()
            ->where('team_id', $teamId)
            ->whereIn('key', $resolvedKeys)
            ->where('is_shown_once', false)
            ->pluck('key');
        if ($collisions->isNotEmpty()) {
            return response()->json([
                'message' => 'Cannot overwrite existing non-secret shared variables with the secrets API.',
                'conflicting_keys' => $collisions->values()->all(),
            ], 409);
        }

        [$upserted, $deleted] = DB::transaction(function () use ($resolved, $resolvedKeys, $environment, $teamId, $request) {
            $upsertedRows = collect();
            foreach ($resolved as $entry) {
                $item = $entry['item'];
                $secret = $environment->environment_variables()->updateOrCreate(
                    ['key' => $entry['key'], 'team_id' => $teamId],
                    [
                        'value' => $item['value'],
                        'comment' => $item['comment'] ?? null,
                        'team_id' => $teamId,
                        'type' => 'environment',
                        'is_literal' => (bool) ($item['is_literal'] ?? false),
                        'is_multiline' => (bool) ($item['is_multiline'] ?? false),
                        'is_shown_once' => true,
                    ]
                );
                $upsertedRows->push($this->presentSecret($secret));
            }

            $deletedKeys = collect();
            if ($request->boolean('delete_missing')) {
                $toDelete = $environment->environment_variables()
                    ->where('team_id', $teamId)
                    ->where('is_shown_once', true)
                    ->whereNotIn('key', $resolvedKeys)
                    ->get();
                foreach ($toDelete as $secret) {
                    $deletedKeys->push($secret->key);
                    $secret->delete();
                }
            }

            return [$upsertedRows, $deletedKeys];
        });

        return response()->json([
            'upserted' => $upserted->values(),
            'deleted' => $deleted->values(),
        ]);
    }

    #[OA\Delete(
        summary: 'Delete Project Secret',
        description: 'Delete an environment-scoped shared secret for a project.',
        path: '/projects/{uuid}/secrets/{key}',
        operationId: 'delete-project-secret',
        security: [
            ['bearerAuth' => []],
        ],
        tags: ['Projects'],
        parameters: [
            new OA\Parameter(
                name: 'uuid',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'string'),
                description: 'Project UUID.'
            ),
            new OA\Parameter(
                name: 'key',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'string'),
                description: 'Secret key.'
            ),
            new OA\Parameter(
                name: 'environment',
                in: 'query',
                required: true,
                schema: new OA\Schema(type: 'string'),
                description: 'Environment name or UUID.'
            ),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Project secret deleted.'),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 404, ref: '#/components/responses/404'),
            new OA\Response(response: 422, ref: '#/components/responses/422'),
        ]
    )]
    public function delete_secret(Request $request, string $uuid, string $key)
    {
        $project = $this->resolveProjectAndEnvironment($request, $uuid);
        if ($project instanceof JsonResponse) {
            return $project;
        }

        $this->authorize('update', $project);
        $environment = $this->getResolvedEnvironment($project);
        $teamId = getTeamIdFromToken();
        $key = $this->resolveSecretKey($key);
        if ($key instanceof JsonResponse) {
            return $key;
        }

        $secret = $environment->environment_variables()
            ->where('key', $key)
            ->where('team_id', $teamId)
            ->where('is_shown_once', true)
            ->first();

        if (! $secret) {
            return response()->json(['message' => 'Secret not found.'], 404);
        }

        $secret->delete();

        return response()->json(['message' => 'Secret deleted.']);
    }
}
