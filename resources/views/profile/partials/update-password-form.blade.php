<section>
    <h3 class="section-title mt-0">Update Password</h3>
    <p class="text-secondary text-sm mb-16">Ensure your account is using a long, random password to stay secure.</p>

    <form method="post" action="{{ route('password.update') }}">
        @csrf
        @method('put')

        <div class="form-grid">
            <div>
                <label for="update_password_current_password">Current Password</label>
                <input id="update_password_current_password" name="current_password" type="password" class="input" autocomplete="current-password">
                @if($errors->updatePassword->has('current_password'))
                    <div class="field-error">{{ $errors->updatePassword->first('current_password') }}</div>
                @endif
            </div>

            <div>
                <label for="update_password_password">New Password</label>
                <input id="update_password_password" name="password" type="password" class="input" autocomplete="new-password">
                @if($errors->updatePassword->has('password'))
                    <div class="field-error">{{ $errors->updatePassword->first('password') }}</div>
                @endif
            </div>

            <div>
                <label for="update_password_password_confirmation">Confirm Password</label>
                <input id="update_password_password_confirmation" name="password_confirmation" type="password" class="input" autocomplete="new-password">
                @if($errors->updatePassword->has('password_confirmation'))
                    <div class="field-error">{{ $errors->updatePassword->first('password_confirmation') }}</div>
                @endif
            </div>
        </div>

        <div class="d-flex items-center gap-8 mt-16">
            <button class="btn" type="submit">Save</button>

            @if (session('status') === 'password-updated')
                <span class="text-sm text-secondary">Saved.</span>
            @endif
        </div>
    </form>
</section>
