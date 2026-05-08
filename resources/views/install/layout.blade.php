<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>VentaSync Installer — @yield('title', 'Setup')</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    @vite('resources/css/install.css')
</head>
<body>

<div class="installer">

    {{-- Header --}}
    <header class="installer-header">
        <div class="brand">
            <div class="brand-logo">
                <img src="/images/brand/ventasync-legacy.png" alt="VentaSync" onerror="this.style.display='none'">
            </div>
            <div class="brand-info">
                <div class="brand-name">VentaSync</div>
                <div class="brand-tag">Self-hosted ERP setup</div>
            </div>
        </div>
        <div class="installer-meta">
            <div class="meta-dot"></div>
            <span>Step {{ $currentStep }} of {{ $totalSteps }}</span>
        </div>
    </header>

    {{-- Main shell — sidebar (steps) + content --}}
    <main class="installer-shell">

        {{-- Step rail --}}
        <aside class="installer-sidebar">
            <div class="sidebar-heading">Installation Steps</div>
            <ol class="step-list">
                @foreach($steps as $index => $step)
                    @php
                        $stepNum = $index + 1;
                        $stepClass = match (true) {
                            $stepNum < $currentStep => 'step done',
                            $stepNum === $currentStep => 'step active',
                            default => 'step',
                        };
                    @endphp
                    <li class="{{ $stepClass }}">
                        <div class="step-num">{{ $stepNum < $currentStep ? '✓' : $stepNum }}</div>
                        <div class="step-content">
                            <span class="step-name">{{ $step['name'] }}</span>
                            <span class="step-desc">{{ $step['desc'] }}</span>
                        </div>
                    </li>
                @endforeach
            </ol>

            <div class="sidebar-progress">
                <div class="sidebar-progress-label">Progress</div>
                <div class="progress-bar">
                    <div class="progress-fill" style="width: {{ $progressPercent }}%"></div>
                </div>
                <div class="sidebar-progress-text">
                    <span>{{ $currentStep }} of {{ $totalSteps }}</span>
                    <span>{{ $progressPercent }}%</span>
                </div>
            </div>
        </aside>

        {{-- Step content --}}
        <section class="installer-content">
            @yield('content')
        </section>

    </main>

</div>

@stack('scripts')

</body>
</html>
