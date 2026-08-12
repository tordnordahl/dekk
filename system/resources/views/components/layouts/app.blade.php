<!doctype html>
<html lang="nb">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><link rel="icon" href="{{ asset('public/favicon.ico') }}" sizes="any">
    <meta name="csrf-token" content="{{ csrf_token() }}"><title>{{ $title ?? 'DekkPilot' }}</title>
    @foreach(['app.css','operations.css','vehicle.css','admin.css','ui-fixes.css','inventory.css','inventory-table.css','inventory-density.css','tire-catalog.css','label-modal.css','workday.css','opportunities.css','quote-tools.css','booking-calendar.css','booking-actions.css','email-preview.css','dashboard.css','business-ranking.css','statistics.css','app-shell.css','ux-review.css','design-polish.css','floor-details.css','technician-mode.css','help.css','profile-menu-fix.css'] as $stylesheet)
    <link rel="stylesheet" href="{{ route('system.asset', ['filename'=>$stylesheet]) }}?v=20260812-9">
    @endforeach
</head>
<body class="{{ session('ui_mode') === 'technician' ? 'ui-mode-technician' : 'ui-mode-portal' }}"><a class="screen-reader-only" href="#main-content">Hopp til hovedinnhold</a>
@if(session('demo_read_only'))<div class="impersonation-bar" style="position:sticky;top:0;z-index:1000"><span>👁 <strong>Skrivebeskyttet demo</strong> – du kan se hele systemet, men ingen data kan endres eller sendes.</span></div>@endif
<div class="shell">
    <aside class="sidebar" id="main-navigation">
        <button class="nav-close" type="button" data-nav-close aria-label="Lukk meny">×</button>
        <a class="brand" href="{{ route('dashboard') }}"><span class="brandmark">D</span><span>DekkPilot<small>Drift uten friksjon</small></span></a>
        <nav aria-label="Hovedmeny">
            @if(session('ui_mode') === 'technician')
            <a class="{{ request()->routeIs('workday*') ? 'active' : '' }}" href="{{ route('workday') }}"><x-icon name="workday"/><span>I dag</span></a>
            <a class="{{ request()->routeIs('warehouse.map') ? 'active' : '' }}" href="{{ route('warehouse.map') }}"><x-icon name="warehouse"/><span>Lagerkart</span></a>
            <a class="{{ request()->routeIs('inventory*') ? 'active' : '' }}" href="{{ route('inventory') }}"><x-icon name="tires"/><span>Hjulhotell</span></a>
            <a class="{{ request()->routeIs('actions') ? 'active' : '' }}" href="{{ route('actions') }}"><x-icon name="orders"/><span>Avvik</span></a>
            <a href="{{ route('ui-mode.choose') }}"><x-icon name="settings"/><span>Bytt visning</span></a>
            @else
            @if(in_array(auth()->user()->role,['technician','warehouse'],true))
            <a class="{{ request()->routeIs('dashboard') ? 'active' : '' }}" href="{{ route('dashboard') }}"><x-icon name="home"/><span>Oversikt</span></a>
            <a class="{{ request()->routeIs('actions') ? 'active' : '' }}" href="{{ route('actions') }}"><x-icon name="orders"/><span>Krever handling</span></a>
            <a class="{{ request()->routeIs('workday*') ? 'active' : '' }}" href="{{ route('workday') }}"><x-icon name="workday"/><span>Min arbeidsdag</span></a>
            @if(auth()->user()->role==='technician')<a class="{{ request()->routeIs('bookings') ? 'active' : '' }}" href="{{ route('bookings') }}"><x-icon name="calendar"/><span>Timebok</span></a>@endif
            <a class="{{ request()->routeIs('warehouse.map') ? 'active' : '' }}" href="{{ route('warehouse.map') }}"><x-icon name="warehouse"/><span>Lagerkart</span></a>
            <a class="{{ request()->routeIs('inventory*') ? 'active' : '' }}" href="{{ route('inventory') }}"><x-icon name="tires"/><span>Hjulhotell</span></a>
            @if(auth()->user()->role==='technician')<a class="{{ request()->routeIs('work-orders*') ? 'active' : '' }}" href="{{ route('work-orders.index') }}"><x-icon name="orders"/><span>Arbeidsordrer</span></a>@endif
            @else
            <a class="{{ request()->routeIs('dashboard') ? 'active' : '' }}" href="{{ route('dashboard') }}"><x-icon name="home"/><span>Oversikt</span></a>
            <a class="{{ request()->routeIs('actions') ? 'active' : '' }}" href="{{ route('actions') }}"><x-icon name="orders"/><span>Krever handling</span></a>
            <a class="{{ request()->routeIs('workday*') ? 'active' : '' }}" href="{{ route('workday') }}"><x-icon name="workday"/><span>Min arbeidsdag</span></a>
            <a class="{{ request()->routeIs('bookings*') ? 'active' : '' }}" href="{{ route('bookings') }}"><x-icon name="calendar"/><span>Bookinger</span></a>
            @if(in_array(auth()->user()->role,['owner','admin','manager','customer_service'],true))<a class="{{ request()->routeIs('season-recall*') ? 'active' : '' }}" href="{{ route('season-recall.index') }}"><x-icon name="calendar"/><span>Sesonginnkalling</span></a>@endif
            <a class="{{ request()->routeIs('work-orders*') ? 'active' : '' }}" href="{{ route('work-orders.index') }}"><x-icon name="orders"/><span>Arbeidsordrer</span></a>
            <a class="{{ request()->routeIs('customers*') ? 'active' : '' }}" href="{{ route('customers') }}"><x-icon name="customers"/><span>Kunder</span></a>
            <a class="{{ request()->routeIs('inventory*') ? 'active' : '' }}" href="{{ route('inventory') }}"><x-icon name="warehouse"/><span>Hjulhotell</span></a>
            <a class="{{ request()->routeIs('hotel-agreements*') ? 'active' : '' }}" href="{{ route('hotel-agreements.index') }}"><x-icon name="orders"/><span>Hotellavtaler</span></a>
            <a class="{{ request()->routeIs('quotes*') ? 'active' : '' }}" href="{{ route('quotes') }}"><x-icon name="quote"/><span>Tilbud</span></a>
            <a class="{{ request()->routeIs('statistics') ? 'active' : '' }}" href="{{ route('statistics') }}"><x-icon name="chart"/><span>Statistikk</span></a>
            @if(in_array(auth()->user()->role, ['owner','admin'], true))<a class="mobile-admin {{ request()->routeIs('admin*') ? 'active' : '' }}" href="{{ route('admin') }}"><x-icon name="settings"/><span>Admin</span></a>@endif
            @endif
            @endif
        </nav>
        <details class="profile-menu">
            <summary aria-label="Åpne brukermeny"><span class="avatar">{{ mb_substr(auth()->user()->name, 0, 1) }}</span><span class="profile-menu-person"><strong>{{ auth()->user()->name }}</strong><small>{{ session('ui_mode')==='technician'?'Teknikermodus':(auth()->user()->is_super_admin ? 'Superadmin' : ucfirst(auth()->user()->role)) }}</small></span><span class="profile-menu-chevron" aria-hidden="true">⌃</span></summary>
            <div class="profile-menu-popover" role="menu">
                <div class="profile-menu-heading"><small>INNLOGGET SOM</small><strong>{{ auth()->user()->email }}</strong></div>
                <a href="{{ route('ui-mode.choose') }}" role="menuitem"><span><x-icon name="home" size="18"/></span><span><strong>Bytt visning</strong><small>Portal eller teknikermodus</small></span></a>
                <a href="{{ route('help') }}" role="menuitem"><span class="profile-menu-symbol">?</span><span><strong>Hjelp</strong><small>Åpne brukerhåndboken</small></span></a>
                @if(auth()->user()->is_super_admin)<a class="super" href="{{ route('superadmin') }}" role="menuitem"><span><x-icon name="star" size="18"/></span><span><strong>Superadmin</strong><small>Se alle SaaS-kunder</small></span></a>@endif
                @if(auth()->user()->is_super_admin || in_array(auth()->user()->role,['owner','admin'],true))<a href="{{ route('admin') }}" role="menuitem"><span><x-icon name="settings" size="18"/></span><span><strong>Administrasjon</strong><small>Oppsett og integrasjoner</small></span></a>@endif
                <form method="post" action="{{ route('logout') }}">@csrf<button role="menuitem"><span><x-icon name="logout" size="18"/></span><span><strong>Logg ut</strong><small>Avslutt den sikre økten</small></span></button></form>
            </div>
        </details>
    </aside>
    <button class="nav-backdrop" type="button" data-nav-backdrop aria-label="Lukk meny" tabindex="-1"></button>
    <main id="main-content" tabindex="-1">
        @if(request()->attributes->get('impersonated_organization'))<div class="impersonation-bar"><span>★ Du arbeider som superadmin i <strong>{{ request()->attributes->get('impersonated_organization')->name }}</strong></span><form method="post" action="{{ route('superadmin.leave') }}">@csrf<button>Tilbake til alle kunder</button></form></div>@endif
        <header class="topbar"><div class="topbar-title"><button class="nav-toggle" type="button" data-nav-toggle aria-label="Skjul eller vis meny" aria-controls="main-navigation" aria-expanded="true"><x-icon name="menu" size="21"/></button><div><p class="eyebrow">{{ now()->translatedFormat('l j. F') }}</p><h1>{{ $heading ?? 'God dag' }}</h1></div></div>@if(!in_array(auth()->user()->role,['technician','warehouse','accounting'],true))<div class="top-actions"><a class="button ghost" href="{{ route('customers', ['new' => 1]) }}"><x-icon name="plus" size="17"/>Ny kunde</a><a class="button" href="{{ route('bookings', ['new' => 1]) }}"><x-icon name="plus" size="17"/>Ny booking</a></div>@endif</header>
        @if(session('success'))<div class="flash">✓ {{ session('success') }}</div>@endif
        @if(session('warning'))<div class="warning-flash">! {{ session('warning') }}</div>@endif
        @if($errors->any())<div class="errors"><strong>Noe må rettes:</strong><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
        {{ $slot }}
    </main>
</div>
@if(session('ui_mode') === 'technician')<nav class="technician-mobile-nav" aria-label="Teknikermeny"><a class="{{ request()->routeIs('workday*')?'active':'' }}" href="{{ route('workday') }}"><x-icon name="workday"/><span>I dag</span></a><a class="{{ request()->routeIs('warehouse.map')?'active':'' }}" href="{{ route('warehouse.map') }}"><x-icon name="warehouse"/><span>Kart</span></a><a class="{{ request()->routeIs('inventory*')?'active':'' }}" href="{{ route('inventory') }}"><x-icon name="tires"/><span>Hjul</span></a><a class="{{ request()->routeIs('actions')?'active':'' }}" href="{{ route('actions') }}"><x-icon name="orders"/><span>Avvik</span></a><a href="{{ route('ui-mode.choose') }}"><x-icon name="settings"/><span>Bytt</span></a></nav>@endif
@foreach(['app-shell.js','profile-menu.js','email-preview.js','booking-capacity.js','quote-preview.js','ux-review.js'] as $script)
<script defer src="{{ route('system.asset', ['filename'=>$script]) }}?v=20260812-9"></script>
@endforeach
</body>
</html>
