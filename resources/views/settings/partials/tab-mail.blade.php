@php
    $mailer = old('mail_mailer', $setting->mail_mailer ?? 'sendmail');
    $isSmtp = $mailer === 'smtp';
    $encryption = old('mail_encryption', $setting->mail_encryption);
    $hasStoredPassword = ! empty($setting->mail_password);
@endphp

<div class="fm-body">
    <div class="fm-col fm-col--main">

        <section class="fm-section">
            <div class="fm-section__head">
                <h2 class="fm-section__title">How mail leaves this server</h2>
            </div>

            <div class="fm-fields fm-fields--2">
                <x-ui.field label="Send through" for="st-mailer" name="mail_mailer" :required="true" wide
                            hint="The server's own mail program needs no credentials but is often filtered as spam. An SMTP account is delivered as that account.">
                    <x-ui.select id="st-mailer" name="mail_mailer" data-smtp-toggle="st-smtp" :disabled="$ro">
                        <option value="sendmail" @selected($mailer === 'sendmail')>The server's mail program</option>
                        <option value="smtp" @selected($isSmtp)>An SMTP account</option>
                    </x-ui.select>
                </x-ui.field>
            </div>

            <div class="fm-fields fm-fields--2 st-smtp" id="st-smtp" @if(! $isSmtp) hidden @endif>
                <x-ui.field label="Host" for="st-mail-host" name="mail_host">
                    <x-ui.input id="st-mail-host" name="mail_host" maxlength="190" placeholder="smtp.example.com" :readonly="$ro"
                                value="{{ old('mail_host', $setting->mail_host) }}" />
                </x-ui.field>

                <x-ui.field label="Port" for="st-mail-port" name="mail_port" hint="Usually 587 for TLS, 465 for SSL.">
                    <x-ui.input type="number" id="st-mail-port" name="mail_port" min="1" max="65535"
                                class="fm-input--num st-days" placeholder="587" :readonly="$ro"
                                value="{{ old('mail_port', $setting->mail_port) }}" />
                </x-ui.field>

                <x-ui.field label="Username" for="st-mail-user" name="mail_username">
                    <x-ui.input id="st-mail-user" name="mail_username" maxlength="190" autocomplete="off" :readonly="$ro"
                                value="{{ old('mail_username', $setting->mail_username) }}" />
                </x-ui.field>

                <x-ui.field label="Password" for="st-mail-pass" name="mail_password"
                            :hint="$hasStoredPassword ? 'A password is stored. Leave this blank to keep it.' : 'Nothing stored yet.'">
                    <x-ui.input type="password" id="st-mail-pass" name="mail_password" maxlength="190"
                                autocomplete="new-password" value="" :readonly="$ro" />
                    @if($hasStoredPassword && ! $ro)
                        <label class="st-inline-check">
                            <input type="checkbox" class="st-check" name="clear_mail_password" value="1">
                            <span>Forget the stored password</span>
                        </label>
                    @endif
                </x-ui.field>

                <x-ui.field label="Encryption" for="st-mail-enc" name="mail_encryption">
                    <x-ui.select id="st-mail-enc" name="mail_encryption" :disabled="$ro">
                        <option value="" @selected($encryption === null || $encryption === '')>None</option>
                        <option value="tls" @selected($encryption === 'tls')>TLS</option>
                        <option value="ssl" @selected($encryption === 'ssl')>SSL</option>
                    </x-ui.select>
                </x-ui.field>
            </div>
        </section>

        <section class="fm-section">
            <div class="fm-section__head">
                <h2 class="fm-section__title">Who mail comes from</h2>
            </div>
            <p class="fm-section__note">
                Set either of these and they take precedence over the sender address and company
                name on the Website Settings page. Leave them empty to use those instead.
            </p>

            <div class="fm-fields fm-fields--2">
                <x-ui.field label="From address" for="st-mail-from" name="mail_from_address">
                    <x-ui.input type="email" id="st-mail-from" name="mail_from_address" maxlength="190"
                                placeholder="no-reply@example.com" :readonly="$ro"
                                value="{{ old('mail_from_address', $setting->mail_from_address) }}" />
                </x-ui.field>

                <x-ui.field label="From name" for="st-mail-from-name" name="mail_from_name">
                    <x-ui.input id="st-mail-from-name" name="mail_from_name" maxlength="190"
                                placeholder="{{ config('app.name') }}" :readonly="$ro"
                                value="{{ old('mail_from_name', $setting->mail_from_name) }}" />
                </x-ui.field>
            </div>
        </section>

    </div>

    @if(! $ro)
    <div class="fm-col fm-col--side">
        <div class="fm-card">
            <div class="fm-card__head"><h2 class="fm-card__title">Send a test</h2></div>
            <div class="fm-card__body">
                <label class="fm-label" for="st-test-to">Send to</label>
                <x-ui.input type="email" id="st-test-to" class="st-test__to"
                            value="{{ auth()->user()->email ?? '' }}" placeholder="you@example.com" />

                <div class="st-test__row">
                    <x-ui.button type="button" id="st-test-send"
                                 data-test-mail="{{ route('settings.mail.test') }}"
                                 data-test-input="st-test-to"
                                 data-test-result="st-test-result">Send test email</x-ui.button>
                </div>

                <div class="st-test__result" id="st-test-result" role="status" aria-live="polite" hidden></div>
            </div>
        </div>
    </div>
    @endif
</div>
