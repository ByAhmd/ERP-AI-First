<x-invitations.layout>
    <h1 class="invitation__heading">
        {{ __('identity.invitations.accept.heading', ['company' => $company->displayName()]) }}
    </h1>

    <p class="invitation__identity">{{ $user->email }}</p>

    @if ($errors->any())
        <ul class="invitation__errors">
            @foreach ($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    @endif

    <form method="POST" action="{{ route('invitations.accept', ['token' => $token]) }}">
        @csrf

        <label class="invitation__label" for="password">
            {{ __('identity.invitations.accept.password') }}
        </label>
        <input class="invitation__input"
               id="password"
               name="password"
               type="password"
               autocomplete="new-password"
               required
               autofocus>

        <label class="invitation__label" for="password_confirmation">
            {{ __('identity.invitations.accept.password_confirmation') }}
        </label>
        <input class="invitation__input"
               id="password_confirmation"
               name="password_confirmation"
               type="password"
               autocomplete="new-password"
               required>

        <button class="invitation__submit" type="submit">
            {{ __('identity.invitations.accept.submit') }}
        </button>
    </form>
</x-invitations.layout>
