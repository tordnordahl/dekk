@props(['name', 'size' => 20])
<svg class="ui-icon" width="{{ $size }}" height="{{ $size }}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
@switch($name)
@case('home')<path d="M3 11.5 12 4l9 7.5"/><path d="M5.5 10.5V20h13v-9.5M9.5 20v-6h5v6"/>@break
@case('workday')<path d="M8 5h8M9 3h6v4H9z"/><rect x="5" y="5" width="14" height="16" rx="2"/><path d="m9 14 2 2 4-5"/>@break
@case('calendar')<rect x="3" y="5" width="18" height="16" rx="3"/><path d="M8 3v4M16 3v4M3 10h18M8 14h.01M12 14h.01M16 14h.01M8 18h.01M12 18h.01"/>@break
@case('orders')<rect x="5" y="4" width="14" height="17" rx="2"/><path d="M9 4V2h6v2M8.5 10h7M8.5 14h4M8.5 18h6"/>@break
@case('customers')<path d="M16 20v-2a4 4 0 0 0-4-4H7a4 4 0 0 0-4 4v2"/><circle cx="9.5" cy="7" r="4"/><path d="M17 11a3 3 0 0 0 0-6M21 20v-2a4 4 0 0 0-3-3.85"/>@break
@case('warehouse')<path d="m3 9 9-5 9 5v11H3zM3 9h18M8 20v-7h8v7M8 16h8"/>@break
@case('quote')<path d="M20 13 13 20l-9-9V4h7z"/><circle cx="8.5" cy="8.5" r="1"/>@break
@case('chart')<path d="M4 20V10M10 20V4M16 20v-7M22 20H2"/>@break
@case('settings')<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .34 1.88l.06.06-2.83 2.83-.06-.06a1.7 1.7 0 0 0-1.88-.34 1.7 1.7 0 0 0-1.03 1.56V21h-4v-.09A1.7 1.7 0 0 0 8.94 19.4a1.7 1.7 0 0 0-1.88.34l-.06.06-2.83-2.83.06-.06A1.7 1.7 0 0 0 4.57 15 1.7 1.7 0 0 0 3 14H3v-4h.09A1.7 1.7 0 0 0 4.6 8.94a1.7 1.7 0 0 0-.34-1.88L4.2 7l2.83-2.83.06.06A1.7 1.7 0 0 0 9 4.57 1.7 1.7 0 0 0 10 3h4v.09A1.7 1.7 0 0 0 15.06 4.6a1.7 1.7 0 0 0 1.88-.34L17 4.2 19.83 7l-.06.06A1.7 1.7 0 0 0 19.43 9 1.7 1.7 0 0 0 21 10h.09v4H21A1.7 1.7 0 0 0 19.4 15z"/>@break
@case('star')<path d="m12 3 2.7 5.5 6.1.9-4.4 4.3 1 6.1-5.4-2.9-5.4 2.9 1-6.1-4.4-4.3 6.1-.9z"/>@break
@case('logout')<path d="M10 5H5v14h5M14 8l4 4-4 4M18 12H9"/>@break
@case('menu')<path d="M4 7h16M4 12h16M4 17h16"/>@break
@case('plus')<path d="M12 5v14M5 12h14"/>@break
@case('team')<circle cx="9" cy="8" r="3"/><path d="M3.5 20v-2a4.5 4.5 0 0 1 9 0v2M16 4v6M13 7h6M15 14h5v6h-5z"/>@break
@case('tires')<circle cx="12" cy="12" r="9"/><circle cx="12" cy="12" r="4"/><path d="m12 8 2.5-3M16 12l3 1M12 16l-1 3M8 12l-3-2"/>@break
@case('services')<path d="M4 7h16M4 12h16M4 17h16"/><circle cx="8" cy="7" r="2" fill="currentColor" stroke="none"/><circle cx="15" cy="12" r="2" fill="currentColor" stroke="none"/><circle cx="10" cy="17" r="2" fill="currentColor" stroke="none"/>@break
@case('import')<path d="M12 3v12M7 10l5 5 5-5M5 21h14"/>@break
@case('accounting')<path d="M4 20h16M6 20V9h12v11M9 13h6M9 16h6M8 9V5h8v4"/>@break
@case('message')<path d="M4 5h16v12H8l-4 4zM8 9h8M8 13h5"/>@break
@default<circle cx="12" cy="12" r="9"/><path d="M12 8v4l3 2"/>
@endswitch
</svg>
