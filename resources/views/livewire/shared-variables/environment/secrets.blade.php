<div>
    <x-slot:title>
        Secrets | Coolify
    </x-slot>
    <div class="flex flex-wrap items-center gap-2">
        <h1>Secrets for {{ $project->name }}/{{ $environment->name }}</h1>
        @can('update', $environment)
            <x-forms.button wire:click="toggleAddForm">
                {{ $showingAddForm ? 'Cancel' : '+ Add Secret' }}
            </x-forms.button>
        @endcan
        <x-forms.button canGate="view" :canResource="$environment" wire:click="switchView">
            {{ $view === 'normal' ? 'Developer view' : 'Normal view' }}
        </x-forms.button>
    </div>

    <div class="flex items-center gap-2 mt-1 text-sm">
        <a class="hover:underline"
            href="{{ route('shared-variables.environment.show', ['project_uuid' => $project->uuid, 'environment_uuid' => $environment->uuid]) }}"
            {{ wireNavigate() }}>
            Variables
        </a>
        <span class="text-neutral-400">|</span>
        <span class="font-semibold">Secrets</span>
    </div>

    <div class="flex items-center gap-1 mt-1 subtitle">
        Sensitive environment values, scoped to this project + environment. Values are write-only — they are never
        returned by the API or shown again after creation, unless this environment is marked dev-pullable below.
    </div>

    <div class="p-3 mt-3 border rounded-md dark:border-coolgray-200 border-neutral-200">
        <x-forms.checkbox canGate="update" :canResource="$environment" id="isDevPullable"
            wire:model.live="isDevPullable"
            label="Dev-pullable environment"
            helper="When enabled, the coolster CLI can fetch secret VALUES (not just metadata) for this environment via `coolster pull` and `coolster secret reveal`. Use only for development/staging — never for production." />
        @if ($isDevPullable)
            <div class="mt-2 rounded-md border border-warning/40 bg-warning/10 px-3 py-2 text-xs leading-5 text-warning">
                Secret values in this environment are fetchable via the API. Anyone with a token scoped to this team
                and `read` ability can pull these values to a local cache.
            </div>
        @endif
    </div>

    @if ($showingAddForm)
        <div class="p-4 mt-4 mb-4 border rounded-md dark:border-coolgray-200 border-neutral-200">
            <h3 class="mb-3">New Secret</h3>
            <form wire:submit.prevent="saveSecret" class="flex flex-col gap-3">
                <x-forms.input id="newKey" label="Key" placeholder="MY_SECRET_KEY" wire:model="newKey" required
                    helper="Must match ^[A-Z][A-Z0-9_]*$." />
                @if ($newIsMultiline)
                    <x-forms.textarea id="newValue" label="Value" rows="6" wire:model="newValue" required
                        monospace />
                @else
                    <x-forms.input type="password" id="newValue" label="Value" wire:model="newValue" required
                        helper="Stored encrypted. Will not be shown again." />
                @endif
                <x-forms.input id="newComment" label="Comment (optional)" wire:model="newComment" maxlength="256" />
                <div class="flex gap-4">
                    <x-forms.checkbox id="newIsLiteral" label="Literal" wire:model.live="newIsLiteral"
                        helper="Treat the value literally (do not interpolate $)." />
                    <x-forms.checkbox id="newIsMultiline" label="Multiline" wire:model.live="newIsMultiline"
                        helper="Allow newline characters in the value." />
                </div>
                <div class="flex gap-2">
                    <x-forms.button type="submit" class="btn btn-primary">Save Secret</x-forms.button>
                    <x-forms.button type="button" wire:click="cancelAdd">Cancel</x-forms.button>
                </div>
            </form>
        </div>
    @endif

    @if ($view === 'normal')
        <div class="flex flex-col gap-2 mt-4">
            @forelse ($this->secrets as $secret)
                <livewire:project.shared.environment-variable.show wire:key="secret-{{ $secret->id }}"
                    :env="$secret" type="environment" />
            @empty
                <div class="text-sm text-neutral-500 dark:text-neutral-400">
                    No secrets in this environment yet.
                    @can('update', $environment)
                        Click <span class="font-semibold">+ Add Secret</span> above, or push from the
                        <code class="font-mono">coolster</code> CLI.
                    @endcan
                </div>
            @endforelse
        </div>
    @else
        <form wire:submit.prevent="submitDevView" class="flex flex-col gap-2 mt-4">
            <x-callout type="info" title="Developer view">
                Existing keys appear with empty values. Type or paste <code class="font-mono">KEY=value</code> to set a
                secret. Lines with empty values are no-ops (existing secrets are preserved).
            </x-callout>
            <x-forms.textarea canGate="update" :canResource="$environment" rows="20"
                class="whitespace-pre-wrap" id="variables" wire:model="variables" monospace
                label="Secrets" />
            <x-forms.checkbox canGate="update" :canResource="$environment" id="deleteMissingOnBulk"
                wire:model="deleteMissingOnBulk" label="Delete keys removed from list (destructive)"
                helper="When enabled, secrets whose keys are absent from the textarea will be deleted." />
            <x-forms.button canGate="update" :canResource="$environment" type="submit" class="btn btn-primary">
                Save Secrets
            </x-forms.button>
        </form>
    @endif
</div>
