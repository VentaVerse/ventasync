            <div class="fm-card">
                <div class="fm-card__head"><h2 class="fm-card__title">Limits</h2></div>
                <div class="fm-card__body">
                    <x-ui.field label="Allowed IP addresses" for="st-api-ips" name="allowed_ips"
                                hint="One per line, addresses or ranges such as 203.0.113.0/24. Empty means anywhere.">
                        <textarea id="st-api-ips" name="allowed_ips" class="x-input" rows="3">{{ old('allowed_ips', $allowedIps) }}</textarea>
                    </x-ui.field>

                    <x-ui.field label="Calls per minute" for="st-api-cap" name="calls_per_minute"
                                hint="Empty means no cap. A cap protects the server from software that calls in a loop.">
                        <x-ui.input type="number" id="st-api-cap" name="calls_per_minute" class="fm-input--num" min="1" max="600"
                                    value="{{ old('calls_per_minute', $callsPerMinute) }}" />
                    </x-ui.field>
                </div>
            </div>
