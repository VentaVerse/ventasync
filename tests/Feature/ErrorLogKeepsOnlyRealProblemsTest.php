<?php

namespace Tests\Feature;

use Tests\TestCase;

class ErrorLogKeepsOnlyRealProblemsTest extends TestCase
{
    public function test_a_silenced_warning_or_deprecation_never_reaches_the_error_log(): void
    {
        $log = storage_path('logs/error.log');
        $before = is_file($log) ? filesize($log) : 0;

        @trigger_error('silenced deprecation marker', E_USER_DEPRECATED);
        @class_implements('web');

        clearstatcache();
        $added = is_file($log) ? (string) file_get_contents($log, false, null, $before) : '';
        $this->assertStringNotContainsString('silenced deprecation marker', $added);
        $this->assertStringNotContainsString('Class web does not exist', $added);
    }
}
