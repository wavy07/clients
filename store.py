#!/usr/bin/env python3
# visibleTech Store - logic (stdlib only). Serves index.html + JSON API, keeps orders/config stock in SQLite.
# CLI (used by install.sh menu):  store.py serve | stock | orders | confirm TOKEN | addconfigs FILE_OR_FOLDER SPEED DURATION [file] | price SPEED DURATION PRICE | set KEY VALUE | show
import os, sys, json, time, sqlite3, secrets, hashlib, threading, urllib.request
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
DEF = {'name': 'visibleTech', 'network': 'halotel', 'whatsapp': '255719554187', 'youtube': '',
       'webhook_secret': '', 'admin_user': 'admin', 'admin_path': '', 'admin_hash': '', 'pay_api_url': '', 'pay_api_key': '',
       'plans': [
        {'id': 'middle', 'cls': 'mid', 'title': 'Middle Speed', 'range': '700Kbps – 1.9MBps',
         'footer': 'Kupakua na kupakia bila kikomo. Kifaa kimoja au viwili.',
         'rows': rows(('wiki1', 'Siku 7', 'Wiki 1', 2000, 0), ('wiki2', 'Siku 14', 'Wiki 2', 4000, 1), ('mwezi1', 'Siku 30', 'Mwezi 1', 8000, 0))},
        {'id': 'normal', 'cls': 'nor', 'title': 'Normal Speed', 'range': '100Kbps – 700Kbps',
         'footer': 'Gharama nafuu kwa matumizi ya kila siku. Data bila kikomo.',
         'rows': rows(('wiki1', 'Siku 7', 'Wiki 1', 1500, 0), ('wiki2', 'Siku 14', 'Wiki 2', 3000, 0), ('mwezi1', 'Siku 30', 'Mwezi 1', 6000, 0))}]}

def flat(s): return [(p, r) for p in s['plans'] for r in p['rows']]

def load():
    try: s = json.load(open(SETF))
    except Exception: s = {}
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
      network TEXT, phone TEXT, price INT, status TEXT, config TEXT);
      CREATE TABLE IF NOT EXISTS configs(id INTEGER PRIMARY KEY, speed TEXT, duration TEXT, content TEXT UNIQUE, order_token TEXT);''')
    return c

def mkpw(pw):
    salt = secrets.token_hex(8); return salt + '$' + hashlib.pbkdf2_hmac('sha256', pw.encode(), salt.encode(), 100000).hex()
def checkpw(pw, h):
    try: salt, hx = h.split('$'); return secrets.compare_digest(hashlib.pbkdf2_hmac('sha256', pw.encode(), salt.encode(), 100000).hex(), hx)
    except Exception: return False
def clean_plans(pl):  # validate what the web panel sends
    out = []
    for p in pl[:6]:
        rs = [dict(d=str(r['d'])[:20], label=str(r['label'])[:40], small=str(r.get('small', ''))[:40], price=max(0, int(r['price'])), best=1 if r.get('best') else 0) for r in p['rows'][:12]]
        out.append(dict(id=str(p['id'])[:20], cls=p.get('cls', 'nor'), title=str(p['title'])[:40], range=str(p['range'])[:40], footer=str(p['footer'])[:160], rows=rs))
    return out

def find_row(s, speed, dur):
    for p in s['plans']:
        if p['id'] == speed:
            for r in p['rows']:
                if r['d'] == dur: return p, r
    return None, None

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

HITS, SESS, FAILS = {}, {}, {}
class H(BaseHTTPRequestHandler):
    server_version = 'vstore'
    def log_message(self, *a): pass
    def out(self, code, body, ctype='application/json', hdr=()):
        b = body if isinstance(body, bytes) else (json.dumps(body, ensure_ascii=False) if ctype == 'application/json' else body).encode()
        self.send_response(code); self.send_header('Content-Type', ctype + '; charset=utf-8'); self.send_header('Content-Length', str(len(b)))
        self.send_header('Cache-Control', 'no-store'); [self.send_header(k, v) for k, v in hdr]; self.end_headers(); self.wfile.write(b)
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
                c = db(); st = {k: s[k] for k in ('name', 'network', 'whatsapp', 'youtube', 'pay_api_url', 'pay_api_key', 'webhook_secret', 'admin_user')}
                stock = [dict(speed=r[0], d=r[1], free=r[2], total=r[3]) for r in c.execute('SELECT speed,duration,SUM(order_token IS NULL),COUNT(*) FROM configs GROUP BY 1,2')]
                orders = [dict(token=r[0], t=time.strftime('%m-%d %H:%M', time.localtime(r[1])), package=r[2], phone=r[3], price=r[4], status=r[5]) for r in c.execute('SELECT token,created,package,phone,price,status FROM orders ORDER BY created DESC LIMIT 100')]
                return self.out(200, dict(version=2, settings=st, plans=s['plans'], stock=stock, orders=orders))
            if sub == '/api/save' and post:
                for k in ('name', 'network', 'whatsapp', 'youtube', 'pay_api_url', 'pay_api_key'):
                    if k in f.get('settings', {}): s[k] = str(f['settings'][k]).strip()[:300]
                if 'plans' in f: s['plans'] = clean_plans(f['plans'])
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
            if sub == '/api/confirm' and post: return self.out(200, {'result': deliver(f.get('token', ''))})
            if sub == '/api/password' and post:
                if len(f.get('password', '')) < 8: return self.out(400, {'error': 'Min 8 characters'})
                s['admin_hash'] = mkpw(f['password']); save(s); SESS.clear(); return self.out(200, {'ok': True})
        except Exception as e: return self.out(400, {'error': 'Bad request: %s' % e})
        self.out(404, {'error': 'not found'})
    def do_GET(self):
        s, p = load(), self.path.split('?')[0]
        if p == '/' + s['admin_path'] or p.startswith('/' + s['admin_path'] + '/'): return self.admin(s, p[len(s['admin_path']) + 1:], {}, False)
        if p in ('/', '/index.html'): return self.out(200, open(HTML, 'rb').read(), 'text/html')
        if p == '/api/site': return self.out(200, {k: s[k] for k in ('name', 'network', 'whatsapp', 'youtube', 'plans')})
        if p.startswith('/api/order/status/'):
            o = db().execute('SELECT status,config FROM orders WHERE token=?', (p.rsplit('/', 1)[1],)).fetchone()
            if not o: return self.out(404, {'payment_status': 'failed'})
            return self.out(200, {'payment_status': o['status'], 'config': o['config'] or ''})
        if p.startswith('/r/'):
            o = db().execute('SELECT status,config,package FROM orders WHERE token=?', (p[3:],)).fetchone()
            cfg = (o['config'] if o and o['status'] == 'success' else '') or ''
            msg = ('<pre style="white-space:pre-wrap;word-break:break-all;background:#111A2E;padding:16px;border-radius:12px">%s</pre>' % cfg.replace('<', '&lt;')) if cfg else '<p>Config bado haijapatikana (hali: %s).</p>' % (o['status'] if o else 'haipo')
            return self.out(200, '<meta name=viewport content="width=device-width,initial-scale=1"><body style="background:#0A0F1C;color:#E8EEF8;font-family:sans-serif;max-width:640px;margin:30px auto;padding:0 16px"><h2>%s</h2>%s' % (s['name'], msg), 'text/html')
        self.out(404, {'error': 'not found'})
    def do_POST(self):
        s, p = load(), self.path.split('?')[0]
        f = self.form(ADMIN_MAX if p.startswith('/' + s['admin_path'] + '/') else 20000)
        if p.startswith('/' + s['admin_path'] + '/'): return self.admin(s, p[len(s['admin_path']) + 1:], f, True)
        if p == '/api/buy-direct':
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
                c.execute('INSERT INTO orders VALUES(?,?,?,?,?,?,?,?,?,NULL)', (token, int(now), '%s — %s' % (plan['title'], r['label']), plan['id'], r['d'], s['network'], phone, price, 'pending'))
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
        for r in db().execute('SELECT speed,duration,SUM(order_token IS NULL) free,COUNT(*) total FROM configs GROUP BY 1,2'): print('%-8s %-8s free=%s / %s' % (r[0], r[1], r[2], r[3]))
    elif a[0] == 'orders':
        for r in db().execute('SELECT token,created,package,phone,price,status FROM orders ORDER BY created DESC LIMIT 25'):
            print('%s  %s  %-26s %-11s %6s  %s' % (r[0], time.strftime('%m-%d %H:%M', time.localtime(r[1])), r[2], r[3], r[4], r[5]))
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
        for i, (p, r) in enumerate(flat(s), 1): print('%2d) %-14s | %-10s %-10s | %7s%s' % (i, p['title'], r['label'], r['small'], r['price'], '  [recommended]' if r['best'] else ''))
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
        p = s['plans'][int(a[1]) - 1]
        if a[2] not in ('title', 'range', 'footer'): sys.exit('field: title|range|footer')
        p[a[2]] = a[3]; save(s); print('ok')
    elif a[0] == 'admin':  # admin [reset] -> prints web panel path/user (and a NEW password when created/reset)
        if not s['admin_hash'] or a[1:2] == ['reset']:
            pw = secrets.token_urlsafe(9); s['admin_hash'] = mkpw(pw); save(s); print('NEWPASS', pw)
        print('PATH', s['admin_path']); print('USER', s['admin_user'])
    elif a[0] == 'set':
        if a[1] not in ('name', 'network', 'whatsapp', 'youtube', 'pay_api_url', 'pay_api_key'): sys.exit('unknown key')
        s[a[1]] = a[2]; save(s); print('ok')
    elif a[0] == 'show': print(json.dumps({k: v for k, v in s.items() if k not in ('plans', 'admin_hash')}, indent=1)); [print(p['id'], [(r['d'], r['price']) for r in p['rows']]) for p in s['plans']]
if __name__ == '__main__': cli(sys.argv[1:] or ['serve'])
