const QUERY = `query { cjenik {
  kategorije { naziv slug stavke { databaseId naziv cijena sidrenaCijena sidrenaDatum jedinicaMjere akcija { cijena naziv najniza30Dana } } }
  datoteke { vrsta naziv csv xml }
  arhiva { vrsta naziv csv xml objavljeno }
} }`;

const NASLOVI = { usluga: 'Cjenik usluga', proizvod: 'Cjenik proizvoda' };

const eur = (n) => (n == null ? '–' : n.toLocaleString('hr-HR', { style: 'currency', currency: 'EUR' }));
const dan = (d) => new Date(`${d}T12:00:00`).toLocaleDateString('hr-HR');

export default async function Cjenik() {
  const res = await fetch(`${process.env.WP_URL}/graphql`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ query: QUERY }),
    next: { tags: ['cjenik'] },
  });
  const { data: { cjenik } } = await res.json();

  return (
    <section>
      {cjenik.kategorije.map((k) => (
        <div key={k.slug}>
          <h2>{k.naziv}</h2>
          {k.stavke.map((s) => (
            <div key={s.databaseId}>
              <span>{s.naziv}{s.jedinicaMjere ? ` (${s.jedinicaMjere})` : ''}</span>
              {s.akcija ? (
                <>
                  <strong>{eur(s.akcija.cijena)}</strong> {s.akcija.naziv && `(${s.akcija.naziv})`}
                  <small>Najniža cijena u zadnjih 30 dana: {eur(s.akcija.najniza30Dana)}</small>
                </>
              ) : (
                <strong>{eur(s.cijena)}</strong>
              )}
              {/* The ministry recommends labelling the anchor price with its date only. */}
              <small>Cijena na {dan(s.sidrenaDatum)}: {eur(s.sidrenaCijena)}</small>
            </div>
          ))}
        </div>
      ))}
      <div>
        {cjenik.datoteke.map((d) => (
          <div key={d.naziv}>
            {NASLOVI[d.vrsta]} (strojno čitljivo): <a href={d.csv}>CSV</a> / <a href={d.xml}>XML</a>
          </div>
        ))}
        {cjenik.arhiva.length > 0 && (
          <details>
            <summary>Arhiva cjenika</summary>
            {cjenik.arhiva.map((d) => (
              <div key={d.naziv}>
                {NASLOVI[d.vrsta]}, {new Date(d.objavljeno).toLocaleString('hr-HR')}: <a href={d.csv}>CSV</a> / <a href={d.xml}>XML</a>
              </div>
            ))}
          </details>
        )}
      </div>
    </section>
  );
}
