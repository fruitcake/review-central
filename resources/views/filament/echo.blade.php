{{-- Laravel Echo, when the installation has a broadcaster. After Filament's own
     scripts, which bring the Pusher client, and before Livewire starts, which
     looks for Echo when a page listens. --}}
@if ($options !== null && auth()->check())
    <script src="{{ asset('js/laravel-echo.js') }}?v=2.6.3" data-navigate-once></script>
    <script data-navigate-once>
        window.Echo = new window.Echo.default(@js($options))
        window.dispatchEvent(new CustomEvent('EchoLoaded'))
    </script>
@endif
