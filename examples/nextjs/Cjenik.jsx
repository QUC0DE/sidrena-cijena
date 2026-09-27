const QUERY = `query { cjenik {
  kategorije { naziv slug stavke { databaseId naziv cijena sidrenaCijena sidrenaDatum akcija { cijena naziv najniza30Dana } } }
  datoteke { naziv csv xml }
} }`;

const eur = (n) => n?.toLocaleString('hr-HR', { style: 'currency', currency: 'EUR' });
const dan = (d) => new Date(d).toLocaleDateString('hr-HR');

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
              <span>{s.naziv}</span>
              {s.akcija ? (
                <>
                  <strong>{eur(s.akcija.cijena)}</strong> ({s.akcija.naziv})
                  <small>Najniža cijena u zadnjih 30 dana: {eur(s.akcija.najniza30Dana)}</small>
                </>
              ) : (
                <strong>{eur(s.cijena)}</strong>
              )}
              <small>Cijena na {dan(s.sidrenaDatum)}: {eur(s.sidrenaCijena)}</small>
            </div>
          ))}
        </div>
      ))}
      <p>
        Strojno čitljivi cjenik:{' '}
        {cjenik.datoteke.map((d) => (
          <span key={d.naziv}><a href={d.csv}>CSV</a> / <a href={d.xml}>XML</a> </span>
        ))}
      </p>
    </section>
  );
}
