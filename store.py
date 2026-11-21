#!/usr/bin/env python3
# visibleTech Store - logic (stdlib only). Serves index.html + JSON API, keeps orders/config stock in SQLite.
# CLI (used by install.sh menu):  store.py serve | stock | orders | confirm TOKEN | addconfigs FILE SPEED DURATION | price SPEED DURATION PRICE | set KEY VALUE | show
import os, sys, json, time, sqlite3, secrets, threading, urllib.request
from http.server import ThreadingHTTPServer, BaseHTTPRequestHandler
D = os.environ.get('STORE_DIR', '/etc/visibleTech-store')
DBF, SETF, HTML = D + '/store.db', D + '/settings.json', os.environ.get('STORE_HTML', D + '/index.html')
PORT = int(os.environ.get('STORE_PORT', '80'))
BIND = os.environ.get('STORE_BIND', '0.0.0.0')  # 127.0.0.1 when nginx (domain + HTTPS) sits in front
LOCK = threading.Lock()
def rows(*a):  # (duration id, label, small, price, best)
    return [dict(d=x[0], label=x[1], small=x[2], price=x[3], best=x[4]) for x in a]
DEF = {'name': 'visibleTech', 'network': 'halotel', 'whatsapp': '255719554187', 'youtube': '',
       'webhook_secret': '', 'pay_api_url': '', 'pay_api_key': '',
       'plans': [
        {'id': 'middle', 'cls': 'mid', 'title': 'Middle Speed', 'range': '700Kbps – 1.9MBps',
         'footer': 'Kupakua na kupakia bila kikomo. Kifaa kimoja au viwili.',
         'rows': rows(('wiki1', 'Siku 7', 'Wiki 1', 2000, 0), ('wiki2', 'Siku 14', 'Wiki 2', 4000, 1), ('mwezi1', 'Siku 30', 'Mwezi 1', 8000, 0))},
        {'id': 'normal', 'cls': 'nor', 'title': 'Normal Speed', 'range': '100Kbps – 700Kbps',
         'footer': 'Gharama nafuu kwa matumizi ya kila siku. Data bila kikomo.',
         'rows': rows(('wiki1', 'Siku 7', 'Wiki 1', 1500, 0), ('wiki2', 'Siku 14', 'Wiki 2', 3000, 0), ('mwezi1', 'Siku 30', 'Mwezi 1', 6000, 0))}]}

def load():
    try: s = json.load(open(SETF))
    except Exception: s = {}
    for k, v in DEF.items(): s.setdefault(k, v)
    if not s['webhook_secret']: s['webhook_secret'] = secrets.token_urlsafe(18); save(s)
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

HITS = {}
class H(BaseHTTPRequestHandler):
    server_version = 'vstore'
    def log_message(self, *a): pass
    def out(self, code, body, ctype='application/json'):
        b = body if isinstance(body, bytes) else (json.dumps(body, ensure_ascii=False) if ctype == 'application/json' else body).encode()
        self.send_response(code); self.send_header('Content-Type', ctype + '; charset=utf-8'); self.send_header('Content-Length', str(len(b)))
        self.send_header('Cache-Control', 'no-store'); self.end_headers(); self.wfile.write(b)
    def form(self):
        raw = self.rfile.read(min(int(self.headers.get('Content-Length') or 0), 20000)).decode('utf-8', 'ignore')
        try: return json.loads(raw) if raw.lstrip().startswith('{') else {k: v[0] for k, v in __import__('urllib.parse').parse.parse_qs(raw).items()}
        except Exception: return {}
    def do_GET(self):
        s, p = load(), self.path.split('?')[0]
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
        s, p, f = load(), self.path.split('?')[0], self.form()
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
        n = 0
        with db() as c:
            for line in open(a[1], encoding='utf-8', errors='ignore'):
                if line.strip(): n += c.execute('INSERT OR IGNORE INTO configs(speed,duration,content) VALUES(?,?,?)', (a[2], a[3], line.strip())).rowcount
        print('added', n)
    elif a[0] == 'price':
        _, r = find_row(s, a[1], a[2])
        if not r: sys.exit('no such speed/duration')
        r['price'] = int(a[3]); save(s); print('ok')
    elif a[0] == 'set':
        if a[1] not in ('name', 'network', 'whatsapp', 'youtube', 'pay_api_url', 'pay_api_key'): sys.exit('unknown key')
        s[a[1]] = a[2]; save(s); print('ok')
    elif a[0] == 'show': print(json.dumps({k: v for k, v in s.items() if k != 'plans'}, indent=1)); [print(p['id'], [(r['d'], r['price']) for r in p['rows']]) for p in s['plans']]
if __name__ == '__main__': cli(sys.argv[1:] or ['serve'])
