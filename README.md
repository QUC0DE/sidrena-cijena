# Sidrena cijena i cjenik

WordPress plugin za obveze iz **NN 101/2026** (na snazi od 1. 10. 2026.):

- isticanje **dodatne (sidrene) cijene** uz svaku cijenu,
- objava **strojno čitljivog cjenika** (CSV + XML) na mrežnoj stranici, s arhivom od najmanje 30 dana.

Namijenjen je stranicama **bez WooCommercea** (usluge, digitalni materijali) i **headless** postavu (REST ili WPGraphQL).

> Plugin je tehničko pomagalo, a ne pravni savjet. Točnost cijena (stanje na 10. 9. 2026.) potvrđuje vlasnik stranice.
> Službeni izvor: [Pojašnjenja Ministarstva gospodarstva](https://mingo.gov.hr/vijesti/pojasnjenja-za-primjenu-dodatne-cijene-i-objavu-cjenika-od-1-listopada/10440)

## Značajke

- Izbornik **Cjenik** u adminu: usluge i proizvodi, sidrena cijena i datum, akcija (naziv, najniža cijena u 30 dana), kategorije
- Kvačica "Nova stavka" za stavke uvedene nakon 10. 9. 2026. (sidrena cijena i datum postave se sami)
- Automatsko generiranje CSV/XML kod svake promjene, a za proizvode i svaki radni dan u 6:30
- Nazivi datoteka po propisu: `oblik_adresa_oznaka_brojpohrane_dd.mm.gggg_hh:mm`
- Arhiva prethodnih cjenika (45 dana)
- REST: `/wp-json/cjenik/v1/stavke`, `/wp-json/cjenik/v1/datoteke`
- WPGraphQL: root polje `cjenik`
- Webhook za revalidaciju frontenda i action hook `sc_cjenik_azuriran`

## Instalacija

1. Preuzmite zip iz [Releases](../../releases) ili klonirajte repo u `wp-content/plugins/sidrena-cjenik`.
2. Aktivirajte plugin.
3. **Settings → General:** vremenska zona Zagreb.
4. **Cjenik → Postavke i datoteke:** adresa, oblik i oznaka objekta.
5. Serverski cron umjesto WP-Crona:
   ```php
   // wp-config.php
   define('DISABLE_WP_CRON', true);
   ```
   ```
   */15 * * * * wget -q -O - https://cms.example.hr/wp-cron.php >/dev/null 2>&1
   ```
6. Unesite stavke i kliknite **Generiraj cjenik sada**.

## Headless frontend

Primjeri su u [`examples/`](examples/):

- [`examples/graphql/cjenik.graphql`](examples/graphql/cjenik.graphql) sadrži upit.
- [`examples/nextjs/Cjenik.jsx`](examples/nextjs/Cjenik.jsx) je komponenta za prikaz.
- [`examples/nextjs/app/api/revalidate/route.js`](examples/nextjs/app/api/revalidate/route.js) je ruta koju plugin poziva nakon promjene. U postavkama plugina upišite npr. `https://example.hr/api/revalidate?secret=TAJNA`, s istom vrijednošću kao `REVALIDATE_SECRET` na frontendu.

Datoteke leže u `wp-content/uploads/cjenik/` na WP backendu. Učinite ih dostupnima s javne domene (rewrite/proxy) i linkajte u footeru.

## Ažuriranja

Plugin provjerava zadnji [GitHub Release](../../releases) i nudi update u **Plugins** kao i svaki drugi plugin.

Repo je privatan, pa u `wp-config.php` na stranici klijenta treba dodati token (fine-grained, samo ovaj repo, *Contents: Read-only*):

```php
define('SC_GITHUB_TOKEN', 'github_pat_...');
```

Nova verzija: podigni `Version` u `sidrena-cjenik.php` i `Stable tag` u `readme.txt`, dopuni `CHANGELOG.md`, commitaj i pushaj tag:

```
git tag v1.0.2 && git push origin v1.0.2
```

## Hookovi

| Hook | Tip | Opis |
|---|---|---|
| `sc_cjenik_azuriran` | action | Nakon generiranja datoteka, argument: `['usluga', 'proizvod']` |

## Licenca

GPL-2.0-or-later
