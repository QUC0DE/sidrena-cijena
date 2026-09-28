# Changelog

## [1.2.0] - 2026-09-28
### Dodano
- Više lokacija (uslužnih i prodajnih objekata). Svaka ima svoj cjenik, broj pohrane i arhivu.
- U stavci: na kojim se lokacijama nudi, te vlastita cijena, dodatna cijena, akcija i raspoloživost po lokaciji.
- Stupac "Lokacije" u popisu stavki kad postoji više lokacija.
- REST `/wp-json/cjenik/v1/objekti`, GraphQL `cjenik.objekti`, `stavke.lokacije` i podaci o lokaciji na datotekama.
- Filteri `sc_objekti` i `sc_cjenik_redak`. `sc_cjenik_azuriran` dobiva i ID-jeve lokacija.
- Provjere: lokacija bez adrese, dvije lokacije s istim nazivom datoteke, stavke bez lokacije.
### Promijenjeno
- Postavke lokacije (oblik, adresa, oznaka) prebačene su u popis lokacija. Postojeće instalacije migriraju se same, a brojevi pohrane i arhiva se nastavljaju.
- Kad s lokacije nestanu sve stavke, objavljuje se prazan cjenik umjesto da ostane stari.
### Ispravljeno
- Dnevna objava se nakon zakašnjelog WP-Crona vraća na 6:30 umjesto da se pomiče.

## [1.1.0] - 2026-09-28
### Dodano
- Zakazana promjena cijene: nova cijena i datum od kojeg vrijedi, primjenjuje se i objavljuje u 6:30 tog dana.
- Upozorenja kod spremanja stavke: nedostaje cijena, naziv akcije, najniža cijena u 30 dana ili marka.
- Pregled uživo kako će cijena izgledati na stranici.
- Filtri "Usluge" i "Proizvodi" u popisu stavki.
- Stranica "Objava i postavke": status oba cjenika, sljedeća automatska objava, provjera vremenske zone, crona i adrese, primjer naziva datoteke.
### Promijenjeno
- Forma stavke je podijeljena na sekcije. Kvačicu "Nova stavka" zamjenjuje izbor "Kada je stavka uvedena u ponudu?".
- Akcija se uključuje kvačicom. Kad se isključi, akcijska polja se brišu.
- Cjenik proizvoda objavljuje se svaki dan u 6:30, i vikendom.
- Arhiva je prikazana u tablici.

## [1.0.2] - 2026-09-27
### Dodano
- Gumb "Obriši sve cjenike i kreni ispočetka" u postavkama (briše datoteke i arhivu, vraća broj pohrane na 1).

## [1.0.1] - 2026-09-27
### Dodano
- Automatska ažuriranja iz GitHub Releasesa.
### Promijenjeno
- Naziv plugina bez "(headless)".

## [1.0.0] - 2026-09-27
### Dodano
- Unos usluga i proizvoda sa sidrenom cijenom i akcijama.
- Generiranje CSV/XML cjenika s arhivom.
- REST API i WPGraphQL (root polje `cjenik`).
