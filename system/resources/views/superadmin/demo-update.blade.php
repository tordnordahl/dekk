<x-layouts.app title="Nullstill demo · DekkPilot" heading="Demo">
<section class="panel" style="max-width:720px;margin:0 auto">
    <p class="eyebrow">SUPERADMIN</p>
    <h2>Nullstill demomiljøet</h2>
    <p class="muted">Du er allerede sikkert innlogget som superadmin. Ingen ekstra innlogging er nødvendig.</p>

    @if(session('demo_report'))
        <div class="report" style="display:grid;gap:8px;margin:20px 0">
            @foreach(session('demo_report') as $item)<span style="padding:10px 12px;background:#edf7f1;border-radius:10px">{{ $item }}</span>@endforeach
        </div>
        <a class="button full" href="{{ route('superadmin') }}">Tilbake til plattformoversikten →</a>
    @else
        <form method="post" action="{{ route('superadmin.demo-update.run') }}" class="stack">
            @csrf
            <label>Antall hovedhjulsett<input type="number" name="sets" min="50" max="2000" value="600" required><small>600 gir en realistisk belastningstest med flere biler per kunde.</small></label>
            <div class="booking-guidance"><strong>Dette erstatter hele demoen</strong><br>Vanlige kunder og virksomheter berøres ikke. Ingen e-post eller SMS sendes.</div>
            <button class="button full">Nullstill og bygg demoen på nytt</button>
        </form>
    @endif
</section>
</x-layouts.app>
