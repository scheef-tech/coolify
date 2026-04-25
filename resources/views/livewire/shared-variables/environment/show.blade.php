<div>
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
    <div class="flex items-center gap-2 mt-1 text-sm">
        <span class="font-semibold">Variables</span>
        <span class="text-neutral-400">|</span>
        <a class="hover:underline"
            href="{{ route('shared-variables.environment.secrets', ['project_uuid' => $project->uuid, 'environment_uuid' => $environment->uuid]) }}"
            {{ wireNavigate() }}>
            Secrets
        </a>
    </div>
    <div class="flex items-center gap-1 subtitle">You can use these variables anywhere with <span
            class="dark:text-warning text-coollabs">@{{ environment.VARIABLENAME }}</span><x-helper
            helper="More info <a class='underline dark:text-white' href='https://coolify.io/docs/knowledge-base/environment-variables#shared-variables' target='_blank'>here</a>."></x-helper>
    </div>
    @if ($view === 'normal')
        <div class="flex flex-col gap-2">
            @forelse ($environment->environment_variables->sort()->sortBy('key') as $env)
                <livewire:project.shared.environment-variable.show wire:key="environment-{{ $env->id }}"
                    :env="$env" type="environment" />
            @empty
                <div>No shared variables found.</div>
            @endforelse
        </div>
    @else
        <form wire:submit='submit' class="flex flex-col gap-2">
            <x-forms.textarea canGate="update" :canResource="$environment" rows="20" class="whitespace-pre-wrap" id="variables" wire:model="variables" monospace
                label="Environment Shared Variables"></x-forms.textarea>
            <x-forms.button canGate="update" :canResource="$environment" type="submit" class="btn btn-primary">Save All Environment Variables</x-forms.button>
        </form>
    @endif
</div>
