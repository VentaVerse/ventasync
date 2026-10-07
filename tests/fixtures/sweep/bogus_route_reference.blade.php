<a href="{{ route('this.route.does.not.exist.anywhere') }}">nowhere</a>
<span class="{{ request()->routeIs('this.pattern.does.not.match.*') ? 'is-active' : '' }}"></span>
