{{-- Fixture for ViewResolutionSweepTest::test_the_sweep_catches_a_bogus_view_reference().

     Deliberately references a view name that does not, and must never,
     exist anywhere in this app. This file is never included in the real
     app/extensions/resources/views trees the sweep scans by default - the
     self-test points the collector at THIS file directly, proving the
     sweep mechanism can actually fail without ever polluting the real
     collection with a fixture-only name. --}}
@include('this-view-does-not-exist-anywhere-in-the-app')
