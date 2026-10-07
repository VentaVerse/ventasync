@php
    // Update validates all tabs together; carry stored values of required fields the tab does not show.
    $owned = [
        'general' => ['company_name'],
        'website' => ['from_email', 'timezone', 'activity_log_retention_days'],
        'mail'    => ['mail_mailer'],
    ];

    $required = [
        'company_name'                => $setting->company_name,
        'from_email'                  => $setting->from_email,
        'timezone'                    => $setting->timezone ?: 'Asia/Manila',
        'activity_log_retention_days' => $setting->activity_log_retention_days ?: 90,
        'mail_mailer'                 => $setting->mail_mailer ?: 'sendmail',
    ];

    $carried = array_diff_key($required, array_flip($owned[$activeTab] ?? []));
@endphp

@foreach($carried as $name => $value)
    <input type="hidden" name="{{ $name }}" value="{{ old($name, $value) }}">
@endforeach
