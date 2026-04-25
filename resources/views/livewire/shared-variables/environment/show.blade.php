<div>
    @unless ($compact)
        <x-slot:title>
            Shared Variables | Coolify
        </x-slot>
        <div class="flex gap-2">
            <h1>Shared Variables for {{ $project->name }}/{{ $environment->name }}</h1>
            @can('update', $environment)
                <x-modal-input buttonTitle="+ Add" title="New Shared Variable">
                    <livewire:project.shared.environment-variable.add :shared="true" />
                </x-modal-input>
            @endcan
            <x-forms.button canGate="update" :canResource="$environment" wire:click='switch'>{{ $view === 'normal' ? 'Developer view' : 'Normal view' }}</x-forms.button>
        </div>
        <nav class="flex gap-1 mt-3 mb-1 border-b dark:border-coolgray-200 border-neutral-200" aria-label="Shared variables sections">
            <span class="px-3 py-2 -mb-px text-sm font-semibold border-b-2 dark:border-warning border-coollabs dark:text-warning text-coollabs">
                Variables
            </span>
            <a class="px-3 py-2 -mb-px text-sm font-medium border-b-2 border-transparent text-neutral-500 dark:hover:text-warning hover:text-coollabs dark:hover:border-warning hover:border-coollabs transition-colors"
                href="{{ route('shared-variables.environment.secrets', ['project_uuid' => $project->uuid, 'environment_uuid' => $environment->uuid]) }}"
                {{ wireNavigate() }}>
                Secrets
            </a>
        </nav>
        <div class="flex items-center gap-1 subtitle">You can use these variables anywhere with <span
                class="dark:text-warning text-coollabs">@{{ environment.VARIABLENAME }}</span><x-helper
                helper="More info <a class='underline dark:text-white' href='https://coolify.io/docs/knowledge-base/environment-variables#shared-variables' target='_blank'>here</a>."></x-helper>
        </div>
    @else
        <div class="flex items-center gap-2 mb-2">
            @can('update', $environment)
                <x-modal-input buttonTitle="+ Add" title="New Shared Variable">
                    <livewire:project.shared.environment-variable.add :shared="true" />
                </x-modal-input>
            @endcan
            <x-forms.button canGate="update" :canResource="$environment" wire:click='switch'>{{ $view === 'normal' ? 'Developer view' : 'Normal view' }}</x-forms.button>
            <a class="text-xs underline dark:text-warning text-coollabs ml-auto"
                href="{{ route('shared-variables.environment.show', ['project_uuid' => $project->uuid, 'environment_uuid' => $environment->uuid]) }}"
                {{ wireNavigate() }}>
                Open full page
            </a>
        </div>
    @endunless
    @if ($view === 'normal')
        <div class="flex flex-col gap-2">
            @forelse ($environment->environment_variables->sort()->sortBy('key') as $env)
                <livewire:project.shared.environment-variable.show wire:key="environment-{{ $env->id }}"
                    :env="$env" type="environment" />
            @empty
                <div class="text-sm text-neutral-500 dark:text-neutral-400">No shared variables found.</div>
            @endforelse
        </div>
    @else
        <form wire:submit='submit' class="flex flex-col gap-2">
            <x-forms.textarea canGate="update" :canResource="$environment" rows="{{ $compact ? 8 : 20 }}" class="whitespace-pre-wrap" id="variables" wire:model="variables" monospace
                label="Environment Shared Variables"></x-forms.textarea>
            <x-forms.button canGate="update" :canResource="$environment" type="submit" class="btn btn-primary">Save All Environment Variables</x-forms.button>
        </form>
    @endif
</div>
