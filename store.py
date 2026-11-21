#!/usr/bin/env python3
# visibleTech Store - logic (stdlib only). Serves index.html + JSON API, keeps orders/config stock in SQLite.
# CLI (used by install.sh menu):  store.py serve | stock | orders | confirm TOKEN | addconfigs FILE_OR_FOLDER SPEED DURATION [file] | price SPEED DURATION PRICE | set KEY VALUE | show
import os, sys, json, time, sqlite3, secrets, hashlib, threading, urllib.request, urllib.parse, re
from http.server import ThreadingHTTPServer, BaseHTTPRequestHandler
D = os.environ.get('STORE_DIR', '/etc/visibleTech-store')
DBF, SETF, HTML = D + '/store.db', D + '/settings.json', os.environ.get('STORE_HTML', D + '/index.html')
ADMIN_HTML = os.environ.get('STORE_ADMIN_HTML', D + '/admin.html')
PORT = int(os.environ.get('STORE_PORT', '80'))
BIND = os.environ.get('STORE_BIND', '0.0.0.0')  # 127.0.0.1 when nginx (domain + HTTPS) sits in front
LOCK = threading.Lock()
ADMIN_MAX = 20 * 1024 * 1024  # max size of ONE admin request (the panel sends ~700KB batches, any number of batches)
def rows(*a):  # (duration id, label, small, price, best)
    return [dict(d=x[0], label=x[1], small=x[2], price=x[3], best=x[4]) for x in a]
DEF = {'name': 'visibleTech', 'whatsapp': '255719554187', 'youtube': '',
       'webhook_secret': '', 'login_required': '0', 'admin_user': 'admin', 'admin_path': '', 'admin_hash': '', 'pay_api_url': '', 'pay_api_key': '',
       'networks': [{'id': 'halotel', 'name': 'Halotel', 'plans': [
        {'id': 'middle', 'cls': 'mid', 'title': 'Middle Speed', 'range': '700Kbps – 1.9MBps',
         'footer': 'Kupakua na kupakia bila kikomo. Kifaa kimoja au viwili.',
         'rows': rows(('wiki1', 'Siku 7', 'Wiki 1', 2000, 0), ('wiki2', 'Siku 14', 'Wiki 2', 4000, 1), ('mwezi1', 'Siku 30', 'Mwezi 1', 8000, 0))},
        {'id': 'normal', 'cls': 'nor', 'title': 'Normal Speed', 'range': '100Kbps – 700Kbps',
         'footer': 'Gharama nafuu kwa matumizi ya kila siku. Data bila kikomo.',
         'rows': rows(('wiki1', 'Siku 7', 'Wiki 1', 1500, 0), ('wiki2', 'Siku 14', 'Wiki 2', 3000, 0), ('mwezi1', 'Siku 30', 'Mwezi 1', 6000, 0))}]}]}

def allplans(s): return [(n, p) for n in s['networks'] for p in n['plans']]  # every speed plan with its network
def flat(s): return [(p, r) for n, p in allplans(s) for r in p['rows']]

def load():
    try: s = json.load(open(SETF))
    except Exception: s = {}
    if 'networks' not in s and 'plans' in s:  # old single-network settings -> new multi-network layout (stock keeps working: same plan ids)
        s['networks'] = [dict(id='halotel', name=str(s.get('network') or 'Halotel').strip().title(), plans=s.pop('plans'))]
    for k, v in DEF.items(): s.setdefault(k, v)
    if not s['webhook_secret'] or not s['admin_path']:
        s['webhook_secret'] = s['webhook_secret'] or secrets.token_urlsafe(18); s['admin_path'] = s['admin_path'] or 'a_' + secrets.token_hex(6); save(s)
    return s
def save(s):
    os.makedirs(D, exist_ok=True); tmp = SETF + '.tmp'
    json.dump(s, open(tmp, 'w'), ensure_ascii=False, indent=1); os.chmod(tmp, 0o600); os.replace(tmp, SETF)
def db():
    os.makedirs(D, exist_ok=True); c = sqlite3.connect(DBF, timeout=20); c.row_factory = sqlite3.Row
    c.executescript('''CREATE TABLE IF NOT EXISTS orders(token TEXT PRIMARY KEY, created INT, package TEXT, speed TEXT, duration TEXT,
      network TEXT, phone TEXT, price INT, status TEXT, config TEXT, user_id INT);
      CREATE TABLE IF NOT EXISTS configs(id INTEGER PRIMARY KEY, speed TEXT, duration TEXT, content TEXT UNIQUE, order_token TEXT);
      CREATE TABLE IF NOT EXISTS users(id INTEGER PRIMARY KEY, email TEXT UNIQUE, phone TEXT UNIQUE, name TEXT, google_sub TEXT, created INT, last_login INT);
      CREATE TABLE IF NOT EXISTS sessions(token TEXT PRIMARY KEY, user_id INT, expires INT);''')
    for alt in ('ALTER TABLE orders ADD COLUMN user_id INT', 'ALTER TABLE users ADD COLUMN pw_hash TEXT'):  # old databases
        try: c.execute(alt)
        except sqlite3.OperationalError: pass
    return c

def mkpw(pw):
    salt = secrets.token_hex(8); return salt + '$' + hashlib.pbkdf2_hmac('sha256', pw.encode(), salt.encode(), 100000).hex()
def checkpw(pw, h):
    try: salt, hx = h.split('$'); return secrets.compare_digest(hashlib.pbkdf2_hmac('sha256', pw.encode(), salt.encode(), 100000).hex(), hx)
    except Exception: return False
def clean_networks(nl):  # validate what the web panel sends: any number of networks, each with its own speed plans and packages
    out, seen = [], set()
    for n in nl[:20]:
        plans = []
        for p in n.get('plans', [])[:12]:
            pid = str(p.get('id', ''))[:20].strip()
            if not pid or pid in seen: pid = 'p' + secrets.token_hex(3)
            seen.add(pid); rs, rseen = [], set()
            for r in p.get('rows', [])[:30]:
                d = str(r.get('d', ''))[:20].strip()
                if not d or d in rseen: d = 'd' + secrets.token_hex(2)
                rseen.add(d)
                rs.append(dict(d=d, label=str(r.get('label', ''))[:40], small=str(r.get('small', ''))[:40], price=max(0, int(r.get('price') or 0)), best=1 if r.get('best') else 0))
            plans.append(dict(id=pid, cls=p.get('cls') if p.get('cls') in ('mid', 'nor', 'gld', 'grn') else 'nor', title=str(p.get('title', ''))[:40],
                              range=str(p.get('range', ''))[:40], footer=str(p.get('footer', ''))[:160], rows=rs))
        nid = str(n.get('id', ''))[:20].strip() or 'n' + secrets.token_hex(3)
        out.append(dict(id=nid, name=str(n.get('name', ''))[:40] or 'Network', plans=plans))
    return out

def find_row(s, speed, dur):
    for p in (p for n, p in allplans(s)):
        if p['id'] == speed:
            for r in p['rows']:
                if r['d'] == dur: return p, r
    return None, None
def net_of(s, plan): return next((n['name'] for n, p in allplans(s) if p is plan), '')

def deliver(token):  # paid -> hand out one unused config (atomic); returns status
    with LOCK, db() as c:
        o = c.execute('SELECT * FROM orders WHERE token=?', (token,)).fetchone()
        if not o: return 'failed'
        if o['status'] == 'success': return 'success'
        k = c.execute('SELECT id,content FROM configs WHERE speed=? AND duration=? AND order_token IS NULL ORDER BY id LIMIT 1', (o['speed'], o['duration'])).fetchone()
        if not k: c.execute("UPDATE orders SET status='no_stock' WHERE token=?", (token,)); return 'no_stock'
        c.execute('UPDATE configs SET order_token=? WHERE id=?', (token, k['id']))
        c.execute("UPDATE orders SET status='success',config=? WHERE token=?", (k['content'], token))
        return 'success'

def pay_request(s, token, o, host):  # EDIT HERE for your payment provider (ClickPesa / ZenoPay / Selcom ...)
    if not s['pay_api_url']: return True  # no provider: order stays pending until webhook / manual confirm
    body = json.dumps({'order_id': token, 'amount': o['price'], 'phone': o['phone'], 'network': o['network'],
                       'callback_url': '%s://%s/api/webhook/%s' % ('https' if BIND == '127.0.0.1' else 'http', host, s['webhook_secret'])}).encode()
    rq = urllib.request.Request(s['pay_api_url'], body, {'Content-Type': 'application/json', 'Authorization': 'Bearer ' + s['pay_api_key']})
    try: urllib.request.urlopen(rq, timeout=20).read(); return True
    except Exception: return False


# ---------------- customer accounts: email + phone + password (stored on this VPS, password only as a salted hash) ----------------
EMAIL_RE = re.compile(r'^[^@\s]{1,64}@[^@\s]{1,200}\.[^@\s.]{2,}$')
REGIP, LOGIP, LOGT = {}, {}, {}
def norm_email(t):
    t = str(t or '').strip().lower(); return t if len(t) <= 254 and EMAIL_RE.match(t) else None
def norm_phone(t):  # 0712345678 / +255712345678 -> 255712345678
    d = ''.join(ch for ch in str(t or '') if ch.isdigit())
    if d.startswith('00'): d = d[2:]
    if d.startswith('0') and len(d) == 10: d = '255' + d[1:]
    elif len(d) == 9: d = '255' + d
    return d if 11 <= len(d) <= 15 and not d.startswith('0') else None
def pub(u): return dict(name=u['name'] or '', email=u['email'] or '', phone=u['phone'] or '')
def login_needed(s): return True  # login is ALWAYS required: nothing (page content, packages, orders, configs) is served to anonymous visitors
LOCK_STUB = '<button id="acct-btn" hidden></button><h1 id="hero-title" hidden></h1><h2 id="pk-title" hidden></h2><div id="nets" hidden></div><div id="plans" hidden></div>'
def gate_page(): # index.html WITHOUT the site content: only the login/sign-up modal + scripts are sent to visitors who are not logged in
    h = open(HTML, encoding='utf-8').read(); a, b = h.find('<body>'), h.find('<!-- Payment modal -->')
    return (h[:a + 6] + LOCK_STUB + h[b:]).encode() if 0 < a < b else h.encode()

HITS, SESS, FAILS = {}, {}, {}
class H(BaseHTTPRequestHandler):
    server_version = 'vstore'
    def log_message(self, *a): pass
    def out(self, code, body, ctype='application/json', hdr=()):
        b = body if isinstance(body, bytes) else (json.dumps(body, ensure_ascii=False) if ctype == 'application/json' else body).encode()
        self.send_response(code); self.send_header('Content-Type', ctype + '; charset=utf-8'); self.send_header('Content-Length', str(len(b)))
        self.send_header('Cache-Control', 'no-store'); [self.send_header(k, v) for k, v in hdr]; self.end_headers(); self.wfile.write(b)
    def ip(self): return (self.headers.get('X-Real-IP') if BIND == '127.0.0.1' else None) or self.client_address[0]
    def me(self):  # logged-in customer (row) or None
        ck = dict(x.strip().split('=', 1) for x in (self.headers.get('Cookie') or '').split(';') if '=' in x)
        if not ck.get('vs_user'): return None
        with db() as c: return c.execute('SELECT u.* FROM sessions s JOIN users u ON u.id=s.user_id WHERE s.token=? AND s.expires>?', (ck['vs_user'], int(time.time()))).fetchone()
    def login_ok(self, c, u):  # create session + cookie
        now = int(time.time()); tok = secrets.token_urlsafe(32)
        c.execute('DELETE FROM sessions WHERE expires<?', (now,)); c.execute('INSERT INTO sessions VALUES(?,?,?)', (tok, u['id'], now + 2592000))
        return self.out(200, {'ok': True, 'user': pub(u)}, hdr=[('Set-Cookie', 'vs_user=%s; Path=/; HttpOnly; SameSite=Lax; Max-Age=2592000%s' % (tok, '; Secure' if BIND == '127.0.0.1' else ''))])
    def auth(self, s, p, f):  # all /api/auth/* endpoints
        now, ip = time.time(), self.ip()
        if p == '/api/auth/register':
            REGIP[ip] = [t for t in REGIP.get(ip, []) if now - t < 3600]
            if len(REGIP[ip]) >= 10: return self.out(429, {'error': 'Maombi mengi. Jaribu tena baadaye.'})
            email, phone, pw, name = norm_email(f.get('email')), norm_phone(f.get('phone')), str(f.get('password', '')), str(f.get('name', '')).strip()[:60]
            if not email: return self.out(400, {'error': 'Barua pepe si sahihi.'})
            if not phone: return self.out(400, {'error': 'Namba ya simu si sahihi.'})
            if len(pw) < 6: return self.out(400, {'error': 'Nenosiri liwe na angalau herufi 6.'})
            REGIP[ip].append(now)
            with LOCK, db() as c:
                if c.execute('SELECT 1 FROM users WHERE email=? OR phone=?', (email, phone)).fetchone(): return self.out(409, {'error': 'Barua pepe au namba hii tayari ina akaunti. Ingia badala yake.'})
                cur = c.execute('INSERT INTO users(email,phone,name,created,last_login,pw_hash) VALUES(?,?,?,?,?,?)', (email, phone, name, int(now), int(now), mkpw(pw)))
                return self.login_ok(c, c.execute('SELECT * FROM users WHERE id=?', (cur.lastrowid,)).fetchone())
        if p == '/api/auth/login':
            who = str(f.get('login', '')).strip(); key = norm_email(who) or norm_phone(who) or who[:60].lower()
            LOGIP[ip] = [t for t in LOGIP.get(ip, []) if now - t < 600]; LOGT[key] = [t for t in LOGT.get(key, []) if now - t < 3600]
            if len(LOGIP) > 5000: LOGIP.clear(); LOGT.clear()
            if len(LOGIP[ip]) >= 8 or len(LOGT[key]) >= 10: return self.out(429, {'error': 'Umejaribu mara nyingi. Subiri dakika chache.'})
            with LOCK, db() as c:
                u = c.execute('SELECT * FROM users WHERE email=? OR phone=?', (norm_email(who) or '-', norm_phone(who) or '-')).fetchone()
                if not u or not u['pw_hash'] or not checkpw(str(f.get('password', '')), u['pw_hash']):
                    LOGIP[ip].append(now); LOGT[key].append(now); return self.out(403, {'error': 'Barua pepe/namba au nenosiri si sahihi.'})
                c.execute('UPDATE users SET last_login=? WHERE id=?', (int(now), u['id']))
                return self.login_ok(c, u)
        if p == '/api/auth/password':  # logged-in customer changes own password
            u = self.me()
            if not u: return self.out(401, {'error': 'login'})
            if len(str(f.get('new', ''))) < 6: return self.out(400, {'error': 'Nenosiri jipya liwe na angalau herufi 6.'})
            if not u['pw_hash'] or not checkpw(str(f.get('old', '')), u['pw_hash']): return self.out(403, {'error': 'Nenosiri la sasa si sahihi.'})
            with LOCK, db() as c: c.execute('UPDATE users SET pw_hash=? WHERE id=?', (mkpw(str(f['new'])), u['id']))
            return self.out(200, {'ok': True})
        if p == '/api/auth/logout':
            ck = dict(x.strip().split('=', 1) for x in (self.headers.get('Cookie') or '').split(';') if '=' in x)
            with LOCK, db() as c: c.execute('DELETE FROM sessions WHERE token=?', (ck.get('vs_user', ''),))
            return self.out(200, {'ok': True}, hdr=[('Set-Cookie', 'vs_user=; Path=/; Max-Age=0')])
        self.out(404, {'error': 'not found'})
    def form(self, limit=20000):  # public endpoints stay tiny; the logged-in admin may upload big batches of configs
        raw = self.rfile.read(min(int(self.headers.get('Content-Length') or 0), limit)).decode('utf-8', 'ignore')
        try: return json.loads(raw) if raw.lstrip().startswith('{') else {k: v[0] for k, v in __import__('urllib.parse').parse.parse_qs(raw).items()}
        except Exception: return {}
    def admin(self, s, sub, f, post):
        now = time.time(); ip = (self.headers.get('X-Real-IP') if BIND == '127.0.0.1' else None) or self.client_address[0]
        if sub in ('', '/') and not post: return self.out(200, open(ADMIN_HTML, 'rb').read(), 'text/html')
        if sub == '/api/login' and post:
            FAILS[ip] = [t for t in FAILS.get(ip, []) if now - t < 600]
            if len(FAILS[ip]) >= 5: return self.out(429, {'error': 'Too many attempts, wait 10 minutes'})
            if f.get('user') == s['admin_user'] and checkpw(f.get('password', ''), s['admin_hash']):
                tok = secrets.token_urlsafe(24); SESS[tok] = now + 43200
                return self.out(200, {'ok': True}, hdr=[('Set-Cookie', 'vs_admin=%s; Path=/%s; HttpOnly; SameSite=Strict; Max-Age=43200%s' % (tok, s['admin_path'], '; Secure' if BIND == '127.0.0.1' else ''))])
            FAILS[ip].append(now); return self.out(403, {'error': 'Wrong user or password'})
        ck = dict(x.strip().split('=', 1) for x in (self.headers.get('Cookie') or '').split(';') if '=' in x)
        if SESS.get(ck.get('vs_admin'), 0) < now: return self.out(401, {'error': 'login'})
        try:
            if sub == '/api/state':
                c = db(); st = {k: s[k] for k in ('name', 'whatsapp', 'youtube', 'pay_api_url', 'pay_api_key', 'webhook_secret', 'admin_user', 'login_required')}
                stock = [dict(speed=r[0], d=r[1], free=r[2], total=r[3]) for r in c.execute('SELECT speed,duration,SUM(order_token IS NULL),COUNT(*) FROM configs GROUP BY 1,2')]
                orders = [dict(token=r[0], t=time.strftime('%m-%d %H:%M', time.localtime(r[1])), package=r[2], phone=r[3], price=r[4], status=r[5]) for r in c.execute('SELECT token,created,package,phone,price,status FROM orders ORDER BY created DESC LIMIT 100')]
                return self.out(200, dict(version=5, settings=st, networks=s['networks'], stock=stock, orders=orders, users=[dict(id=r[5], email=r[0] or '', phone=r[1] or '', name=r[2] or '', t=time.strftime('%m-%d %H:%M', time.localtime(r[3])), orders=r[4]) for r in c.execute('SELECT u.email,u.phone,u.name,u.created,(SELECT COUNT(*) FROM orders o WHERE o.user_id=u.id),u.id FROM users u ORDER BY u.created DESC LIMIT 200')], user_total=c.execute('SELECT COUNT(*) FROM users').fetchone()[0]))
            if sub == '/api/save' and post:
                for k in ('name', 'whatsapp', 'youtube', 'pay_api_url', 'pay_api_key', 'login_required'):
                    if k in f.get('settings', {}): s[k] = str(f['settings'][k]).strip()[:300]
                if 'networks' in f: s['networks'] = clean_networks(f['networks'])
                save(s); return self.out(200, {'ok': True})
            if sub == '/api/configs' and post:
                # 'items' = list of configs (one per uploaded file, any length / multi-line); 'text' = one config per line. No limit on count or length.
                items = [str(x).strip() for x in f['items']] if isinstance(f.get('items'), list) else [l.strip() for l in str(f.get('text', '')).splitlines()]
                items = [x for x in items if x]; n = 0
                with LOCK, db() as c:
                    for x in items: n += c.execute('INSERT OR IGNORE INTO configs(speed,duration,content) VALUES(?,?,?)', (f['speed'], f['duration'], x)).rowcount
                return self.out(200, {'added': n, 'duplicates': len(items) - n})
            if sub == '/api/clearstock' and post:
                with LOCK, db() as c: c.execute('DELETE FROM configs WHERE speed=? AND duration=? AND order_token IS NULL', (f['speed'], f['duration']))
                return self.out(200, {'ok': True})
            if sub == '/api/user/password' and post:  # customers can't reset by email, so the admin sets a new password for them
                if len(str(f.get('password', ''))) < 6: return self.out(400, {'error': 'Min 6 characters'})
                with LOCK, db() as c: c.execute('UPDATE users SET pw_hash=? WHERE id=?', (mkpw(str(f['password'])), int(f['id']))); c.execute('DELETE FROM sessions WHERE user_id=?', (int(f['id']),))
                return self.out(200, {'ok': True})
            if sub == '/api/user/delete' and post:
                with LOCK, db() as c: c.execute('DELETE FROM sessions WHERE user_id=?', (int(f['id']),)); c.execute('DELETE FROM users WHERE id=?', (int(f['id']),))
                return self.out(200, {'ok': True})
            if sub == '/api/confirm' and post: return self.out(200, {'result': deliver(f.get('token', ''))})
            if sub == '/api/password' and post:
                if len(f.get('password', '')) < 8: return self.out(400, {'error': 'Min 8 characters'})
                s['admin_hash'] = mkpw(f['password']); save(s); SESS.clear(); return self.out(200, {'ok': True})
        except Exception as e: return self.out(400, {'error': 'Bad request: %s' % e})
        self.out(404, {'error': 'not found'})
    def do_GET(self):
        s, p = load(), self.path.split('?')[0]
        if p == '/' + s['admin_path'] or p.startswith('/' + s['admin_path'] + '/'): return self.admin(s, p[len(s['admin_path']) + 1:], {}, False)
        if p in ('/', '/index.html'): return self.out(200, open(HTML, 'rb').read() if self.me() else gate_page(), 'text/html')
        if p == '/api/auth/config': return self.out(200, {'required': login_needed(s)})
        if p == '/api/me':
            u = self.me(); return self.out(200, {'user': pub(u) if u else None})
        if p == '/api/my-orders':
            u = self.me()
            if not u: return self.out(401, {'error': 'login'})
            return self.out(200, {'orders': [dict(token=r[0], t=time.strftime('%Y-%m-%d %H:%M', time.localtime(r[1])), package=r[2], price=r[3], status=r[4]) for r in db().execute('SELECT token,created,package,price,status FROM orders WHERE user_id=? ORDER BY created DESC LIMIT 50', (u['id'],))]})
        if p == '/api/site':
            if not self.me(): return self.out(401, {'error': 'login'})
            return self.out(200, {k: s[k] for k in ('name', 'whatsapp', 'youtube', 'networks')})
        if p.startswith('/api/order/status/'):
            u = self.me()
            if not u: return self.out(401, {'error': 'login'})
            o = db().execute('SELECT status,config,user_id FROM orders WHERE token=?', (p.rsplit('/', 1)[1],)).fetchone()
            if not o or o['user_id'] not in (None, u['id']): return self.out(404, {'payment_status': 'failed'})
            return self.out(200, {'payment_status': o['status'], 'config': o['config'] or ''})
        if p.startswith('/r/'):
            u = self.me()
            if not u: return self.out(302, b'', 'text/html', hdr=[('Location', '/')])
            o = db().execute('SELECT status,config,package,user_id FROM orders WHERE token=?', (p[3:],)).fetchone()
            if o and o['user_id'] not in (None, u['id']): o = None  # someone else's order
            cfg = (o['config'] if o and o['status'] == 'success' else '') or ''
            msg = ('<pre style="white-space:pre-wrap;word-break:break-all;background:#111A2E;padding:16px;border-radius:12px">%s</pre>' % cfg.replace('<', '&lt;')) if cfg else '<p>Config bado haijapatikana (hali: %s).</p>' % (o['status'] if o else 'haipo')
            return self.out(200, '<meta name=viewport content="width=device-width,initial-scale=1"><body style="background:#0A0F1C;color:#E8EEF8;font-family:sans-serif;max-width:640px;margin:30px auto;padding:0 16px"><h2>%s</h2>%s' % (s['name'], msg), 'text/html')
        self.out(404, {'error': 'not found'})
    def do_POST(self):
        s, p = load(), self.path.split('?')[0]
        f = self.form(ADMIN_MAX if p.startswith('/' + s['admin_path'] + '/') else 20000)
        if p.startswith('/' + s['admin_path'] + '/'): return self.admin(s, p[len(s['admin_path']) + 1:], f, True)
        if p.startswith('/api/auth/'): return self.auth(s, p, f)
        if p == '/api/buy-direct':
            me = self.me()
            if not me: return self.out(401, {'status': 'error', 'message': 'Ingia kwanza ili kuendelea.', 'login': True})
            ip, now = (self.headers.get('X-Real-IP') if BIND == '127.0.0.1' else None) or self.client_address[0], time.time()
            HITS[ip] = [t for t in HITS.get(ip, []) if now - t < 600] + [now]
            if len(HITS[ip]) > 10: return self.out(429, {'status': 'error', 'message': 'Maombi mengi. Subiri dakika chache.'})
            plan, r = find_row(s, f.get('speed_type', ''), f.get('duration', ''))
            if not r: return self.out(400, {'status': 'error', 'message': 'Kifurushi hakipo.'})
            phone = ''.join(ch for ch in f.get('phone', '') if ch.isdigit())
            price = int(r['price'])  # price always comes from the server table, never from the browser
            if price and (len(phone) != 10 or not phone.startswith('0') or phone[:3] in ('074', '075', '076')):
                return self.out(400, {'status': 'error', 'message': 'Namba ya simu si sahihi.'})
            token = secrets.token_urlsafe(16)
            with LOCK, db() as c:
                c.execute('INSERT INTO orders(token,created,package,speed,duration,network,phone,price,status,user_id) VALUES(?,?,?,?,?,?,?,?,?,?)', (token, int(now), '%s — %s — %s' % (net_of(s, plan), plan['title'], r['label']), plan['id'], r['d'], net_of(s, plan), phone, price, 'pending', me['id'] if me else None))
            if not price:
                st = deliver(token)
                cfg = db().execute('SELECT config FROM orders WHERE token=?', (token,)).fetchone()['config']
                if st != 'success': return self.out(200, {'status': 'error', 'message': 'Config zimeisha kwa sasa.'})
                return self.out(200, {'status': 'success', 'free': True, 'config': cfg, 'redirect': '/r/' + token})
            o = db().execute('SELECT * FROM orders WHERE token=?', (token,)).fetchone()
            if not pay_request(s, token, o, self.headers.get('Host', '')):
                with LOCK, db() as c: c.execute("UPDATE orders SET status='failed' WHERE token=?", (token,))
                return self.out(200, {'status': 'error', 'message': 'Imeshindwa kutuma ombi la malipo.'})
            return self.out(200, {'status': 'success', 'free': False, 'access_token': token, 'redirect': '/r/' + token})
        if p == '/api/webhook/' + s['webhook_secret']:  # provider -> {order_id, status: success|failed}
            tok, st = f.get('order_id', ''), str(f.get('status', '')).lower()
            if st in ('success', 'completed', 'paid'): deliver(tok)
            elif st in ('failed', 'cancelled'):
                with LOCK, db() as c: c.execute("UPDATE orders SET status='failed' WHERE token=? AND status='pending'", (tok,))
            return self.out(200, {'ok': True})
        self.out(404, {'error': 'not found'})

def cli(a):
    s = load()
    if a[0] == 'serve': ThreadingHTTPServer((BIND, PORT), H).serve_forever()
    elif a[0] == 'stock':
        for r in db().execute('SELECT speed,duration,SUM(order_token IS NULL) free,COUNT(*) total FROM configs GROUP BY 1,2'): print('%-10s %-10s free=%s / %s' % (r[0], r[1], r[2], r[3]))
    elif a[0] == 'orders':
        for r in db().execute('SELECT token,created,package,phone,price,status FROM orders ORDER BY created DESC LIMIT 25'):
            print('%s  %s  %-26s %-11s %6s  %s' % (r[0], time.strftime('%m-%d %H:%M', time.localtime(r[1])), r[2], r[3], r[4], r[5]))
    elif a[0] == 'users':
        for r in db().execute('SELECT id,email,phone,name,created FROM users ORDER BY created DESC'): print(r[0], r[1], r[2], r[3], time.strftime('%m-%d %H:%M', time.localtime(r[4])))
    elif a[0] == 'confirm': print(deliver(a[1]))
    elif a[0] == 'addconfigs':
        # FOLDER -> every file inside = one config | FILE + 'file' -> whole file = one config | FILE -> one config per line
        n, items = 0, []
        if os.path.isdir(a[1]):
            for fn in sorted(os.listdir(a[1])):
                fp = os.path.join(a[1], fn)
                if os.path.isfile(fp): items.append(open(fp, encoding='utf-8', errors='ignore').read())
        elif a[4:5] == ['file']: items.append(open(a[1], encoding='utf-8', errors='ignore').read())
        else: items = open(a[1], encoding='utf-8', errors='ignore').read().splitlines()
        with db() as c:
            for x in items:
                if x.strip(): n += c.execute('INSERT OR IGNORE INTO configs(speed,duration,content) VALUES(?,?,?)', (a[2], a[3], x.strip())).rowcount
        print('added', n, 'of', len([x for x in items if x.strip()]))
    elif a[0] == 'price':
        _, r = find_row(s, a[1], a[2])
        if not r: sys.exit('no such speed/duration')
        r['price'] = int(a[3]); save(s); print('ok')
    elif a[0] == 'list':  # numbered list of every package
        for i, (p, r) in enumerate(flat(s), 1): print('%2d) %-9s %-14s | %-10s %-10s | %7s%s' % (i, net_of(s, p), p['title'], r['label'], r['small'], r['price'], '  [recommended]' if r['best'] else ''))
    elif a[0] == 'ids':  # ids to type when adding configs from the menu
        for n, p in allplans(s):
            for r in p['rows']: print('%-10s speed-id=%-10s duration-id=%-10s  (%s - %s)' % (n['name'], p['id'], r['d'], p['title'], r['label']))
    elif a[0] == 'edit':  # edit N price|label|small|best VALUE
        i, f, v = int(a[1]) - 1, a[2], a[3]
        p, r = flat(s)[i]
        if f == 'price': r['price'] = int(v)
        elif f in ('label', 'small'): r[f] = v
        elif f == 'best':
            for x in p['rows']: x['best'] = 0
            r['best'] = 1 if v in ('1', 'y', 'yes') else 0
        else: sys.exit('field: price|label|small|best')
        save(s); print('ok')
    elif a[0] == 'plan':  # plan N title|range|footer VALUE   (N = plan number 1,2..)
        p = allplans(s)[int(a[1]) - 1][1]
        if a[2] not in ('title', 'range', 'footer'): sys.exit('field: title|range|footer')
        p[a[2]] = a[3]; save(s); print('ok')
    elif a[0] == 'admin':  # admin [reset] -> prints web panel path/user (and a NEW password when created/reset)
        if not s['admin_hash'] or a[1:2] == ['reset']:
            pw = secrets.token_urlsafe(9); s['admin_hash'] = mkpw(pw); save(s); print('NEWPASS', pw)
        print('PATH', s['admin_path']); print('USER', s['admin_user'])
    elif a[0] == 'set':
        if a[1] not in ('name', 'whatsapp', 'youtube', 'pay_api_url', 'pay_api_key', 'login_required'): sys.exit('unknown key')
        s[a[1]] = a[2]; save(s); print('ok')
    elif a[0] == 'show': print(json.dumps({k: v for k, v in s.items() if k not in ('networks', 'admin_hash')}, indent=1)); [print(n['name'], '/', p['id'], [(r['d'], r['price']) for r in p['rows']]) for n, p in allplans(s)]
if __name__ == '__main__': cli(sys.argv[1:] or ['serve'])
