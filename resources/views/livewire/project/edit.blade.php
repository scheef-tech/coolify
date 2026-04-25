<div>
    <x-slot:title>
        {{ data_get_str($project, 'name')->limit(10) }} > Edit | Coolify
        </x-slot>
        <form wire:submit='submit' class="flex flex-col pb-10">
            <div class="flex gap-2">
                <h1>{{ data_get_str($project, 'name')->limit(15) }}</h1>
                <div class="flex items-end gap-2">
                    <x-forms.button type="submit">Save</x-forms.button>
                    <livewire:project.delete-project :disabled="!$project->isEmpty()" :project_id="$project->id" />
                </div>
            </div>
            <div class="pt-2 pb-10">Edit project details here.</div>
            <div class="flex gap-2">
                <x-forms.input label="Name" id="name" />
                <x-forms.input label="Description" id="description" />
            </div>
        </form>

        <div class="flex flex-col gap-2 mt-6">
            <h3>Shared variables</h3>
            <p class="text-sm text-neutral-500 dark:text-neutral-400">
                Manage variables that apply across this project's resources.
            </p>
            <a class="coolbox group" href="{{ route('shared-variables.project.show', ['project_uuid' => $project->uuid]) }}" {{ wireNavigate() }}>
                <div class="flex flex-col justify-center mx-6">
                    <div class="box-title">Project shared variables</div>
                    <div class="box-description">Available to every resource across all environments in this project.</div>
                </div>
            </a>
        </div>
</div>