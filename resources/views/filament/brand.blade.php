{{-- Marca do painel: logo do app mobile + nome, na barra lateral e na tela de login. --}}
<span class="geracao-brand" style="display: flex; align-items: center; gap: 0.625rem; height: 100%;">
    <img src="{{ asset('assets/logo.png') }}" alt="" style="height: 100%; width: auto; border-radius: 0.5rem;">
    <span style="font-weight: 700; font-size: 1.125rem; letter-spacing: -0.01em; white-space: nowrap;">{{ filament()->getBrandName() }}</span>
</span>
