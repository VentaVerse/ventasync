<div class="fm-body st-body--one">
    <div class="fm-col fm-col--main">

        <section class="fm-section">
            <div class="fm-section__head">
                <h2 class="fm-section__title">Company</h2>
            </div>
            <p class="fm-section__note">
                The company name appears in the sidebar, on the login screen and as the sender
                name on mail this system sends.
            </p>

            <div class="fm-fields fm-fields--2">
                <x-ui.field label="Company name" for="st-company-name" name="company_name" :required="true" wide>
                    <x-ui.input id="st-company-name" name="company_name" maxlength="255" :readonly="$ro"
                                value="{{ old('company_name', $setting->company_name) }}" />
                </x-ui.field>

                <x-ui.field label="Email" for="st-company-email" name="company_email"
                            hint="Where customers should reply. Not the address mail is sent from.">
                    <x-ui.input type="email" id="st-company-email" name="company_email" maxlength="255" :readonly="$ro"
                                value="{{ old('company_email', $setting->company_email) }}" />
                </x-ui.field>

                <x-ui.field label="Phone" for="st-phone" name="phone">
                    <x-ui.input id="st-phone" name="phone" maxlength="32" :readonly="$ro"
                                value="{{ old('phone', $setting->phone) }}" />
                </x-ui.field>
            </div>
        </section>

        <section class="fm-section">
            <div class="fm-section__head">
                <h2 class="fm-section__title">Address</h2>
            </div>

            <div class="fm-fields fm-fields--2">
                <x-ui.field label="Address line 1" for="st-addr1" name="address_line1" wide>
                    <x-ui.input id="st-addr1" name="address_line1" maxlength="255" :readonly="$ro"
                                value="{{ old('address_line1', $setting->address_line1) }}" />
                </x-ui.field>

                <x-ui.field label="Address line 2" for="st-addr2" name="address_line2" wide>
                    <x-ui.input id="st-addr2" name="address_line2" maxlength="255" :readonly="$ro"
                                value="{{ old('address_line2', $setting->address_line2) }}" />
                </x-ui.field>

                <x-ui.field label="City" for="st-city" name="city">
                    <x-ui.input id="st-city" name="city" maxlength="128" :readonly="$ro"
                                value="{{ old('city', $setting->city) }}" />
                </x-ui.field>

                <x-ui.field label="State or province" for="st-state" name="state">
                    <x-ui.input id="st-state" name="state" maxlength="128" :readonly="$ro"
                                value="{{ old('state', $setting->state) }}" />
                </x-ui.field>

                <x-ui.field label="Postal code" for="st-postal" name="postal_code">
                    <x-ui.input id="st-postal" name="postal_code" maxlength="20" class="fm-input--num" :readonly="$ro"
                                value="{{ old('postal_code', $setting->postal_code) }}" />
                </x-ui.field>

                <x-ui.field label="Country" for="st-country" name="country">
                    <x-ui.input id="st-country" name="country" maxlength="128" :readonly="$ro"
                                value="{{ old('country', $setting->country) }}" />
                </x-ui.field>
            </div>
        </section>

    </div>
</div>
