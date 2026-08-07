{{-- Fixture for RouteNameSweepTest::test_the_sweep_catches_a_bogus_route_reference().

     Deliberately references a route name, and a routeIs wildcard pattern,
     that do not and must never match anything real in this app. Never
     included in the real app/extensions/resources/views trees the sweep
     scans by default - the self-test reads THIS file directly, proving the
     sweep mechanism can actually fail without ever polluting the real
     collection with a fixture-only name. --}}
<a href="{{ route('this.route.does.not.exist.anywhere') }}">nowhere</a>
<span class="{{ request()->routeIs('this.pattern.does.not.match.*') ? 'is-active' : '' }}"></span>
