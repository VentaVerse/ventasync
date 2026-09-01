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

<div class="fm-body st-body--one">
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

        {{-- Sync and API logs (2026-09-01). They had no retention and no way
             to stop them: venta_sync_logs alone reached 310,120 rows on the
             box this was found on, the largest table in the database. The
             level is the switch that matters most: a cron that pulled
             nothing is not worth a row the next day. --}}
        <section class="fm-section">
            <div class="fm-section__head">
                <h2 class="fm-section__title">Sync and API logs</h2>
            </div>
            <p class="fm-section__note">
                Sync logs record each sync run this system starts. API logs record the individual
                calls, both the ones made to a marketplace and the ones other software makes to
                VentaSync. Both are diagnostics: orders, stock history and the activity log are
                never touched by this. The nightly cleanup applies these, and Purge now applies
                them at once. Each channel can set its own recording level on its Sync log tab;
                what is set here is what a channel follows until it does.
            </p>

            <div class="fm-fields fm-fields--2">
                <x-ui.field label="Record sync runs by default" for="st-sync-level" name="sync_log_level" :required="true"
                            hint="A run that found nothing is rarely worth keeping. A channel can override this on its own Sync log tab.">
                    <x-ui.select id="st-sync-level" name="sync_log_level" :disabled="$ro">
                        @foreach(\App\Support\LogRetention::LEVELS as $value => $label)
                            <option value="{{ $value }}" @selected(old('sync_log_level', $setting->sync_log_level ?? 'all') === $value)>{{ $label }}</option>
                        @endforeach
                    </x-ui.select>
                </x-ui.field>

                <x-ui.field label="Keep sync logs for" for="st-sync-days" name="sync_log_retention_days" :required="true"
                            hint="Days.">
                    <x-ui.input type="number" id="st-sync-days" name="sync_log_retention_days"
                                min="1" max="3650" class="fm-input--num st-days" :readonly="$ro"
                                value="{{ old('sync_log_retention_days', $setting->sync_log_retention_days ?? 30) }}" />
                </x-ui.field>

                <x-ui.field label="Keep API logs for" for="st-api-days" name="api_log_retention_days" :required="true"
                            hint="Days.">
                    <x-ui.input type="number" id="st-api-days" name="api_log_retention_days"
                                min="1" max="3650" class="fm-input--num st-days" :readonly="$ro"
                                value="{{ old('api_log_retention_days', $setting->api_log_retention_days ?? 30) }}" />
                </x-ui.field>
            </div>

            @unless($ro)
                {{-- form= points at the purge form in settings/edit.blade.php,
                     which sits OUTSIDE the settings form: a form inside a form
                     is not valid HTML and the browser drops the inner one. --}}
                <div class="fm-inline-action">
                    <x-ui.button type="submit" form="stPurgeLogs" variant="secondary"
                                 data-confirm="Remove every sync and API log row older than the retention above? The counts and errors in them are gone for good; nothing else is touched.">Purge now</x-ui.button>
                    <span class="fm-hint">Applies the retention above immediately.</span>
                </div>
            @endunless
        </section>

        <section class="fm-section">
            <div class="fm-section__head">
                <h2 class="fm-section__title">Branding</h2>
            </div>
            <p class="fm-section__note">
                Uploading replaces what is there now. There is no revert: upload again to change it back.
            </p>

            {{-- Each upload sits with the thing it would replace. They were
                 split apart into a side card, which put the logo you are
                 about to overwrite in a different column from the button
                 that overwrites it. The file input still drives the image
                 above it by id through data-image-preview, so picking a file
                 previews it in place. --}}
            <div class="fm-fields fm-fields--2">
                {{-- The hint is written into the slot rather than passed as
                     the prop, only so it keeps its old position: the prop
                     renders after the slot, which would put the sentence
                     under the preview where it reads as a caption for the
                     image. Same markup either way. --}}
                <x-ui.field label="Logo" for="st-logo" name="logo" wide>
                    <div class="st-file">
                        <label class="st-file__btn" for="st-logo">Choose file</label>
                        <span class="st-file__name" data-file-name-for="st-logo">No file chosen</span>
                        <input type="file" id="st-logo" name="logo" accept="image/*" class="st-file__input"
                               data-image-preview="st-logo-img" @disabled($ro)>
                    </div>
                    <div class="fm-hint">Shown in the sidebar and on the login screen. Up to 2 MB.</div>
                    <div class="st-brandnow">
                        <img class="st-brand__logo" id="st-logo-img" alt="Logo"
                             src="{{ $setting->logo_path ? asset('storage/'.$setting->logo_path) : asset('images/brand/ventasync.png') }}">
                        <span class="st-brand__cap">{{ $setting->logo_path ? 'Uploaded logo' : 'Default logo' }}</span>
                    </div>
                </x-ui.field>

                <x-ui.field label="Favicon" for="st-favicon" name="favicon" wide>
                    <div class="st-file">
                        <label class="st-file__btn" for="st-favicon">Choose file</label>
                        <span class="st-file__name" data-file-name-for="st-favicon">No file chosen</span>
                        <input type="file" id="st-favicon" name="favicon" accept="image/*,.ico,.svg" class="st-file__input"
                               data-image-preview="st-favicon-img" @disabled($ro)>
                    </div>
                    <div class="fm-hint">Shown in the browser tab. PNG, SVG or ICO, square, up to 512 KB.</div>
                    <div class="st-brandnow st-brandnow--icon">
                        <img class="st-brand__fav" id="st-favicon-img" alt="Favicon"
                             src="{{ $setting->favicon_path ? asset('storage/'.$setting->favicon_path) : asset('images/brand/ventasync-icon.png') }}">
                        <span class="st-brand__cap">{{ $setting->favicon_path ? 'Uploaded favicon' : 'Default favicon' }}</span>
                    </div>
                </x-ui.field>
            </div>
        </section>

    </div>
</div>
