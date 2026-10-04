<x-layouts.app title="Tilgang stengt" heading="Tilgangen er stengt">
<section class="panel"><h2>Virksomheten er stengt av DekkPilot</h2><p>Kontakt DekkPilot for å avklare tilgang. En betaling åpner ikke en administrativt stengt virksomhet automatisk.</p><a class="button" href="{{ route('billing') }}">Se og administrer abonnementet</a><form method="post" action="{{ route('logout') }}">@csrf<button class="button ghost">Logg ut</button></form></section>
</x-layouts.app>
