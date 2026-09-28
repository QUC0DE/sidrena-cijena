const QUERY = `query { cjenik {
  kategorije { naziv slug stavke {
    databaseId naziv cijena sidrenaCijena sidrenaDatum jedinicaMjere akcija { cijena naziv najniza30Dana }
    lokacije { objekt cijena sidrenaCijena akcija { cijena naziv najniza30Dana } }
  } }
  objekti { id vrsta naziv adresa }
  datoteke { objekt vrsta lokacija naziv csv xml }
  arhiva { objekt vrsta lokacija naziv csv xml objavljeno }
} }`;

const NASLOVI = { usluga: 'Cjenik usluga', proizvod: 'Cjenik proizvoda' };

const eur = (n) => (n == null ? '–' : n.toLocaleString('hr-HR', { style: 'currency', currency: 'EUR' }));
const dan = (d) => new Date(`${d}T12:00:00`).toLocaleDateString('hr-HR');

// `objekt` is a location id from `cjenik.objekti`; without it the base prices are shown.
export default async function Cjenik({ objekt } = {}) {
  const res = await fetch(`${process.env.WP_URL}/graphql`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ query: QUERY }),
    next: { tags: ['cjenik'] },
  });
  const { data: { cjenik } } = await res.json();

  const cijeneNaLokaciji = (s) => (objekt ? s.lokacije.find((l) => l.objekt === objekt) : s);
  const datoteke = cjenik.datoteke.filter((d) => !objekt || d.objekt === objekt || d.vrsta !== 'usluga');
  const arhiva = cjenik.arhiva.filter((d) => !objekt || d.objekt === objekt);

  return (
    <section>
      {cjenik.kategorije.map((k) => {
        const stavke = k.stavke.map((s) => ({ ...s, c: cijeneNaLokaciji(s) })).filter((s) => s.c);
        if (!stavke.length) return null;
        return (
          <div key={k.slug}>
            <h2>{k.naziv}</h2>
            {stavke.map(({ c, ...s }) => (
              <div key={s.databaseId}>
                <span>{s.naziv}{s.jedinicaMjere ? ` (${s.jedinicaMjere})` : ''}</span>
                {c.akcija ? (
                  <>
                    <strong>{eur(c.akcija.cijena)}</strong> {c.akcija.naziv && `(${c.akcija.naziv})`}
                    <small>Najniža cijena u zadnjih 30 dana: {eur(c.akcija.najniza30Dana)}</small>
                  </>
                ) : (
                  <strong>{eur(c.cijena)}</strong>
                )}
                {/* The ministry recommends labelling the anchor price with its date only. */}
                <small>Cijena na {dan(s.sidrenaDatum)}: {eur(c.sidrenaCijena)}</small>
              </div>
            ))}
          </div>
        );
      })}
      <div>
        {datoteke.map((d) => (
          <div key={d.naziv}>
            {NASLOVI[d.vrsta]}, {d.lokacija} (strojno čitljivo): <a href={d.csv}>CSV</a> / <a href={d.xml}>XML</a>
          </div>
        ))}
        {arhiva.length > 0 && (
          <details>
            <summary>Arhiva cjenika</summary>
            {arhiva.map((d) => (
              <div key={d.naziv}>
                {NASLOVI[d.vrsta]}, {d.lokacija}, {new Date(d.objavljeno).toLocaleString('hr-HR')}: <a href={d.csv}>CSV</a> / <a href={d.xml}>XML</a>
              </div>
            ))}
          </details>
        )}
      </div>
    </section>
  );
}
