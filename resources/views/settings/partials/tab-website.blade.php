@php
    // AppServiceProvider::boot() sets mail.from.address from `from_email`,
    // and MailConfigServiceProvider (registered after it in config/app.php)
    // overwrites that with `mail_from_address` whenever the Mail tab has one.
    // So `from_email` is the fallback sender, not a second independent
    // address, and the hint says exactly that instead of leaving an operator
    // to discover the precedence by sending a test message.
    $mailOverrides = ! empty($setting->mail_from_address);
    $retention = old('activity_log_retention_days', $setting->activity_log_retention_days ?? 90);

    // Built here and passed with :hint so the component escapes it exactly
    // once. `hint="{{ ... }}"` would escape into the attribute AND again on
    // render, which is the double-encode Breadcrumbs::leaf() already had to
    // undo once in this codebase.
    $fromHint = $mailOverrides
        ? 'The Mail page sets a From address (' . $setting->mail_from_address . '), which takes precedence over this one.'
        : 'Mail this system sends comes from here, unless the Mail page sets a From address.';
@endphp

<div class="fm-body">
    <div class="fm-col fm-col--main">

        <section class="fm-section">
            <div class="fm-section__head">
                <h2 class="fm-section__title">Sender and time</h2>
            </div>

            <div class="fm-fields fm-fields--2">
                <x-ui.field label="Sender address" for="st-from-email" name="from_email" :required="true" wide
                            :hint="$fromHint">
                    <x-ui.input type="email" id="st-from-email" name="from_email" maxlength="255" :readonly="$ro"
                                value="{{ old('from_email', $setting->from_email) }}" />
                </x-ui.field>

                <x-ui.field label="Timezone" for="st-timezone" name="timezone" :required="true" wide
                            hint="Every date and time in this application is shown in this zone.">
                    <x-ui.select id="st-timezone" name="timezone" :disabled="$ro">
                        @foreach(timezone_identifiers_list() as $tz)
                            <option value="{{ $tz }}" @selected(old('timezone', $setting->timezone ?? 'Asia/Manila') === $tz)>{{ $tz }}</option>
                        @endforeach
                    </x-ui.select>
                </x-ui.field>
            </div>
        </section>

        <section class="fm-section">
            <div class="fm-section__head">
                <h2 class="fm-section__title">Activity history</h2>
            </div>
            <p class="fm-section__note">
                Activity log entries older than this are removed by the scheduled cleanup, and by
                Clear old logs on the activity log page. Between 7 and 3650 days.
            </p>

            <div class="fm-fields fm-fields--2">
                <x-ui.field label="Keep activity for" for="st-retention" name="activity_log_retention_days" :required="true"
                            hint="Days.">
                    <x-ui.input type="number" id="st-retention" name="activity_log_retention_days"
                                min="7" max="3650" class="fm-input--num st-days" :readonly="$ro"
                                value="{{ $retention }}" />
                </x-ui.field>
            </div>
        </section>

        <section class="fm-section">
            <div class="fm-section__head">
                <h2 class="fm-section__title">Branding</h2>
            </div>
            <p class="fm-section__note">
                Uploading replaces what is there now. There is no revert: upload again to change it back.
            </p>

            <div class="fm-fields fm-fields--2">
                <x-ui.field label="Logo" for="st-logo" name="logo" wide
                            hint="Shown in the sidebar and on the login screen. Up to 2 MB.">
                    <div class="st-file">
                        <label class="st-file__btn" for="st-logo">Choose file</label>
                        <span class="st-file__name" data-file-name-for="st-logo">No file chosen</span>
                        <input type="file" id="st-logo" name="logo" accept="image/*" class="st-file__input"
                               data-image-preview="st-logo-img" @disabled($ro)>
                    </div>
                </x-ui.field>

                <x-ui.field label="Favicon" for="st-favicon" name="favicon" wide
                            hint="Shown in the browser tab. PNG, SVG or ICO, square, up to 512 KB.">
                    <div class="st-file">
                        <label class="st-file__btn" for="st-favicon">Choose file</label>
                        <span class="st-file__name" data-file-name-for="st-favicon">No file chosen</span>
                        <input type="file" id="st-favicon" name="favicon" accept="image/*,.ico,.svg" class="st-file__input"
                               data-image-preview="st-favicon-img" @disabled($ro)>
                    </div>
                </x-ui.field>
            </div>
        </section>

    </div>

    <div class="fm-col fm-col--side">
        <div class="fm-card">
            <div class="fm-card__head"><h2 class="fm-card__title">What is showing now</h2></div>
            <div class="fm-card__body">
                <div class="st-brand">
                    <img class="st-brand__logo" id="st-logo-img" alt="Logo"
                         src="{{ $setting->logo_path ? asset('storage/'.$setting->logo_path) : asset('images/brand/ventasync.png') }}">
                    <span class="st-brand__cap">{{ $setting->logo_path ? 'Uploaded logo' : 'Default logo' }}</span>
                </div>
                <div class="st-brand st-brand--icon">
                    <img class="st-brand__fav" id="st-favicon-img" alt="Favicon"
                         src="{{ $setting->favicon_path ? asset('storage/'.$setting->favicon_path) : asset('images/brand/ventasync-icon.png') }}">
                    <span class="st-brand__cap">{{ $setting->favicon_path ? 'Uploaded favicon' : 'Default favicon' }}</span>
                </div>
            </div>
        </div>
    </div>
</div>
