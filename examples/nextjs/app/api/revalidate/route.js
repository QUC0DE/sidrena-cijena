import { revalidateTag } from 'next/cache';

export async function POST(req) {
  const secret = new URL(req.url).searchParams.get('secret');
  if (secret !== process.env.REVALIDATE_SECRET) {
    return Response.json({ ok: false }, { status: 401 });
  }
  revalidateTag('cjenik');
  return Response.json({ ok: true });
}
