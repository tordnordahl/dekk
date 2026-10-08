# Versjonerte kundeavtaler

## Aktivering

Migrasjonen oppretter tomt avtaleverk; den publiserer ikke utkastet og registrerer ingen aksept for eksisterende kunder. Første avtale publiseres fra **Superadmin → Avtaler** etter utfylling av juridisk leverandør, underleverandører/behandlingssteder og eksport-/sletteprosedyre. `resources/legal/agreement-draft.php` er et utgangspunkt, ikke ferdig juridisk kvalitetssikring. Innholdet må stemme med den faktiske driften. Varslingsfrister i avtalen håndteres av leverandøren; publisering sender ikke e-post eller varsler på forhånd.

Før publisering brukes tidligere registreringsvilkår. Etter publisering kreves aktiv aksept av gjeldende avtale ved registrering. Eksisterende eiere og administratorer sendes til en obligatorisk avtaledialog før bruk av tenant-portalen. Vanlige ansatte, superadmin og det reserverte demomiljøet blokkeres ikke. Én autorisert representant godkjenner for hele virksomheten. Superadmin kan ikke godkjenne på kundens vegne. Sperren gjelder de innloggede tenant-nettrutene, ikke eksterne kundeportaler, webhooks eller integrasjons-API-er.

## Dokumentasjon

- `service_agreements`: uforanderlig dokumentinnhold, publiserende superadmin, tidspunkt og SHA-256 av kanonisk JSON.
- `agreement_policy`: peker på gjeldende versjon. Publisering og aksept tar samme transaksjonslås, også ved registrering, for å avvise utdaterte skjemaer.
- `agreement_acceptances`: unik aksept per virksomhet og versjon, kopi av representantens navn/e-post/rolle og virksomhetens navn/org.nr., tidspunkt, IP, nettleser og dokumentets kontrollsum.
- Sletting av en bruker nuller brukerreferansen, men beholder akseptbeviset. Sletting av hele virksomheten sletter tilhørende aksepter. Bevaring ved krav/lovpålagt lagring må vurderes før slik sletting; funksjonen innfører ikke en automatisk oppbevaringsfrist for disse bevisene.
- Gamle `legal_acceptances` beholdes og vises separat. De utgjør ikke aksept av det nye avtaleverket.
- Publiserte versjoner er tilgjengelige på `/avtale/{id}`. De gamle lenkene `/vilkar` og `/databehandleravtale` leder til gjeldende publiserte versjon.

Aksepten er dokumentert elektronisk avkrysning, ikke BankID eller automatisk fullmaktskontroll. Databasetilgang må beskyttes; kontrollsummen er ikke en digital signatur mot en privilegert databaseadministrator.

## Juridisk grunnlag for utkastet

- Datatilsynet: https://www.datatilsynet.no/rettigheter-og-plikter/virksomhetenes-plikter/hvordan-lage-en-databehandleravtale/
- GDPR, særlig artiklene 28, 32–36 og 82: https://eur-lex.europa.eu/eli/reg/2016/679/oj
- Avtaleloven, særlig § 36: https://lovdata.no/dokument/NL/lov/1918-05-31-4

Utkastets ansvarsbegrensninger er ikke en garanti mot krav. Opplysninger om leverandører og slettefrister skal bekreftes, og avtalen bør gjennomgås juridisk før den publiseres.

Leverandørens offentlige forhåndsgodkjenning vises med selskapsnavn, uten personnavn. Publiserende superadmins bruker-ID beholdes internt. Utkastet er utfylt med Jovia Digital AS, org.nr. 938431671, Sørlia 25, 5223 Nesttun, Norge; kontrollert mot Enhetsregisterets API 8. oktober 2026.
