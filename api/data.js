// Máy chủ lưu dữ liệu cho form Tính vốn lời, chạy trên Vercel.
// Dữ liệu lưu trong Upstash Redis (thêm ở tab Storage của project trên Vercel).

// Không dùng mật khẩu: ai mở link cũng xem và sửa được dữ liệu.

const KEY = 'tinh-von-loi:entries';
const URL_ = process.env.UPSTASH_REDIS_REST_URL || process.env.KV_REST_API_URL;
const TOKEN = process.env.UPSTASH_REDIS_REST_TOKEN || process.env.KV_REST_API_TOKEN;

async function redis(cmd) {
  const r = await fetch(URL_, {
    method: 'POST',
    headers: { Authorization: 'Bearer ' + TOKEN, 'Content-Type': 'application/json' },
    body: JSON.stringify(cmd),
  });
  const j = await r.json().catch(() => ({}));
  if (!r.ok || j.error) throw new Error(j.error || ('redis ' + r.status));
  return j.result;
}

async function readAll() {
  const flat = (await redis(['HGETALL', KEY])) || [];
  const days = [];
  for (let i = 1; i < flat.length; i += 2) { try { days.push(JSON.parse(flat[i])); } catch (e) {} }
  return days;
}

const mine = (d, role) => role === 'all' || (d && d.who) === role;
const id_ = v => String(v == null ? '' : v).replace(/[^A-Za-z0-9_-]/g, '');

function clean(e, role) {
  const s = ['win', 'lost', 'idle'].includes(e.s) ? e.s : 'win';
  const date = /^\d{4}-\d{2}-\d{2}$/.test(e.date || '') ? e.date : '';
  return {
    id: id_(e.id),
    who: role === 'all' ? id_(e.who) : role, // người thường chỉ ghi được cho chính mình
    num: String(e.num || '').slice(0, 60),
    date, s,
    src: e.src === 'new' ? 'new' : 'cash',
    inv: Number(e.inv) || 0,
    dt: Number(e.dt) || 0,
    t: Number(e.t) || 0,
  };
}

module.exports = async (req, res) => {
  res.setHeader('Cache-Control', 'no-store');
  const send = (code, data) => res.status(code).json(data);
  if (req.method !== 'POST') return send(405, { error: 'chi_nhan_post' });
  if (!URL_ || !TOKEN) return send(500, { error: 'chua_noi_redis' });

  let body = req.body;
  if (typeof body === 'string') { try { body = JSON.parse(body); } catch (e) { body = {}; } }
  body = body || {};
  const role = 'all';

  const action = (req.query && req.query.action) || 'list';
  try {
    if (action === 'list') return send(200, { role, days: await readAll() });

    if (action === 'save') {
      const list = Array.isArray(body.entries) ? body.entries : [body.entry];
      const existing = {};
      (await readAll()).forEach(d => { existing[d.id] = d; });
      const args = [];
      for (const e of list) {
        if (!e || typeof e !== 'object') continue;
        const c = clean(e, role);
        if (!c.id || !c.who) continue;
        if (existing[c.id] && !mine(existing[c.id], role)) continue; // không sửa dòng của người khác
        args.push(c.id, JSON.stringify(c));
      }
      if (args.length) await redis(['HSET', KEY, ...args]);
    } else if (action === 'delete') {
      const id = id_(body.id);
      const cur = await redis(['HGET', KEY, id]);
      if (cur) {
        let d = null; try { d = JSON.parse(cur); } catch (e) {}
        if (mine(d, role)) await redis(['HDEL', KEY, id]); // chỉ xóa được dòng của mình
      }
    } else {
      return send(400, { error: 'action_khong_hop_le' });
    }
    return send(200, { ok: true, role, days: await readAll() });
  } catch (e) {
    return send(500, { error: 'loi_redis', detail: String(e.message || e) });
  }
};
