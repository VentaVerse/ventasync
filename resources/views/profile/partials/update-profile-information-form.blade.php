<section>
    <h3 class="section-title mt-0">Profile Information</h3>
    <p class="text-secondary text-sm mb-16">Update your account's profile information and email address.</p>

    <form id="send-verification" method="post" action="{{ route('verification.send') }}">
        @csrf
    </form>

    <form method="post" action="{{ route('profile.update') }}">
        @csrf
        @method('patch')

        <div class="form-grid">
            <div>
                <label for="name">Name</label>
                <input id="name" name="name" type="text" class="input" value="{{ old('name', $user->name) }}" required autofocus autocomplete="name">
                @if($errors->has('name'))
                    <div class="field-error">{{ $errors->first('name') }}</div>
                @endif
            </div>

            <div>
                <label for="email">Email</label>
                <input id="email" name="email" type="email" class="input" value="{{ old('email', $user->email) }}" required autocomplete="username">
                @if($errors->has('email'))
                    <div class="field-error">{{ $errors->first('email') }}</div>
                @endif

                @if ($user instanceof \Illuminate\Contracts\Auth\MustVerifyEmail && ! $user->hasVerifiedEmail())
                    <div class="mt-12">
                        <p class="text-sm text-secondary">
                            Your email address is unverified.
                            <button form="send-verification" class="btn small secondary">Click here to re-send the verification email.</button>
                        </p>

                        @if (session('status') === 'verification-link-sent')
                            <p class="text-sm mt-12" style="color: var(--success);">
                                A new verification link has been sent to your email address.
                            </p>
                        @endif
                    </div>
                @endif
            </div>
        </div>

        <div class="d-flex items-center gap-8 mt-16">
            <button class="btn" type="submit">Save</button>

            @if (session('status') === 'profile-updated')
                <span class="text-sm text-secondary">Saved.</span>
            @endif
        </div>
    </form>
</section>
