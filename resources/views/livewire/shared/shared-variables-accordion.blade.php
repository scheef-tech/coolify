@php
    $scopeMeta = [
        'environment' => [
            'label' => 'Environment shared variables',
            'description' => 'Available to every resource in this environment.',
        ],
        'project' => [
            'label' => 'Project shared variables',
            'description' => 'Available to every resource across this project (all environments).',
        ],
        'server' => [
            'label' => 'Server shared variables',
            'description' => 'Available to every resource that runs on this server.',
        ],
        'team' => [
            'label' => 'Team shared variables',
            'description' => 'Available to every resource owned by this team.',
        ],
    ];
@endphp

<div class="flex flex-col gap-2 mt-6">
    <div class="flex flex-col">
        <h3>Inherited shared variables</h3>
        <p class="text-sm text-neutral-500 dark:text-neutral-400">
            Variables from wider scopes that apply here at runtime, in inheritance order. Sections collapsed by default — click to expand and edit inline.
        </p>
    </div>

    @foreach ($this->scopes as $scope)
        @php $isOpen = in_array($scope, $expandedScopes, true); @endphp
        <div class="border rounded-md dark:border-coolgray-200 border-neutral-200 overflow-hidden">
            <button type="button" wire:click="toggle('{{ $scope }}')"
                class="w-full flex items-center justify-between px-4 py-3 text-left hover:bg-neutral-50 dark:hover:bg-coolgray-200 transition-colors">
                <div class="flex items-center gap-2">
                    <svg class="w-4 h-4 transition-transform {{ $isOpen ? 'rotate-90' : '' }}"
                        fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"
                        aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7" />
                    </svg>
                    <span class="font-medium">{{ $scopeMeta[$scope]['label'] }}</span>
                    <span class="text-xs text-neutral-500 dark:text-neutral-400 hidden md:inline">
                        — {{ $scopeMeta[$scope]['description'] }}
                    </span>
                </div>
                <span class="text-xs text-neutral-400 dark:text-neutral-500">
                    {{ $isOpen ? 'Hide' : 'Show' }}
                </span>
            </button>

            @if ($isOpen)
                <div class="px-4 py-3 border-t dark:border-coolgray-200 border-neutral-200">
                    @if ($scope === 'environment' && $this->environment)
                        <nav class="flex gap-1 mb-3 border-b dark:border-coolgray-200 border-neutral-200" aria-label="Environment scope sub-sections">
                            <button type="button" wire:click="setEnvTab('variables')"
                                class="px-3 py-2 -mb-px text-sm border-b-2 transition-colors
                                    {{ $envTab === 'variables'
                                        ? 'font-semibold dark:border-warning border-coollabs dark:text-warning text-coollabs'
                                        : 'font-medium border-transparent text-neutral-500 dark:hover:text-warning hover:text-coollabs dark:hover:border-warning hover:border-coollabs' }}">
                                Variables
                            </button>
                            <button type="button" wire:click="setEnvTab('secrets')"
                                class="px-3 py-2 -mb-px text-sm border-b-2 transition-colors
                                    {{ $envTab === 'secrets'
                                        ? 'font-semibold dark:border-warning border-coollabs dark:text-warning text-coollabs'
                                        : 'font-medium border-transparent text-neutral-500 dark:hover:text-warning hover:text-coollabs dark:hover:border-warning hover:border-coollabs' }}">
                                Secrets
                            </button>
                        </nav>

                        @if ($envTab === 'variables')
                            <livewire:shared-variables.environment.show
                                :wire:key="'sva-env-vars-'.$this->environment->id"
                                :project="$this->project"
                                :environment="$this->environment"
                                :compact="true" />
                        @else
                            <livewire:shared-variables.environment.secrets
                                :wire:key="'sva-env-secrets-'.$this->environment->id"
                                :project="$this->project"
                                :environment="$this->environment"
                                :compact="true" />
                        @endif
                    @elseif ($scope === 'project' && $this->project)
                        <livewire:shared-variables.project.show
                            :wire:key="'sva-proj-'.$this->project->id"
                            :project="$this->project"
                            :compact="true" />
                    @elseif ($scope === 'server' && $this->server)
                        <livewire:shared-variables.server.show
                            :wire:key="'sva-server-'.$this->server->id"
                            :server="$this->server"
                            :compact="true" />
                    @elseif ($scope === 'team')
                        <livewire:shared-variables.team.index
                            :wire:key="'sva-team-'.$this->team->id"
                            :team="$this->team"
                            :compact="true" />
                    @endif
                </div>
            @endif
        </div>
    @endforeach
</div>
