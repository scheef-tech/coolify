<?php

namespace App\Livewire\SharedVariables\Environment;

use App\Models\Environment;
use App\Models\Project;
use App\Models\SharedEnvironmentVariable;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\DB;
use Livewire\Component;

class Secrets extends Component
{
    use AuthorizesRequests;

    private const KEY_PATTERN = '/^[A-Z][A-Z0-9_]*$/';

    public Project $project;

    public Environment $environment;

    public string $view = 'normal';

    public bool $showingAddForm = false;

    public string $newKey = '';

    public ?string $newValue = null;

    public ?string $newComment = null;

    public bool $newIsLiteral = false;

    public bool $newIsMultiline = false;

    public ?string $variables = null;

    public bool $deleteMissingOnBulk = false;

    public bool $isDevPullable = false;

    public bool $compact = false;

    protected $listeners = [
        'environmentVariableDeleted' => 'refresh',
        'refreshEnvs' => 'refresh',
    ];

    public function mount(?string $project_uuid = null, ?string $environment_uuid = null): void
    {
        if (! isset($this->project)) {
            $projectUuid = $project_uuid ?? request()->route('project_uuid');
            $this->project = Project::ownedByCurrentTeam()->where('uuid', $projectUuid)->firstOrFail();
        }
        if (! isset($this->environment)) {
            $environmentUuid = $environment_uuid ?? request()->route('environment_uuid');
            $this->environment = $this->project->environments()->where('uuid', $environmentUuid)->firstOrFail();
        }
        $this->isDevPullable = (bool) $this->environment->is_dev_pullable;

        $this->refreshDevView();
    }

    public function updatedIsDevPullable(bool $value): void
    {
        try {
            $this->authorize('update', $this->environment);
            $this->environment->is_dev_pullable = $value;
            $this->environment->save();
            $this->dispatch('success', $value
                ? 'Dev-pullable enabled. coolster pull and reveal will work for this environment.'
                : 'Dev-pullable disabled. Secret values are no longer fetchable from this environment.');
        } catch (\Throwable $e) {
            $this->isDevPullable = ! $value;
            handleError($e, $this);
        }
    }

    public function getSecretsProperty()
    {
        return $this->environment->environment_variables()
            ->where('is_shown_once', true)
            ->orderBy('key')
            ->get();
    }

    public function toggleAddForm(): void
    {
        $this->resetAddForm();
        $this->showingAddForm = ! $this->showingAddForm;
    }

    public function cancelAdd(): void
    {
        $this->showingAddForm = false;
        $this->resetAddForm();
    }

    public function saveSecret(): void
    {
        $this->authorize('update', $this->environment);

        $this->validate([
            'newKey' => ['required', 'string', 'regex:'.self::KEY_PATTERN],
            'newValue' => ['required', 'string'],
            'newComment' => ['nullable', 'string', 'max:256'],
            'newIsLiteral' => ['boolean'],
            'newIsMultiline' => ['boolean'],
        ], [
            'newKey.regex' => 'The key must match '.trim(self::KEY_PATTERN, '/').'.',
        ]);

        try {
            $key = $this->newKey;
            $existing = $this->environment->environment_variables()->where('key', $key)->first();

            if ($existing && ! $existing->is_shown_once) {
                $this->dispatch('error', 'A non-secret shared variable with key '.$key.' already exists. Remove it first or pick a different key.');

                return;
            }

            $teamId = currentTeam()->id;
            $this->environment->environment_variables()->updateOrCreate(
                ['key' => $key, 'team_id' => $teamId],
                [
                    'value' => $this->newValue,
                    'comment' => $this->newComment,
                    'team_id' => $teamId,
                    'type' => 'environment',
                    'is_literal' => $this->newIsLiteral,
                    'is_multiline' => $this->newIsMultiline,
                    'is_shown_once' => true,
                ]
            );

            $this->dispatch('success', $existing ? 'Secret updated.' : 'Secret added.');
            $this->resetAddForm();
            $this->showingAddForm = false;
            $this->refresh();
        } catch (\Throwable $e) {
            handleError($e, $this);
        }
    }

    public function switchView(): void
    {
        $this->authorize('view', $this->environment);
        $this->view = $this->view === 'normal' ? 'dev' : 'normal';
        $this->refreshDevView();
    }

    public function submitDevView(): void
    {
        try {
            $this->authorize('update', $this->environment);

            $parsed = parseEnvFormatToArray($this->variables ?? '');
            $resolved = [];
            foreach ($parsed as $rawKey => $data) {
                $key = trim((string) $rawKey);
                $value = is_array($data) ? ($data['value'] ?? '') : $data;

                if ($key === '') {
                    continue;
                }
                if (! preg_match(self::KEY_PATTERN, $key)) {
                    $this->dispatch('error', 'Invalid key '.$key.'. Keys must match '.trim(self::KEY_PATTERN, '/').'.');

                    return;
                }
                if ($value === '' || $value === null) {
                    // Empty RHS is a no-op — preserve existing secret value.
                    continue;
                }
                $resolved[$key] = $value;
            }

            $teamId = currentTeam()->id;
            $submittedKeys = array_keys($resolved);

            $collisions = $this->environment->environment_variables()
                ->whereIn('key', $submittedKeys)
                ->where('is_shown_once', false)
                ->pluck('key');
            if ($collisions->isNotEmpty()) {
                $this->dispatch('error', 'Cannot overwrite non-secret shared variable(s): '.$collisions->implode(', ').'.');

                return;
            }

            DB::transaction(function () use ($resolved, $submittedKeys, $teamId) {
                foreach ($resolved as $key => $value) {
                    $this->environment->environment_variables()->updateOrCreate(
                        ['key' => $key, 'team_id' => $teamId],
                        [
                            'value' => $value,
                            'team_id' => $teamId,
                            'type' => 'environment',
                            'is_shown_once' => true,
                        ]
                    );
                }

                if ($this->deleteMissingOnBulk) {
                    $this->environment->environment_variables()
                        ->where('is_shown_once', true)
                        ->whereNotIn('key', $submittedKeys)
                        ->delete();
                }
            });

            $this->dispatch('success', 'Secrets updated.');
            $this->refresh();
        } catch (\Throwable $e) {
            handleError($e, $this);
        }
    }

    public function refresh(): void
    {
        $this->environment->refresh();
        $this->refreshDevView();
    }

    private function resetAddForm(): void
    {
        $this->newKey = '';
        $this->newValue = null;
        $this->newComment = null;
        $this->newIsLiteral = false;
        $this->newIsMultiline = false;
        $this->resetValidation();
    }

    private function refreshDevView(): void
    {
        $this->variables = $this->secrets
            ->map(fn (SharedEnvironmentVariable $secret) => $secret->key.'=')
            ->join("\n");
    }

    public function render()
    {
        return view('livewire.shared-variables.environment.secrets');
    }
}
