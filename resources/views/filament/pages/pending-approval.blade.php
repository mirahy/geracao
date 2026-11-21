<x-filament-panels::page wire:init="openRequestModal">
    <x-filament::section icon="heroicon-o-clock" icon-color="warning">
        <x-slot name="heading">Seu cadastro aguarda liberação</x-slot>

        @if ($label = $this->requestedUnitLabel())
            <p>Solicitação enviada para a unidade:</p>
            <p class="mt-2 font-semibold">{{ $label }}</p>
            <p class="mt-4 text-sm text-gray-500 dark:text-gray-400">
                Assim que o administrador atribuir uma unidade ao seu usuário, o acesso é liberado automaticamente
                e você recebe um aviso. Enquanto isso, o restante do sistema fica indisponível.
            </p>
        @else
            <p>Para usar o sistema, solicite acesso a uma unidade consumidora. O administrador será avisado e liberará o seu cadastro.</p>
        @endif
    </x-filament::section>
</x-filament-panels::page>
