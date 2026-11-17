# Sidrena cijena i cjenik

WordPress plugin za obveze iz Zakona o zaštiti potrošača i pravilnika iz **NN 132/2026** (na snazi od 17. 11. 2026.):

- isticanje **bazne cijene** uz svaku cijenu (Pravilnik o isticanju bazne cijene proizvoda),
- objava **strojno čitljivog cjenika** (CSV + XML) na mrežnoj stranici, s arhivom od najmanje 15 dana (Pravilnik o načinu objave cjenika proizvoda).

Namijenjen je stranicama **bez WooCommercea** i **headless** postavu (REST ili WPGraphQL). Radi za bilo koji broj lokacija: jedan kabinet, lanac poslovnica, webshop ili sve zajedno.

> Plugin je tehničko pomagalo, a ne pravni savjet. Točnost cijena (stanje na 10. 9. 2026.) potvrđuje vlasnik stranice.
> Službeni izvor: [NN 132/2026](https://narodne-novine.nn.hr/eli/sluzbeni/2026/132/pdf) i [vijest Ministarstva gospodarstva](https://mingo.gov.hr/vijesti/pravilnici-o-baznim-cijenama-i-objavi-cjenika-veca-transparentnost-za-potrosace-provediva-pravila-za-gospodarstvo/10490)

## Značajke

- Izbornik **Cjenik** u adminu: usluge i proizvodi, bazna cijena i datum, akcija (naziv, najniža cijena u 30 dana), kategorije, filtri po vrsti
- **Više lokacija**: svaka poslovnica, kabinet ili webshop ima svoj cjenik, broj pohrane i arhivu
- Po stavci: na kojim se lokacijama nudi, te vlastita cijena, bazna cijena, akcija i raspoloživost po lokaciji
- Izbor "Kada je stavka uvedena u ponudu?": za nove stavke bazna cijena i datum postave se sami
- Zakazana promjena cijene (primjenjuje se i objavljuje u 6:30 na dan kad vrijedi)
- Upozorenja za obvezne podatke i pregled uživo kako cijena izgleda na stranici
- Automatsko generiranje CSV/XML kod svake promjene cijene, a za proizvode po želji i svaki dan u 6:30 (postavka "Dnevna objava")
- Nazivi datoteka po propisu: `OIB_oblik_adresa_oznaka_ROBA|USLUGA_brojpohrane_GGGGMMDD_SSMM`
- Arhiva prethodnih cjenika (zadano 45 dana, podesivo u postavkama, najmanje 15)
- Gumb za brisanje svih cjenika i ponovni početak numeracije (npr. nakon testiranja)
- REST: `/wp-json/cjenik/v1/stavke`, `/wp-json/cjenik/v1/datoteke`, `/wp-json/cjenik/v1/objekti`
- WPGraphQL: root polje `cjenik`
- Webhook za revalidaciju frontenda i action hook `sc_cjenik_azuriran`

## Što propis traži

Izvor: Pravilnik o isticanju bazne cijene proizvoda i Pravilnik o načinu objave cjenika proizvoda, [NN 132/2026](https://narodne-novine.nn.hr/eli/sluzbeni/2026/132/pdf). Odluke iz NN 101/2026 prestale su važiti.

| | Cjenik proizvoda (robe) | Cjenik usluga |
|---|---|---|
| Kada | kod svake promjene, najkasnije do 11:00 na dan izmjene | isto |
| Stupci (tim redoslijedom) | naziv, šifra, marka, neto količina, jedinica mjere, maloprodajna cijena, posebni oblik prodaje (DA/NE), cijena za JM, barkod, EAN barkod, bazna cijena | naziv, maloprodajna cijena, posebni oblik prodaje (DA/NE), bazna cijena |
| Format | `.csv` (UTF-8, odvojeno s `;`) ili `.xml`, smije `.zip`/`.gz` | isto |
| Naziv datoteke | `OIB_oblik_adresa_oznaka_ROBA_brojpohrane_GGGGMMDD_SSMM` | `..._USLUGA_...` |

- Svaka lokacija i webshop ima svoju datoteku, osim ako trgovac ima isti cjenik na svim prodajnim mjestima (tada `ZA_SVA_PRODAJNA_MJESTA`, plugin to zasad ne radi).
- Arhiva: najmanje 15 dana od zamjene novim cjenikom.
- Podaci se moraju moći preuzimati automatski (bez prijave i blokiranja botova).
- Bazna cijena je cijena na dan 10. 9. 2026. (hrana, piće, kozmetika, sredstva za čišćenje, toaletne potrepštine i proizvodi za kućanstvo: 2. 5. 2025.), za stavke uvedene kasnije cijena na dan uvođenja. Ako je stavka tog dana bila na akciji, bazna je redovna cijena, ne snižena.
- Na stranici se bazna cijena ističe **uz** maloprodajnu cijenu, nedvosmisleno, npr. "Bazna cijena (10. 9. 2026.): X €".

## Lokacije

Svaka lokacija dobiva zasebnu datoteku cjenika. Lokacije se dodaju u **Cjenik → Objava i postavke → Lokacije**:

| Cjenik | Naziv | Oblik | Adresa | Oznaka |
|---|---|---|---|---|
| Usluge | Kabinet Zagreb | kabinet | Ilica 150 Zagreb | U-01 |
| Usluge | Kabinet Split | kabinet | Marmontova 5 Split | U-02 |
| Proizvodi | Webshop | webshop | Ilica 150 Zagreb | W-01 |

- Svaka lokacija je jedan cjenik: uslužni objekt za usluge ili prodajni objekt za proizvode. Ako se na istoj adresi prodaje oboje, dodaju se dvije lokacije.
- Stavka se po defaultu nudi na svim lokacijama svoje vrste, pa i na onima koje se dodaju kasnije. U stavci se lokacija može isključiti ili joj se može dati vlastita cijena, bazna cijena, akcija ili raspoloživost. Prazno polje znači da vrijedi osnovna vrijednost.
- Svaka lokacija ima svoj broj pohrane. Uklonjena lokacija više se ne objavljuje, a njezini stari cjenici ostaju u arhivi koliko je postavljeno (zadano 45 dana).
- Kod nadogradnje s 1.1.x postojeće postavke postaju lokacije `usluga-1` i `proizvod-1`, a brojevi pohrane i arhiva se nastavljaju.

## Instalacija

1. Preuzmite zip iz [Releases](../../releases) ili klonirajte repo u `wp-content/plugins/sidrena-cjenik`.
2. Aktivirajte plugin.
3. **Settings → General:** vremenska zona Zagreb.
4. **Cjenik → Objava i postavke:** naziv subjekta, OIB (ili matični broj obrta) i lokacije (oblik, adresa i oznaka objekta).
5. Serverski cron umjesto WP-Crona:
   ```php
   // wp-config.php
   define('DISABLE_WP_CRON', true);
   ```
   ```
   */15 * * * * wget -q -O - https://cms.example.hr/wp-cron.php >/dev/null 2>&1
   ```
6. Unesite stavke i kliknite **Objavi cjenike sada**.

## Headless frontend

Primjeri su u [`examples/`](examples/):

- [`examples/graphql/cjenik.graphql`](examples/graphql/cjenik.graphql) sadrži upit.
- [`examples/nextjs/Cjenik.jsx`](examples/nextjs/Cjenik.jsx) je komponenta za prikaz.
- [`examples/nextjs/app/api/revalidate/route.js`](examples/nextjs/app/api/revalidate/route.js) je ruta koju plugin poziva nakon promjene. U postavkama plugina upišite npr. `https://example.hr/api/revalidate?secret=TAJNA`, s istom vrijednošću kao `REVALIDATE_SECRET` na frontendu.

Za prikaz jedne lokacije komponenti proslijedite ID lokacije: `<Cjenik objekt="usluga-1" />`. Cijene po lokaciji su u `stavke.lokacije`, a popis lokacija u `cjenik.objekti`.

Datoteke leže u `wp-content/uploads/cjenik/` na WP backendu. Učinite ih dostupnima s javne domene (rewrite/proxy) i linkajte u footeru.

## Ažuriranja

Plugin provjerava zadnji [GitHub Release](../../releases) i nudi update u **Plugins** kao i svaki drugi plugin.

Repo je privatan, pa u `wp-config.php` na stranici klijenta treba dodati token (fine-grained, samo ovaj repo, *Contents: Read-only*):

```php
define('SC_GITHUB_TOKEN', 'github_pat_...');
```

Nova verzija: podigni `Version` u `sidrena-cjenik.php` i `Stable tag` u `readme.txt`, dopuni `CHANGELOG.md`, commitaj i pushaj tag:

```
git tag v1.2.0 && git push origin v1.2.0
```

## Hookovi

| Hook | Tip | Opis |
|---|---|---|
| `sc_cjenik_azuriran` | action | Nakon objave. Argumenti: vrste koje su se promijenile (`['usluga', 'proizvod']`) i ID-jevi lokacija |
| `sc_objekti` | filter | Popis lokacija, npr. za lokacije iz drugog izvora. Svaka lokacija: `id`, `vrsta`, `naziv`, `oblik`, `adresa`, `oznaka` |
| `sc_cjenik_redak` | filter | Jedan redak CSV/XML datoteke. Argumenti: `$row`, `$post`, `$objekt` |

## Licenca

GPL-2.0-or-later
