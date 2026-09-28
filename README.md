# Sidrena cijena i cjenik

WordPress plugin za obveze iz **NN 101/2026** (na snazi od 1. 10. 2026.):

- isticanje **dodatne (sidrene) cijene** uz svaku cijenu,
- objava **strojno čitljivog cjenika** (CSV + XML) na mrežnoj stranici, s arhivom od najmanje 30 dana.

Namijenjen je stranicama **bez WooCommercea** i **headless** postavu (REST ili WPGraphQL). Radi za bilo koji broj lokacija: jedan kabinet, lanac poslovnica, webshop ili sve zajedno.

> Plugin je tehničko pomagalo, a ne pravni savjet. Točnost cijena (stanje na 10. 9. 2026.) potvrđuje vlasnik stranice.
> Službeni izvor: [Pojašnjenja Ministarstva gospodarstva](https://mingo.gov.hr/vijesti/pojasnjenja-za-primjenu-dodatne-cijene-i-objavu-cjenika-od-1-listopada/10440)

## Značajke

- Izbornik **Cjenik** u adminu: usluge i proizvodi, sidrena cijena i datum, akcija (naziv, najniža cijena u 30 dana), kategorije, filtri po vrsti
- **Više lokacija**: svaka poslovnica, kabinet ili webshop ima svoj cjenik, broj pohrane i arhivu
- Po stavci: na kojim se lokacijama nudi, te vlastita cijena, dodatna cijena, akcija i raspoloživost po lokaciji
- Izbor "Kada je stavka uvedena u ponudu?": za nove stavke sidrena cijena i datum postave se sami
- Zakazana promjena cijene (primjenjuje se i objavljuje u 6:30 na dan kad vrijedi)
- Upozorenja za obvezne podatke i pregled uživo kako cijena izgleda na stranici
- Automatsko generiranje CSV/XML kod svake promjene, a za proizvode i svaki dan u 6:30
- Nazivi datoteka po propisu: `oblik_adresa_oznaka_brojpohrane_dd.mm.gggg_hh:mm`
- Arhiva prethodnih cjenika (45 dana)
- Gumb za brisanje svih cjenika i ponovni početak numeracije (npr. nakon testiranja)
- REST: `/wp-json/cjenik/v1/stavke`, `/wp-json/cjenik/v1/datoteke`, `/wp-json/cjenik/v1/objekti`
- WPGraphQL: root polje `cjenik`
- Webhook za revalidaciju frontenda i action hook `sc_cjenik_azuriran`

## Što propis traži

Izvor: [Odluka o objavi cjenika, NN 101/2026](https://narodne-novine.nn.hr/clanci/sluzbeni/2026_09_101_1213.html) i pojašnjenja Ministarstva gospodarstva.

| | Cjenik proizvoda | Cjenik usluga |
|---|---|---|
| Kada | jednom dnevno, do 8:00 | kod svake promjene, do 8:00 na dan kad promjena vrijedi |
| Stupci | naziv, šifra, marka, jedinica mjere, cijena za JM, maloprodajna cijena, posebni oblik prodaje i njegov naziv, sidrena cijena, barkod, dostupnost | naziv, maloprodajna cijena, posebni oblik prodaje i njegov naziv, sidrena cijena |
| Naziv datoteke | `oblik_adresa_oznaka_brojpohrane_dd.mm.gggg_hh:mm` | isto |

- Proizvodi i usluge imaju **odvojene datoteke**, a svaka lokacija i webshop svoju datoteku.
- Arhiva: najmanje 30 dana od objave.
- Na stranici se sidrena cijena ističe **uz** redovnu cijenu, na istom cjeniku, ne na posebnom. Dovoljno je napisati "Cijena na 10. 9. 2026.: X €".

## Lokacije

Propis traži zasebnu datoteku cjenika za svaku lokaciju, i kad su cijene iste. Lokacije se dodaju u **Cjenik → Objava i postavke → Lokacije**:

| Cjenik | Naziv | Oblik | Adresa | Oznaka |
|---|---|---|---|---|
| Usluge | Kabinet Zagreb | kabinet | Ilica 150 Zagreb | U-01 |
| Usluge | Kabinet Split | kabinet | Marmontova 5 Split | U-02 |
| Proizvodi | Webshop | webshop | Ilica 150 Zagreb | W-01 |

- Svaka lokacija je jedan cjenik: uslužni objekt za usluge ili prodajni objekt za proizvode. Ako se na istoj adresi prodaje oboje, dodaju se dvije lokacije.
- Stavka se po defaultu nudi na svim lokacijama svoje vrste, pa i na onima koje se dodaju kasnije. U stavci se lokacija može isključiti ili joj se može dati vlastita cijena, dodatna cijena, akcija ili raspoloživost. Prazno polje znači da vrijedi osnovna vrijednost.
- Svaka lokacija ima svoj broj pohrane. Uklonjena lokacija više se ne objavljuje, a njezini stari cjenici ostaju u arhivi 45 dana.
- Kod nadogradnje s 1.1.x postojeće postavke postaju lokacije `usluga-1` i `proizvod-1`, a brojevi pohrane i arhiva se nastavljaju.

## Instalacija

1. Preuzmite zip iz [Releases](../../releases) ili klonirajte repo u `wp-content/plugins/sidrena-cjenik`.
2. Aktivirajte plugin.
3. **Settings → General:** vremenska zona Zagreb.
4. **Cjenik → Objava i postavke:** naziv subjekta i lokacije (oblik, adresa i oznaka objekta).
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
