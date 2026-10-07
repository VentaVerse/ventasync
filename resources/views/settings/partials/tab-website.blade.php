@php
    $mailOverrides = ! empty($setting->mail_from_address);
    $activityCap = ($cap = \App\Plans\Plan::limit('activity_days')) === null ? 3650 : max(1, $cap);
    $retention = min((int) old('activity_log_retention_days', $setting->activity_log_retention_days ?? 90), $activityCap);

    // Pass the hint via :hint so it is escaped once; hint="{{ }}" double-encodes.
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
            <div class="fm-fields fm-fields--2">
                <x-ui.field label="Keep activity for (days)" for="st-retention" name="activity_log_retention_days" :required="true">
                    <x-ui.input type="number" id="st-retention" name="activity_log_retention_days"
                                min="{{ min(7, $activityCap) }}" max="{{ $activityCap }}" class="fm-input--num st-days" :readonly="$ro"
                                value="{{ $retention }}" />
                </x-ui.field>
            </div>
        </section>

        <section class="fm-section">
            <div class="fm-section__head">
                <h2 class="fm-section__title">Picture sizes</h2>
            </div>

            <div class="fm-fields fm-fields--3">
                @foreach([
                    \App\Services\Media\ImageCache::THUMB => 'Thumbnail (px)',
                    \App\Services\Media\ImageCache::PREVIEW => 'Preview (px)',
                    \App\Services\Media\ImageCache::PUSH => 'Product (px)',
                ] as $kind => $label)
                    @php [$min, $max] = \App\Services\Media\ImageCache::LIMITS[$kind]; @endphp
                    <x-ui.field :label="$label" for="st-image-{{ $kind }}" name="image_{{ $kind }}_size">
                        <x-ui.input type="number" id="st-image-{{ $kind }}" name="image_{{ $kind }}_size"
                                    min="{{ $min }}" max="{{ $max }}" class="fm-input--num" :readonly="$ro"
                                    value="{{ old('image_' . $kind . '_size', $setting->{'image_' . $kind . '_size'} ?? \App\Services\Media\ImageCache::DEFAULTS[$kind]) }}" />
                    </x-ui.field>
                @endforeach

                @foreach([
                    \App\Services\Media\BrandImage::NAV => 'Sidebar logo height (px)',
                    \App\Services\Media\BrandImage::LOGIN => 'Sign-in logo height (px)',
                ] as $place => $label)
                    <x-ui.field :label="$label" for="st-logo-{{ $place }}-h" name="logo_{{ $place }}_h">
                        <x-ui.input type="number" id="st-logo-{{ $place }}-h" name="logo_{{ $place }}_h"
                                    min="{{ \App\Services\Media\BrandImage::LIMITS['h'][0] }}"
                                    max="{{ \App\Services\Media\BrandImage::LIMITS['h'][1] }}"
                                    class="fm-input--num" :readonly="$ro"
                                    value="{{ old('logo_' . $place . '_h', \App\Services\Media\BrandImage::height($place)) }}" />
                    </x-ui.field>
                @endforeach
            </div>
        </section>

        <section class="fm-section">
            <div class="fm-section__head">
                <h2 class="fm-section__title">Branding</h2>
            </div>
            <div class="fm-fields fm-fields--2">
                <x-ui.field label="Logo" for="st-logo" name="logo" wide>
                    <div class="st-file">
                        <label class="st-file__btn" for="st-logo">Choose file</label>
                        <span class="st-file__name" data-file-name-for="st-logo">No file chosen</span>
                        <button type="button" class="st-file__reset" data-file-reset="st-logo" hidden>Undo</button>
                        <input type="file" id="st-logo" name="logo" accept="image/png,image/jpeg,image/webp,image/svg+xml" class="st-file__input"
                               data-image-preview="st-logo-img" data-max-kb="2048" data-kinds="PNG, JPG, WebP or SVG"
                               aria-describedby="st-logo-error" @disabled($ro)>
                    </div>
                    <div class="fm-hint">Shown in the sidebar and on the login screen. Up to 2 MB.</div>
                    <p class="st-file__error" id="st-logo-error" role="status" hidden></p>
                    <div class="st-brandnow" @unless($setting->logo_path) hidden @endunless>
                        <img class="st-brand__logo" id="st-logo-img" alt="Logo"
                             src="{{ $setting->logo_path ? asset('storage/'.$setting->logo_path) : '' }}">
                        <span class="st-brand__cap">{{ $setting->logo_path ? 'Uploaded logo' : 'Not saved yet' }}</span>
                    </div>
                    @if($setting->logo_path && ! $ro)
                        <button type="submit" name="remove" value="logo" class="st-brand__remove"
                                data-confirm="Remove the uploaded logo? The sidebar and the sign-in screen go back to the VentaSync logo."
                                data-confirm-verb="Remove">Remove logo</button>
                    @endif
                </x-ui.field>

                <x-ui.field label="Favicon" for="st-favicon" name="favicon" wide>
                    <div class="st-file">
                        <label class="st-file__btn" for="st-favicon">Choose file</label>
                        <span class="st-file__name" data-file-name-for="st-favicon">No file chosen</span>
                        <button type="button" class="st-file__reset" data-file-reset="st-favicon" hidden>Undo</button>
                        <input type="file" id="st-favicon" name="favicon" accept="image/png,image/jpeg,image/webp,image/svg+xml,image/x-icon,image/vnd.microsoft.icon,.ico"
                               class="st-file__input" data-image-preview="st-favicon-img" data-max-kb="512" data-kinds="PNG, SVG or ICO"
                               aria-describedby="st-favicon-error" @disabled($ro)>
                    </div>
                    <div class="fm-hint">Shown in the browser tab. PNG, SVG or ICO, square, up to 512 KB.</div>
                    <p class="st-file__error" id="st-favicon-error" role="status" hidden></p>
                    <div class="st-brandnow st-brandnow--icon" @unless($setting->favicon_path) hidden @endunless>
                        <img class="st-brand__fav" id="st-favicon-img" alt="Favicon"
                             src="{{ $setting->favicon_path ? asset('storage/'.$setting->favicon_path) : '' }}">
                        <span class="st-brand__cap">{{ $setting->favicon_path ? 'Uploaded favicon' : 'Not saved yet' }}</span>
                    </div>
                    @if($setting->favicon_path && ! $ro)
                        <button type="submit" name="remove" value="favicon" class="st-brand__remove"
                                data-confirm="Remove the uploaded favicon? The browser tab goes back to the VentaSync icon."
                                data-confirm-verb="Remove">Remove favicon</button>
                    @endif
                </x-ui.field>
            </div>
        </section>

    </div>
</div>
