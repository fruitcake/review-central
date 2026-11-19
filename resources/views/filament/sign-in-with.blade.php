{{-- Under the sign-in form: the providers this installation is set up for. --}}
@if (session('sign_in_refused'))
    <p class="rc-sign-in-refused" role="alert">{{ session('sign_in_refused') }}</p>
@endif

@foreach ($providers as $provider => $name)
    <a class="rc-sign-in-with" href="{{ route('sign-in.redirect', ['provider' => $provider]) }}">Sign in with {{ $name }}</a>
@endforeach
