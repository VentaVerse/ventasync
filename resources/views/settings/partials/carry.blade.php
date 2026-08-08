@php
    /**
     * The required fields the current tab does not show.
     *
     * SettingController::update() is one action behind one route, and it
     * validates the union of all four tabs. Five of those rules are
     * `required`:
     *
     *   company_name                 (General)
     *   from_email                   (Website Settings)
     *   timezone                     (Website Settings)
     *   activity_log_retention_days  (Website Settings)
     *   mail_mailer                  (Mail)
     *
     * Before this round every tab lived inside one <form>, so a save from
     * any tab posted all five. Now each tab is its own page, so a save from
     * the Mail tab would arrive with no company_name and fail validation on
     * a field that is not on screen. This carries the STORED value of each
     * required field the current tab does not render, which reproduces the
     * old payload exactly.
     *
     * Only these five. Nothing optional is carried: an optional field left
     * out of a post is simply not updated, which is the behaviour a
     * per-tab save should have.
     */
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
