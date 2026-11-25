#!/usr/bin/env python3
# visibleTech Store - logic (stdlib only). Serves index.html + JSON API, keeps orders/config stock in SQLite.
# CLI (used by install.sh menu):  store.py serve | stock | orders | confirm TOKEN | addconfigs FILE_OR_FOLDER SPEED DURATION [file] | price SPEED DURATION PRICE | set KEY VALUE | show
import shutil, subprocess, ssl, hmac, os, sys, json, time, calendar, sqlite3, secrets, hashlib, threading, urllib.request, urllib.parse, re, base64
from http.server import ThreadingHTTPServer, BaseHTTPRequestHandler
D = os.environ.get('STORE_DIR', '/etc/visibleTech-store')
DBF, SETF, HTML = D + '/store.db', D + '/settings.json', os.environ.get('STORE_HTML', D + '/index.html')
ADMIN_HTML = os.environ.get('STORE_ADMIN_HTML', D + '/admin.html')
PORT = int(os.environ.get('STORE_PORT', '80'))
BIND = os.environ.get('STORE_BIND', '0.0.0.0')  # 127.0.0.1 when nginx (domain + HTTPS) sits in front
LOCK = threading.Lock()
HTTPS = BIND == '127.0.0.1'  # nginx + HTTPS in front
CSP = ("default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com https://cdnjs.cloudflare.com; "
       "font-src 'self' https://fonts.gstatic.com https://cdnjs.cloudflare.com; img-src 'self' data: blob:; connect-src 'self'; manifest-src 'self'; worker-src 'self'; "
       "object-src 'none'; base-uri 'none'; form-action 'self'; frame-ancestors 'none'")
SEC_HDR = [('Content-Security-Policy', CSP), ('X-Content-Type-Options', 'nosniff'), ('X-Frame-Options', 'DENY'), ('Referrer-Policy', 'same-origin'),
           ('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=()'), ('Cross-Origin-Opener-Policy', 'same-origin')]
if HTTPS: SEC_HDR.append(('Strict-Transport-Security', 'max-age=31536000'))
def eq(a, b): return hmac.compare_digest(str(a).encode('utf-8', 'ignore'), str(b).encode('utf-8', 'ignore'))  # constant-time compare
def hesc(t): return str(t).replace('&', '&amp;').replace('<', '&lt;').replace('>', '&gt;').replace('"', '&quot;').replace("'", '&#39;')
def tok_hash(t): return hashlib.sha256(str(t).encode()).hexdigest()  # session tokens are stored only as hashes
def pw_err(pw): return None if 8 <= len(pw) <= 200 else 'Nenosiri liwe na herufi 8 hadi 200.'
def site_host(s, hdr):  # host used for payment callbacks: NEVER blindly trust the Host header (Host-header injection would leak the webhook secret)
    h = str(s.get('pay_site') or '').strip()
    if not h:
        try: h = open(D + '/domain').read().strip()
        except Exception: h = ''
    if not h:
        h = str(hdr or '')[:100]
        if not re.fullmatch(r'[A-Za-z0-9.-]+(:\d{1,5})?', h): h = ''
    return h
ADMIN_MAX = 20 * 1024 * 1024  # max size of ONE admin request (the panel sends ~700KB batches, any number of batches)
def rows(*a):  # (duration id, label, small, price, best)
    return [dict(d=x[0], label=x[1], small=x[2], price=x[3], best=x[4]) for x in a]
DEF = {'name': 'visibleTech', 'whatsapp': '255719554187', 'youtube': '',
       'webhook_secret': '', 'login_required': '0', 'admin_user': 'admin', 'admin_path': '', 'admin_hash': '', 'pay_api_url': '', 'pay_api_key': '', 'pay_secret': '', 'pay_site': '',
       'networks': [{'id': 'halotel', 'name': 'Halotel', 'plans': [
        {'id': 'middle', 'cls': 'mid', 'title': 'Middle Speed', 'range': '700Kbps – 1.9MBps',
         'footer': 'Unlimited downloads and uploads. One or two devices.',
         'rows': rows(('wiki1', '7 Days', '1 Week', 2000, 0), ('wiki2', '14 Days', '2 Weeks', 4000, 1), ('mwezi1', '30 Days', '1 Month', 8000, 0))},
        {'id': 'normal', 'cls': 'nor', 'title': 'Normal Speed', 'range': '100Kbps – 700Kbps',
         'footer': 'Affordable for everyday use. Unlimited data.',
         'rows': rows(('wiki1', '7 Days', '1 Week', 1500, 0), ('wiki2', '14 Days', '2 Weeks', 3000, 0), ('mwezi1', '30 Days', '1 Month', 6000, 0))}]}]}

DEF['premium'] = {'on': 1, 'title': 'Premium', 'perks': ['Vifurushi na ofa za kipekee kwa Premium pekee', 'Config za kasi ya juu', 'Huduma ya kipaumbele'],
                  'plans': [{'id': 'm30', 'label': 'Siku 30', 'days': 30, 'price': 5000}]}
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
    try: os.chmod(D, 0o700)
    except Exception: pass
    json.dump(s, open(tmp, 'w'), ensure_ascii=False, indent=1); os.chmod(tmp, 0o600); os.replace(tmp, SETF)
def db():
    os.makedirs(D, exist_ok=True); c = sqlite3.connect(DBF, timeout=20); c.row_factory = sqlite3.Row
    try: os.chmod(D, 0o700); os.chmod(DBF, 0o600)
    except Exception: pass
    c.executescript('''CREATE TABLE IF NOT EXISTS orders(token TEXT PRIMARY KEY, created INT, package TEXT, speed TEXT, duration TEXT,
      network TEXT, phone TEXT, price INT, status TEXT, config TEXT, user_id INT);
      CREATE TABLE IF NOT EXISTS configs(id INTEGER PRIMARY KEY, speed TEXT, duration TEXT, content TEXT, order_token TEXT);
      CREATE TABLE IF NOT EXISTS users(id INTEGER PRIMARY KEY, email TEXT UNIQUE, phone TEXT UNIQUE, name TEXT, google_sub TEXT, created INT, last_login INT);
      CREATE TABLE IF NOT EXISTS sessions(token TEXT PRIMARY KEY, user_id INT, expires INT);
      CREATE TABLE IF NOT EXISTS offers(id INTEGER PRIMARY KEY, title TEXT, descr TEXT, price INT, active INT DEFAULT 1, created INT);
      CREATE TABLE IF NOT EXISTS banners(id INTEGER PRIMARY KEY, title TEXT, text TEXT, place TEXT, color TEXT, coupon TEXT, active INT DEFAULT 1, starts INT DEFAULT 0, ends INT DEFAULT 0);
      CREATE TABLE IF NOT EXISTS coupons(id INTEGER PRIMARY KEY, code TEXT UNIQUE, kind TEXT, value INT, active INT DEFAULT 1, expires INT DEFAULT 0, max_uses INT DEFAULT 0, used INT DEFAULT 0);
      CREATE TABLE IF NOT EXISTS notifs(id INTEGER PRIMARY KEY, user_id INT, title TEXT, body TEXT, kind TEXT, ref TEXT, created INT);
      CREATE TABLE IF NOT EXISTS notif_read(user_id INT, notif_id INT, PRIMARY KEY(user_id, notif_id));
      CREATE TABLE IF NOT EXISTS notif_hidden(user_id INT, notif_id INT, PRIMARY KEY(user_id, notif_id));''')
    for alt in ('ALTER TABLE orders ADD COLUMN user_id INT', "ALTER TABLE offers ADD COLUMN kind TEXT DEFAULT 'offer'", 'ALTER TABLE users ADD COLUMN pw_hash TEXT', 'ALTER TABLE orders ADD COLUMN coupon TEXT', 'ALTER TABLE orders ADD COLUMN hidden INT DEFAULT 0',
                'ALTER TABLE banners ADD COLUMN link TEXT', 'ALTER TABLE banners ADD COLUMN btn TEXT', 'ALTER TABLE banners ADD COLUMN bg TEXT', 'ALTER TABLE banners ADD COLUMN fg TEXT',
                'ALTER TABLE banners ADD COLUMN img TEXT', 'ALTER TABLE banners ADD COLUMN pages TEXT', 'ALTER TABLE banners ADD COLUMN anim INT DEFAULT 1', 'ALTER TABLE banners ADD COLUMN dismiss INT DEFAULT 1', 'ALTER TABLE banners ADD COLUMN sort INT DEFAULT 0',
                "ALTER TABLE configs ADD COLUMN vpn TEXT DEFAULT ''", 'ALTER TABLE configs ADD COLUMN expires INT DEFAULT 0', 'ALTER TABLE configs ADD COLUMN autodel INT DEFAULT 0',
                'ALTER TABLE orders ADD COLUMN vpn TEXT', 'ALTER TABLE orders ADD COLUMN hwid TEXT', 'ALTER TABLE orders ADD COLUMN cfg_id INT', 'ALTER TABLE users ADD COLUMN premium_until INT DEFAULT 0', 'ALTER TABLE offers ADD COLUMN prem INT DEFAULT 0'):  # old databases
        try: c.execute(alt)
        except sqlite3.OperationalError: pass
    migrate_configs(c)
    return c

MIGRATED, MLOCK = [False], threading.Lock()
def migrate_configs(c):  # old databases had content TEXT UNIQUE (global): a repeated / already-sold config was silently ignored -> rebuild the table without it (all stock + orders kept)
    if MIGRATED[0]: return
    with MLOCK:
        if MIGRATED[0]: return
        sql = (c.execute("SELECT sql FROM sqlite_master WHERE type='table' AND name='configs'").fetchone() or [''])[0] or ''
        if re.search(r'content\s+TEXT\s+UNIQUE', sql, re.I):
            c.executescript('''BEGIN; DROP TABLE IF EXISTS configs_new;
              CREATE TABLE configs_new(id INTEGER PRIMARY KEY, speed TEXT, duration TEXT, content TEXT, order_token TEXT, vpn TEXT DEFAULT '', expires INT DEFAULT 0, autodel INT DEFAULT 0);
              INSERT INTO configs_new(id,speed,duration,content,order_token,vpn,expires,autodel) SELECT id,speed,duration,content,order_token,COALESCE(vpn,''),COALESCE(expires,0),COALESCE(autodel,0) FROM configs;
              DROP TABLE configs; ALTER TABLE configs_new RENAME TO configs; COMMIT;''')
        c.execute('CREATE INDEX IF NOT EXISTS cfg_pick ON configs(speed, duration, order_token)'); c.commit()
        MIGRATED[0] = True
def add_config(c, speed, dur, content, vpn='', exp=0, autodel=0):  # 1 = added; 0 = the same config is already waiting UNSOLD in this package (sold ones may be added again)
    return c.execute('INSERT INTO configs(speed,duration,content,vpn,expires,autodel) SELECT ?,?,?,?,?,? WHERE NOT EXISTS (SELECT 1 FROM configs WHERE speed=? AND duration=? AND content=? AND order_token IS NULL)',
                     (speed, dur, content, vpn, exp, autodel, speed, dur, content)).rowcount


# ---------------- VPN app lock (HWID / public key) + config validity time (EAT) ----------------
EAT = 3 * 3600  # East Africa Time (Tanzania) = UTC+3, no daylight saving; admin times are always typed / shown in EAT
def eat_ts(t):  # 'YYYY-MM-DDTHH:MM' typed by the admin in EAT -> unix time
    try: return calendar.timegm(time.strptime(str(t).strip().replace(' ', 'T')[:16], '%Y-%m-%dT%H:%M')) - EAT
    except Exception: return 0
def eat_str(ts): return time.strftime('%Y-%m-%d %H:%M', time.gmtime(int(ts) + EAT)) if ts else ''
def live(): return "(COALESCE(expires,0)=0 OR expires>%d)" % int(time.time())  # SQL: config still valid (not past its end time)
def purge(c): c.execute('DELETE FROM configs WHERE order_token IS NULL AND COALESCE(autodel,0)=1 AND expires>0 AND expires<=?', (int(time.time()),))  # unsold + auto-delete + time reached
def purge_now():
    with LOCK, db() as c: purge(c)
VPNS = {'ssh': 'SSH Custom', 'http': 'HTTP Custom', 'inj': 'HTTP Injector', 'npv': 'NPV Tunnel'}
def hwid_ok(vpn, v):  # does this ID have the format of that VPN app?
    v = str(v or '')
    if vpn in ('ssh', 'http'): return bool(re.fullmatch(r'[a-z0-9]{32}', v))  # 32 small letters + numbers
    if vpn == 'inj': return bool(re.fullmatch(r'[A-Z0-9]{32}', v))  # 32 CAPITAL letters + numbers
    if vpn == 'npv': return bool(re.fullmatch(r'[A-Za-z0-9_+/=-]{40,64}', v)) and bool(re.search('[a-z]', v)) and bool(re.search('[A-Z]', v))  # random mixed case public key
    return False
def hwid_guess(v):  # which app an ID looks like (for a helpful error message)
    return 'SSH Custom / HTTP Custom' if hwid_ok('ssh', v) else 'HTTP Injector' if hwid_ok('inj', v) else 'NPV Tunnel' if hwid_ok('npv', v) else ''
def vpn_info(vpn):
    if vpn not in VPNS: return {}
    return dict(vpn=vpn, app=VPNS[vpn], idn='Public Key' if vpn == 'npv' else 'HWID',
                fmt={'ssh': 'HWID ya SSH Custom: tarakimu 32, herufi ndogo na namba tu.', 'http': 'HWID ya HTTP Custom: tarakimu 32, herufi ndogo na namba tu.',
                     'inj': 'HWID ya HTTP Injector: tarakimu 32, HERUFI KUBWA na namba tu.', 'npv': 'Public Key ya NPV Tunnel: mchanganyiko wa herufi kubwa na ndogo, namba na alama ya - (kama tarakimu 44).'}[vpn])

PW_ITER = 600000
def _pbk(pw, salt, it): return hashlib.pbkdf2_hmac('sha256', pw.encode(), salt.encode(), it).hex()
def mkpw(pw):
    salt = secrets.token_hex(16); return 'p2$%d$%s$%s' % (PW_ITER, salt, _pbk(pw, salt, PW_ITER))
def checkpw(pw, h):  # understands new 'p2$iter$salt$hash' and the old 'salt$hash' (100k) format
    try:
        if str(h).startswith('p2$'): _, it, salt, hx = h.split('$'); it = int(it)
        else: salt, hx = h.split('$'); it = 100000
        return 1000 <= it <= 5000000 and secrets.compare_digest(_pbk(str(pw)[:200], salt, it), hx)
    except Exception: return False
def pw_stale(h): return not str(h or '').startswith('p2$%d$' % PW_ITER)
DUMMY = []
def dummy_check(pw):  # same cost for unknown accounts, so response time does not reveal which emails/phones exist
    if not DUMMY: DUMMY.append(mkpw('dummy-password'))
    checkpw(pw, DUMMY[0])
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
        out.append(dict(id=nid, name=str(n.get('name', ''))[:40] or 'Network', prem=1 if n.get('prem') else 0, plans=plans))
    return out
def clean_premium(p):  # membership settings sent by the admin panel
    p = p if isinstance(p, dict) else {}; plans, seen = [], set()
    for x in (p.get('plans') or [])[:8]:
        mid = ''.join(ch for ch in str(x.get('id', '')) if ch.isalnum())[:12]
        if not mid or mid in seen: mid = 'm' + secrets.token_hex(3)
        seen.add(mid)
        try: days, price = max(1, min(3650, int(x.get('days') or 30))), max(0, int(x.get('price') or 0))
        except Exception: days, price = 30, 0
        plans.append(dict(id=mid, label=str(x.get('label', ''))[:40] or '%d days' % days, days=days, price=price))
    perks = [str(t).strip()[:90] for t in (p.get('perks') or [])[:8] if str(t).strip()]
    return dict(on=1 if p.get('on') else 0, title=str(p.get('title', '')).strip()[:30] or 'Premium', perks=perks, plans=plans)

def find_row(s, speed, dur):
    for p in (p for n, p in allplans(s)):
        if p['id'] == speed:
            for r in p['rows']:
                if r['d'] == dur: return p, r
    return None, None
def net_of(s, plan): return next((n['name'] for n, p in allplans(s) if p is plan), '')

def deliver(token):  # paid -> hand out one unused config (atomic); returns status
    with LOCK, db() as c:
        purge(c)
        o = c.execute('SELECT * FROM orders WHERE token=?', (token,)).fetchone()
        if not o: return 'failed'
        if o['status'] in ('success', 'need_id'): return o['status']
        if str(o['speed']).startswith('member:'):  # paid membership -> extend Premium (stacks on top of the remaining time)
            days = int(o['duration']) if str(o['duration']).isdigit() else 0
            usr = c.execute('SELECT premium_until FROM users WHERE id=?', (o['user_id'],)).fetchone()
            if not usr or days <= 0: return 'failed'
            until = max(int(time.time()), int(usr['premium_until'] or 0)) + days * 86400
            c.execute('UPDATE users SET premium_until=? WHERE id=?', (until, o['user_id']))
            c.execute("UPDATE orders SET status='success',config=?,hidden=1 WHERE token=?", ('Premium hadi ' + dstr(until), token)); c.execute("DELETE FROM notifs WHERE kind='pending' AND ref=?", (token,))
            notify(c, o['user_id'], 'Karibu Premium 👑', 'Premium yako imewashwa hadi %s. Vifurushi na ofa za Premium sasa zimefunguka.' % dstr(until), 'paid', token)
            return 'success'
        k = c.execute('SELECT id,content,vpn FROM configs WHERE speed=? AND duration=? AND order_token IS NULL AND ' + live() + ' ORDER BY id LIMIT 1', (o['speed'], o['duration'])).fetchone()
        if not k:
            c.execute("UPDATE orders SET status='no_stock' WHERE token=?", (token,)); c.execute("DELETE FROM notifs WHERE kind='pending' AND ref=?", (token,))
            if o['user_id']: notify(c, o['user_id'], 'Config zimeisha', '%s: malipo yamepokelewa lakini config zimeisha kwa sasa. Wasiliana nasi utapata config au kurejeshewa pesa.' % o['package'], 'issue', token)
            return 'no_stock'
        c.execute('UPDATE configs SET order_token=? WHERE id=?', (token, k['id']))
        if o['price'] and k['vpn'] in VPNS:  # PAID config of a locked VPN app: keep it reserved, customer must send HWID / public key first (free configs never ask)
            c.execute("UPDATE orders SET status='need_id',vpn=?,cfg_id=? WHERE token=?", (k['vpn'], k['id'], token)); c.execute("DELETE FROM notifs WHERE kind='pending' AND ref=?", (token,))
            if o['user_id']: notify(c, o['user_id'], 'Malipo yamefanikiwa ✅', '%s: weka %s ya %s ndani ya \"Config Zangu\" ili upate config yako.' % (o['package'], vpn_info(k['vpn'])['idn'], VPNS[k['vpn']]), 'paid', token)
            return 'need_id'
        c.execute("UPDATE orders SET status='success',config=? WHERE token=?", (k['content'], token)); c.execute("DELETE FROM notifs WHERE kind='pending' AND ref=?", (token,))
        es = str(o['speed']).startswith('offer:') and c.execute("SELECT 1 FROM offers WHERE id=? AND kind='esim'", (int(o['speed'][6:]) if o['speed'][6:].isdigit() else -1,)).fetchone()
        if o['user_id']: notify(c, o['user_id'], 'Malipo yamefanikiwa ✅' if o['price'] else 'Config yako iko tayari ✅', ('%s: eSIM yako iko tayari. Ifungue ndani ya ukurasa wa eSIM, sehemu ya "eSIM Zangu".' if es else '%s: config yako iko tayari. Itafute wakati wowote ndani ya "Config Zangu".') % o['package'], 'paid', token)
        return 'success'

def pp_on(s): return 'peterpay' in str(s.get('pay_api_url', '')).lower()  # PeterPay mode: URL = https://api.peterpay.link/api/v1 , API key = public key (pk_...), secret = webhook secret (sk_...)
PPCTX = ssl.create_default_context()  # certificate + hostname ARE verified (a man-in-the-middle could otherwise read the API key or fake "paid" answers)
if os.environ.get('STORE_PP_INSECURE') == '1': PPCTX.check_hostname = False; PPCTX.verify_mode = ssl.CERT_NONE  # emergency switch only
PP_PHP = r"""<?php
// visibleTech -> PeterPay bridge. PeterPay accepts PHP/cURL requests but blocks Python's, so the store hands the request to this script.
// stdin: {"url":..,"headers":{..},"body":{..}}   stdout: {"code":HTTP,"raw":"reply body","err":"curl error or empty"}
$in = json_decode(stream_get_contents(STDIN), true);
if (!is_array($in) || empty($in['url'])) { echo json_encode(array('code' => 0, 'raw' => '', 'err' => 'bad input')); exit; }
$h = array(); foreach (($in['headers'] ?? array()) as $k => $v) $h[] = $k . ': ' . $v;
$payload = json_encode($in['body'] ?? new stdClass(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
function pp_send($url, $h, $payload, $verify) {
  $ch = curl_init($url);
  curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, $payload); curl_setopt($ch, CURLOPT_HTTPHEADER, $h);
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, true); curl_setopt($ch, CURLOPT_TIMEOUT, 25); curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
  curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, $verify); curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, $verify ? 2 : 0);
  $raw = curl_exec($ch); $r = array('raw' => ($raw === false ? '' : $raw), 'code' => (int)curl_getinfo($ch, CURLINFO_HTTP_CODE), 'errno' => curl_errno($ch), 'err' => curl_error($ch));
  curl_close($ch); return $r;
}
$r = pp_send($in['url'], $h, $payload, true);  // certificate checked first
if (in_array($r['errno'], array(35, 51, 58, 60, 77, 83))) $r = pp_send($in['url'], $h, $payload, false);  // PeterPay's chain is incomplete: retry as PeterPay advised (VERIFYPEER=false)
echo json_encode(array('code' => $r['code'], 'raw' => $r['raw'], 'err' => $r['err']));
"""
PP_PHP_FILE = D + '/pp_bridge.php'
def pp_php(url, headers, body):  # -> (http code, raw bytes), or None when PHP is not usable here (then Python's own HTTP is tried)
    php = shutil.which('php')
    if not php: return None
    try:
        if not os.path.exists(PP_PHP_FILE) or open(PP_PHP_FILE, encoding='utf-8').read() != PP_PHP:
            with open(PP_PHP_FILE, 'w', encoding='utf-8') as fh: fh.write(PP_PHP)
            os.chmod(PP_PHP_FILE, 0o600)
        r = subprocess.run([php, PP_PHP_FILE], input=json.dumps(dict(url=url, headers=headers, body=body)).encode(), capture_output=True, timeout=45)  # key goes over stdin, never on the command line
        try: d = json.loads(r.stdout or b'{}')
        except Exception:
            print('PeterPay php bridge printed something unexpected (is php-curl installed?  apt install php-cli php-curl):', (r.stdout or r.stderr)[:300], flush=True); return None
        if not d.get('code'): print('PeterPay (php) connection error:', d.get('err') or 'no reply', 'url=', url, flush=True)
        return int(d.get('code') or 0), str(d.get('raw') or '').encode()
    except Exception as e: print('PeterPay php bridge failed:', repr(e), flush=True); return None
def pp_call(s, host, path, body):  # POST to PeterPay -> (http code, json). PHP/cURL first (what PeterPay accepts), plain Python as fallback
    url = s['pay_api_url'].rstrip('/') + path; site = s.get('pay_site') or str(host).split(':')[0]
    res = pp_php(url, {'X-API-KEY': s['pay_api_key'], 'X-PeterPay-Site': site, 'Content-Type': 'application/json', 'Accept': 'application/json'}, body)
    if res is not None: code, raw = res
    else:
        rq = urllib.request.Request(url, json.dumps(body).encode(), {'X-API-KEY': s['pay_api_key'], 'X-PeterPay-Site': site, 'Content-Type': 'application/json', 'Accept': 'application/json', 'User-Agent': 'python-requests/2.31.0'})
        try: r = urllib.request.urlopen(rq, timeout=20, context=PPCTX); code, raw = r.status, r.read()
        except urllib.error.HTTPError as e: code, raw = e.code, e.read()
        except Exception as e: print('PeterPay connection error:', repr(e), 'url=', rq.full_url, flush=True); return 0, {}
    if not raw.strip(): print('PeterPay replied with an EMPTY body: HTTP', code, 'url=', url, flush=True); return code, {}
    try: return code, json.loads(raw)
    except Exception: print('PeterPay replied with something that is not JSON: HTTP', code, 'url=', url, 'body=', raw[:400], flush=True); return code, {}
def pay_request(s, token, o, host):  # returns None when the request was sent, else an error text for the customer
    if not s['pay_api_url']: return None  # no provider: order stays pending until webhook / manual confirm
    if pp_on(s):
        ph = ''.join(ch for ch in str(o['phone']) if ch.isdigit()); ph = '255' + ph[1:] if ph.startswith('0') else ph
        code, d = pp_call(s, host, '/create_order', {'amount': int(o['price']), 'buyer_phone': ph, 'buyer_name': 'Customer', 'order_id': token})
        if code in (200, 201) and str(d.get('status', '')).lower() == 'success': return None
        print('PeterPay create_order failed:', code, d, flush=True)
        return {401: 'Mipangilio ya malipo si sahihi (API key). Wasiliana nasi.', 403: 'Malipo yamezuiwa (IP/website haijaruhusiwa). Wasiliana nasi.', 422: 'Namba ya simu au kiasi si sahihi (kiwango cha chini 500/=).'}.get(code, 'Imeshindikana kutuma ombi la malipo.')
    body = json.dumps({'order_id': token, 'amount': o['price'], 'phone': o['phone'], 'network': o['network'],
                       'callback_url': '%s://%s/api/webhook/%s' % ('https' if BIND == '127.0.0.1' else 'http', host, s['webhook_secret'])}).encode()
    rq = urllib.request.Request(s['pay_api_url'], body, {'Content-Type': 'application/json', 'Authorization': 'Bearer ' + s['pay_api_key']})
    try: urllib.request.urlopen(rq, timeout=20).read(); return None
    except Exception: return 'Imeshindikana kutuma ombi la malipo.'
PPLAST = {}
def pp_poll(s, host, token, price):  # ask PeterPay directly (backup for a missed webhook): 'ok' = really paid (amount checked), 'failed', or None = still waiting
    if not pp_on(s) or time.time() - PPLAST.get(token, 0) < 3: return None
    PPLAST[token] = time.time()
    if len(PPLAST) > 5000: PPLAST.clear()
    code, d = pp_call(s, host, '/order_status', {'order_id': token}); x = d.get('data') if isinstance(d.get('data'), dict) else {}
    st = str(x.get('payment_status', '')).upper()
    try: amt = float(x.get('amount') or 0)
    except Exception: amt = 0
    if st == 'COMPLETED' and amt >= price: return 'ok'
    if st in ('FAILED', 'CANCELLED', 'CANCELED', 'EXPIRED'): return 'failed'
    return None


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
def is_prem(u):
    try: return int(u['premium_until'] or 0) > time.time()
    except Exception: return False
def pub(u): return dict(name=u['name'] or '', email=u['email'] or '', phone=u['phone'] or '', premium=1 if is_prem(u) else 0, premium_until=dstr(u['premium_until']) if is_prem(u) else '')
def login_needed(s): return True  # login is ALWAYS required: nothing (page content, packages, orders, configs) is served to anonymous visitors
# ---------------- offers / banners / coupons / notifications ----------------
def esim_ids(): return {str(r[0]) for r in db().execute("SELECT id FROM offers WHERE kind='esim'")}
def esim_parts(cfg):  # one eSIM stock item: JSON {esim:1, qr, link, code, smdp} | raw QR image (data URL) | link | activation code. A link / code may contain LPA:1$smdp$activation
    cfg = str(cfg or '').strip(); d = {}
    if cfg.startswith('{'):
        try: d = json.loads(cfg)
        except Exception: d = {}
    elif cfg.startswith('data:image/'): d = {'qr': cfg}
    elif re.match(r'^https?://\S+$', cfg): d = {'link': cfg}
    elif cfg: d = {'code': cfg}
    d = d if isinstance(d, dict) else {}
    qr = str(d.get('qr') or ''); qr = qr if IMG_RE.match(qr) else ''
    link = str(d.get('link') or ''); link = link if re.match(r'^https?://\S+$', link) else ''
    code = str(d.get('code') or ''); rx = r'LPA:1\$([^$\s&"\'<>]+)\$([^$\s&"\'<>]*)(\$[^\s&"\'<>]*)?'
    mc, ml = re.search(rx, urllib.parse.unquote(code), re.I), re.search(rx, urllib.parse.unquote(link), re.I); m = mc or ml
    return dict(qr=qr, link=link, lpa=m.group(0) if m else '', smdp=str(d.get('smdp') or '') or (m.group(1) if m else ''), act=mc.group(2) if mc else (code or (ml.group(2) if ml else '')))
def esim_html(cfg):  # simple page for /r/<token> of an eSIM order
    e = lambda t: str(t).replace('&', '&amp;').replace('<', '&lt;').replace('>', '&gt;').replace('"', '&quot;')
    p, box, h = esim_parts(cfg), 'background:#111A2E;padding:12px;border-radius:12px;margin:6px 0 14px;word-break:break-all;white-space:pre-wrap', ''
    if p['qr']: h += '<img alt="eSIM QR code" src="%s" style="display:block;max-width:260px;width:100%%;margin:10px auto;background:#fff;padding:10px;border-radius:12px">' % e(p['qr'])
    if p['link']: h += '<p><a style="color:#3DDC84" href="%s" target="_blank" rel="noopener">Open eSIM link</a></p><div style="%s">%s</div>' % (e(p['link']), box, e(p['link']))
    for lbl, k in (('Activation code (LPA)', 'lpa'), ('SM-DP+ Address', 'smdp'), ('Activation Code', 'act')):
        if p[k]: h += '<small>%s</small><div style="%s">%s</div>' % (lbl, box, e(p[k]))
    return h or '<p>eSIM is empty.</p>'
def notify(c, uid, title, body, kind='info', ref=None):  # uid None = everybody
    if ref and c.execute('SELECT 1 FROM notifs WHERE kind=? AND ref=?', (kind, ref)).fetchone(): return
    c.execute('INSERT INTO notifs(user_id,title,body,kind,ref,created) VALUES(?,?,?,?,?,?)', (uid, title, body, kind, ref, int(time.time())))
def fail_order(tok, why='Malipo yameshindikana'):
    with LOCK, db() as c:
        o = c.execute("SELECT user_id,package FROM orders WHERE token=? AND status='pending'", (tok,)).fetchone()
        if not o: return
        c.execute("UPDATE orders SET status='failed' WHERE token=?", (tok,)); c.execute("DELETE FROM notifs WHERE kind='pending' AND ref=?", (tok,))
        if o['user_id']: notify(c, o['user_id'], why, '%s: malipo yameshindikana. Hakuna pesa iliyokatwa. Jaribu tena au wasiliana nasi.' % o['package'], 'issue', tok)
def offer_price(o): return max(0, int(o['price'] or 0)) if (o['kind'] or 'offer') == 'esim' else 0  # only eSIM can have a price
def resolve_item(s, c, f, u=None):  # what is being bought: a membership, an admin "offer" / eSIM, or a classic network/plan/duration package. {'locked':1} = Premium only
    mid = str(f.get('member_id') or '')
    if mid:
        pm = s.get('premium') or {}; x = next((x for x in pm.get('plans', []) if x['id'] == mid), None)
        return dict(title='👑 %s — %s' % (pm.get('title', 'Premium'), x['label']), speed='member:' + mid, duration=str(x['days']), network='', price=int(x['price'])) if x and pm.get('on') else None
    oid = str(f.get('offer_id') or '')
    if oid:
        o = c.execute('SELECT * FROM offers WHERE id=? AND active=1', (int(oid) if oid.isdigit() else -1,)).fetchone()
        if o and o['prem'] and not (u and is_prem(u)): return dict(locked=1)
        return dict(title=o['title'], speed='offer:%d' % o['id'], duration='stock', network='', price=offer_price(o)) if o else None  # offers are always FREE; eSIM is PAID (price set in the admin eSIM tab)
    plan, r = find_row(s, f.get('speed_type', ''), f.get('duration', ''))
    if r and any(n.get('prem') for n, p in allplans(s) if p is plan) and not (u and is_prem(u)): return dict(locked=1)
    return dict(title='%s — %s — %s' % (net_of(s, plan), plan['title'], r['label']), speed=plan['id'], duration=r['d'], network=net_of(s, plan), price=int(r['price'])) if r else None
def apply_coupon(c, code, price):  # -> (new price, coupon row, error)
    k = c.execute('SELECT * FROM coupons WHERE code=? COLLATE NOCASE', (code,)).fetchone()
    if not k or not k['active']: return price, None, 'Kodi si sahihi.'
    if k['expires'] and k['expires'] < time.time(): return price, None, 'Kodi hii imeisha muda wake.'
    if k['max_uses'] and k['used'] >= k['max_uses']: return price, None, 'Kodi hii imefikia kikomo cha matumizi.'
    off = price * min(100, k['value']) // 100 if k['kind'] == 'pct' else k['value']
    return max(0, price - off), k, None
BN_PAGES = ('home', 'offers', 'packages', 'configs', 'notifs', 'help')
HEX = re.compile(r'^#[0-9a-fA-F]{6}$'); IMG_RE = re.compile(r'^data:image/(png|jpeg|webp|gif);base64,[A-Za-z0-9+/=]+$')
def bn_pub(r):  # banner as the customer site needs it
    return dict(id=r['id'], title=r['title'], text=r['text'], place=r['place'], color=r['color'], coupon=r['coupon'] or '', link=r['link'] or '', btn=r['btn'] or '',
                bg=r['bg'] or '', fg=r['fg'] or '', pages=r['pages'] or 'all', anim=0 if r['anim'] == 0 else 1, dismiss=0 if r['dismiss'] == 0 else 1,
                img=('/api/banner-img/%d?v=%d' % (r['id'], len(r['img']))) if r['img'] else '')
def dstr(ts): return time.strftime('%Y-%m-%d', time.localtime(ts)) if ts else ''
def pdate(t, end=False):
    try: return int(time.mktime(time.strptime(str(t).strip(), '%Y-%m-%d'))) + (86399 if end else 0)
    except Exception: return 0
def admin_extra(c):
    st = {r[0]: (r[1] or 0, r[2], r[3] or 0) for r in c.execute("SELECT speed,SUM(order_token IS NULL AND " + live() + "),COUNT(*),SUM(order_token IS NULL AND NOT " + live() + ") FROM configs WHERE speed LIKE 'offer:%' GROUP BY 1")}
    return dict(
        offers=[dict(id=r['id'], title=r['title'], descr=r['descr'], price=offer_price(r), active=r['active'], kind=(r['kind'] or 'offer'), prem=r['prem'] or 0, free=st.get('offer:%d' % r['id'], (0, 0, 0))[0], total=st.get('offer:%d' % r['id'], (0, 0, 0))[1], exp=st.get('offer:%d' % r['id'], (0, 0, 0))[2]) for r in c.execute('SELECT * FROM offers ORDER BY id DESC')],
        banners=[dict(bn_pub(r), img='', has_img=1 if r['img'] else 0, active=r['active'], starts=dstr(r['starts']), ends=dstr(r['ends']), sort=r['sort'] or 0) for r in c.execute('SELECT * FROM banners ORDER BY COALESCE(sort,0), id DESC')],
        coupons=[dict(id=r['id'], code=r['code'], kind=r['kind'], value=r['value'], active=r['active'], expires=dstr(r['expires']), max_uses=r['max_uses'], used=r['used']) for r in c.execute('SELECT * FROM coupons ORDER BY id DESC')],
        notifs=[dict(id=r[0], title=r[1], body=r[2], t=time.strftime('%m-%d %H:%M', time.localtime(r[3])), to=(r[4] or r[5] or r[6] or 'user') if r[7] else 'ALL USERS') for r in c.execute("SELECT n.id,n.title,n.body,n.created,u.email,u.phone,u.name,n.user_id FROM notifs n LEFT JOIN users u ON u.id=n.user_id WHERE n.kind='info' ORDER BY n.created DESC LIMIT 100")])
def site_payload(s, u=None):
    now = int(time.time()); purge_now(); prem = bool(u and is_prem(u)); pm = s.get('premium') or {}
    with db() as c:
        free = {r[0]: r[1] or 0 for r in c.execute("SELECT speed,SUM(order_token IS NULL AND " + live() + ") FROM configs WHERE speed LIKE 'offer:%' GROUP BY 1")}
        offers = [dict(id=r['id'], title=r['title'], descr=r['descr'], price=offer_price(r), kind=(r['kind'] or 'offer'), prem=1 if r['prem'] else 0, free=free.get('offer:%d' % r['id'], 0)) for r in c.execute('SELECT * FROM offers WHERE active=1 ORDER BY id DESC')]
        banners = [bn_pub(r) for r in c.execute('SELECT * FROM banners WHERE active=1 AND (starts=0 OR starts<=?) AND (ends=0 OR ends>=?) ORDER BY COALESCE(sort,0), id DESC', (now, now))]
    nets = [n for n in s['networks'] if prem or not n.get('prem')]
    locked = sum(1 for n in s['networks'] if n.get('prem')) + sum(1 for o in offers if o['prem']) if not prem else 0  # what a free user is missing (teaser only, no details)
    offers = [o for o in offers if prem or not o['prem']]
    d = {k: s[k] for k in ('name', 'whatsapp', 'youtube')}; d.update(networks=nets, offers=offers, banners=banners)
    d['premium'] = dict(on=1 if pm.get('on') else 0, title=pm.get('title', 'Premium'), perks=pm.get('perks', []), plans=[dict(id=x['id'], label=x['label'], days=x['days'], price=x['price']) for x in pm.get('plans', [])],
                        active=1 if prem else 0, until=dstr(u['premium_until']) if prem else '', locked=locked)
    return d

# Login page: built into store.py so visitors who are not logged in get ONLY this (never any site content, whatever index.html says)
LOGIN_PAGE = r'''<!DOCTYPE html><html lang="sw"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex"><meta name="theme-color" content="#070D0A"><link rel="manifest" href="/manifest.webmanifest"><link rel="apple-touch-icon" href="/icon-192.png"><title>__NAME__ – Ingia</title>
<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin><link href="https://fonts.googleapis.com/css2?family=Instrument+Sans:wght@400;500;600;700&family=Unbounded:wght@600;700&display=swap" rel="stylesheet">
<style>
*{box-sizing:border-box;margin:0}[hidden]{display:none!important}
body{min-height:100vh;display:grid;place-items:center;padding:18px;background:#070D0A;color:#EAF3EC;font:16px/1.5 'Instrument Sans',system-ui,sans-serif}
.card{width:100%;max-width:400px;background:rgba(255,255,255,.06);backdrop-filter:blur(16px);border:1px solid rgba(255,255,255,.12);border-radius:24px;padding:0 24px 26px;overflow:hidden;box-shadow:0 30px 80px rgba(0,0,0,.55);animation:up .6s cubic-bezier(.2,.9,.3,1)}
@keyframes up{from{opacity:0;transform:translateY(20px)}}
.band{height:12px;margin:0 -24px 24px;background:linear-gradient(90deg,#0B8A3E 0 33%,#FCD116 33% 38%,#0A0F0C 38% 62%,#FCD116 62% 67%,#0A7FB5 67% 100%)}
.logo{display:flex;align-items:center;justify-content:center;gap:10px;font:700 24px 'Unbounded',system-ui;letter-spacing:-.04em}.logo b{color:#3DDC84}
.sub{text-align:center;color:#97ABA0;margin:8px 0 20px;font-size:15px}
.tabs{display:grid;grid-template-columns:1fr 1fr;gap:6px;background:rgba(0,0,0,.35);padding:5px;border-radius:14px;margin-bottom:16px}
.tabs button{border:0;border-radius:10px;padding:10px;background:none;color:#97ABA0;font:700 15px 'Instrument Sans',system-ui;cursor:pointer}.tabs button.on{background:#0B8A3E;color:#fff}
input{width:100%;background:rgba(0,0,0,.35);border:1px solid rgba(255,255,255,.14);border-radius:12px;padding:13px 14px;margin-bottom:10px;color:#EAF3EC;font:inherit}input:focus{outline:0;border-color:#3DDC84;box-shadow:0 0 0 3px rgba(11,138,62,.18)}
.go{width:100%;border:0;border-radius:12px;padding:14px;font:700 16px 'Instrument Sans',system-ui;color:#fff;cursor:pointer;background:#0B8A3E}.go:hover{background:#087535}.go:active{transform:scale(.98)}.go:disabled{opacity:.6}
#msg{min-height:20px;margin-top:10px;color:#FF8A96;font-size:14px;text-align:center}.lock{text-align:center;font-size:12px;color:#7A8C82;margin-top:14px}
:focus-visible{outline:3px solid #0A7FB5;outline-offset:2px}
@media(prefers-reduced-motion:reduce){.card{animation:none}}
.au{position:fixed;inset:0;z-index:-1;overflow:hidden}.au i{position:absolute;border-radius:50%;filter:blur(90px);opacity:.4;animation:d 14s ease-in-out infinite alternate}.au i:nth-child(1){width:460px;height:460px;background:#0B8A3E;top:-120px;left:-100px}.au i:nth-child(2){width:400px;height:400px;background:#0A7FB5;right:-100px;top:35%;animation-delay:-5s}.au i:nth-child(3){width:340px;height:340px;background:#FCD116;left:35%;bottom:-170px;opacity:.15;animation-delay:-9s}@keyframes d{to{transform:translate(60px,40px) scale(1.2)}}.go{position:relative;overflow:hidden}.go::after{content:"";position:absolute;top:0;left:-60%;width:40%;height:100%;background:linear-gradient(100deg,transparent,rgba(255,255,255,.35),transparent);transform:skewX(-20deg);animation:sh 3.4s ease-in-out infinite}@keyframes sh{0%,55%{left:-60%}100%{left:130%}}.band{background-size:200% 100%;animation:sl 6s linear infinite}@keyframes sl{to{background-position:-200% 0}}@media(prefers-reduced-motion:reduce){*,*::after{animation:none!important}}</style></head><body>
<div class="au"><i></i><i></i><i></i></div><main class="card"><div class="band"></div><div class="logo"><svg width="26" height="26" viewBox="0 0 30 30" aria-hidden="true"><rect x="2" y="18" width="6" height="10" rx="1.5" fill="#0B8A3E"/><rect x="12" y="11" width="6" height="17" rx="1.5" fill="#E5B800"/><rect x="22" y="3" width="6" height="25" rx="1.5" fill="#0A7FB5"/></svg><span>visible<b>Tech</b></span></div><p class="sub">Ingia au jisajili ili kuendelea.</p>
<div class="tabs"><button id="t1" class="on" onclick="tab(1)">Ingia</button><button id="t2" onclick="tab(2)">Jisajili</button></div>
<form id="f1" onsubmit="return doIn(event)"><input id="li" autocomplete="username" placeholder="Barua pepe au namba ya simu" required><input id="lp" type="password" autocomplete="current-password" placeholder="Nenosiri" required><button class="go" id="b1">Ingia</button></form>
<form id="f2" onsubmit="return doUp(event)" hidden><input id="sn" autocomplete="name" placeholder="Jina (si lazima)"><input id="se" type="email" autocomplete="email" placeholder="Barua pepe" required><input id="sp" type="tel" autocomplete="tel" placeholder="Namba ya simu (0712345678)" required><input id="sw" type="password" autocomplete="new-password" placeholder="Nenosiri (angalau herufi 8)" required><button class="go" id="b2">Fungua akaunti</button></form>
<div id="msg" role="alert"></div><div class="lock">🔒 Kwa wanachama pekee</div></main>
<script>
const $=i=>document.getElementById(i);if('serviceWorker' in navigator)navigator.serviceWorker.register('/sw.js').catch(()=>{});
function tab(n){$('f1').hidden=n!=1;$('f2').hidden=n!=2;$('t1').className=n==1?'on':'';$('t2').className=n==2?'on':'';$('msg').textContent=''}
async function post(u,b){const r=await fetch(u,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(b)});let d={};try{d=await r.json()}catch(e){}if(!r.ok)throw (d.error||'Kuna hitilafu. Jaribu tena.');return d}
async function run(btn,fn){$('msg').textContent='';btn.disabled=true;try{await fn();location.reload()}catch(m){$('msg').textContent=String(m);btn.disabled=false}}
function doIn(e){e.preventDefault();run($('b1'),()=>post('/api/auth/login',{login:$('li').value.trim(),password:$('lp').value}));return false}
function doUp(e){e.preventDefault();run($('b2'),()=>post('/api/auth/register',{name:$('sn').value,email:$('se').value,phone:$('sp').value,password:$('sw').value}));return false}
</script></body></html>'''
def gate_page(s): return LOGIN_PAGE.replace('__NAME__', str(s['name']).replace('&', '&amp;').replace('<', '&lt;')).encode()


# ---------------- installable web app (PWA): manifest + service worker + icons, served WITHOUT login (the browser fetches them before / without the session cookie) ----------------
ICONS = {}
def icon_png(n):  # app icon drawn with plain Python (the visibleTech 3 bars on dark green-black), kept inside the "maskable" safe zone
    if n in ICONS: return ICONS[n]
    import zlib, struct
    u = 0.56 * n / 26.0; ox, oy = (n - 26 * u) / 2.0, (n - 25 * u) / 2.0
    bars = [(2, 18, 6, 10, (11, 138, 62)), (12, 11, 6, 17, (229, 184, 0)), (22, 3, 6, 25, (10, 127, 181))]
    rws = []
    for y in range(n):
        row = bytearray(bytes((7, 13, 10)) * n)
        for x, y0, w, h, col in bars:
            if int(round(oy + (y0 - 3) * u)) <= y < int(round(oy + (y0 - 3 + h) * u)):
                a, b = int(round(ox + (x - 2) * u)), int(round(ox + (x - 2 + w) * u)); row[a * 3:b * 3] = bytes(col) * (b - a)
        rws.append(b'\x00' + bytes(row))
    ch = lambda t, d: struct.pack('>I', len(d)) + t + d + struct.pack('>I', zlib.crc32(t + d) & 0xffffffff)
    ICONS[n] = b'\x89PNG\r\n\x1a\n' + ch(b'IHDR', struct.pack('>IIBBBBB', n, n, 8, 2, 0, 0, 0)) + ch(b'IDAT', zlib.compress(b''.join(rws), 9)) + ch(b'IEND', b'')
    return ICONS[n]
def manifest(s):
    nm = str(s['name'])
    return json.dumps({'name': nm + ' – Online Cloud Config', 'short_name': nm[:12], 'description': 'Config za internet na eSIM, papo hapo.', 'id': '/', 'start_url': '/', 'scope': '/', 'display': 'standalone', 'orientation': 'portrait',
                       'background_color': '#070D0A', 'theme_color': '#070D0A', 'lang': 'sw',
                       'icons': [{'src': '/icon-%d.png' % z, 'sizes': '%dx%d' % (z, z), 'type': 'image/png', 'purpose': p} for z in (192, 512) for p in ('any', 'maskable')]}, ensure_ascii=False)
SW_JS = r"""// visibleTech service worker: makes the site installable. It caches NOTHING (accounts, orders and configs stay private); only shows a friendly page when the phone is offline.
self.addEventListener('install', e => self.skipWaiting());
self.addEventListener('activate', e => e.waitUntil(self.clients.claim()));
self.addEventListener('fetch', e => {
  const r = e.request;
  if (r.method !== 'GET' || r.mode !== 'navigate') return;
  e.respondWith(fetch(r).catch(() => new Response('<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><body style="background:#070D0A;color:#EAF3EC;font-family:sans-serif;display:grid;place-items:center;min-height:100vh;text-align:center;padding:20px"><div><h2>Hakuna mtandao</h2><p style="color:#97ABA0;margin:8px 0 18px">Washa data au Wi-Fi kisha jaribu tena.</p><button onclick="location.reload()" style="padding:12px 24px;border:0;border-radius:12px;background:#0B8A3E;color:#fff;font-size:16px">Jaribu tena</button></div></body>', {headers: {'Content-Type': 'text/html; charset=utf-8'}})));
});
"""
HITS, SESS, FAILS = {}, {}, {}
class H(BaseHTTPRequestHandler):
    server_version = 'vstore'; sys_version = ''
    timeout = 30  # drop slow / stalled connections (slowloris)
    def log_message(self, *a): pass
    def cookies(self): return dict(x.strip().split('=', 1) for x in (self.headers.get('Cookie') or '').split(';') if '=' in x)
    def origin_ok(self):  # CSRF defence in depth: a browser request coming from another site is refused
        if (self.headers.get('Sec-Fetch-Site') or '') == 'cross-site': return False
        o = self.headers.get('Origin') or ''
        return not o or urllib.parse.urlsplit(o).netloc.lower() == (self.headers.get('Host') or '').lower()
    def out(self, code, body, ctype='application/json', hdr=()):
        b = body if isinstance(body, bytes) else (json.dumps(body, ensure_ascii=False) if ctype == 'application/json' else body).encode()
        self.send_response(code); self.send_header('Content-Type', ctype if ctype.startswith('image/') else ctype + '; charset=utf-8'); self.send_header('Content-Length', str(len(b)))
        self.send_header('Cache-Control', 'no-store'); [self.send_header(k, v) for k, v in SEC_HDR]; [self.send_header(k, v) for k, v in hdr]; self.end_headers(); self.wfile.write(b)
    def ip(self): return (self.headers.get('X-Real-IP') if BIND == '127.0.0.1' else None) or self.client_address[0]
    def me(self):  # logged-in customer (row) or None
        ck = self.cookies()
        if not ck.get('vs_user'): return None
        with db() as c: return c.execute('SELECT u.* FROM sessions s JOIN users u ON u.id=s.user_id WHERE s.token=? AND s.expires>?', (tok_hash(ck['vs_user']), int(time.time()))).fetchone()
    def login_ok(self, c, u):  # create session + cookie
        now = int(time.time()); tok = secrets.token_urlsafe(32)
        c.execute('DELETE FROM sessions WHERE expires<?', (now,))
        c.execute('DELETE FROM sessions WHERE user_id=? AND token NOT IN (SELECT token FROM sessions WHERE user_id=? ORDER BY expires DESC LIMIT 9)', (u['id'], u['id']))  # max 10 devices
        c.execute('INSERT INTO sessions VALUES(?,?,?)', (tok_hash(tok), u['id'], now + 2592000))
        return self.out(200, {'ok': True, 'user': pub(u)}, hdr=[('Set-Cookie', 'vs_user=%s; Path=/; HttpOnly; SameSite=Lax; Max-Age=2592000%s' % (tok, '; Secure' if HTTPS else ''))])
    def auth(self, s, p, f):  # all /api/auth/* endpoints
        now, ip = time.time(), self.ip()
        if len(REGIP) > 5000: REGIP.clear()
        if p == '/api/auth/register':
            REGIP[ip] = [t for t in REGIP.get(ip, []) if now - t < 3600]
            if len(REGIP[ip]) >= 10: return self.out(429, {'error': 'Maombi mengi mno. Jaribu tena baadaye.'})
            email, phone, pw, name = norm_email(f.get('email')), norm_phone(f.get('phone')), str(f.get('password', '')), str(f.get('name', '')).strip()[:60]
            if not email: return self.out(400, {'error': 'Barua pepe si sahihi.'})
            if not phone: return self.out(400, {'error': 'Namba ya simu si sahihi.'})
            if pw_err(pw): return self.out(400, {'error': pw_err(pw)})
            REGIP[ip].append(now)
            with LOCK, db() as c:
                if c.execute('SELECT 1 FROM users WHERE email=? OR phone=?', (email, phone)).fetchone(): return self.out(409, {'error': 'Barua pepe au namba hii tayari ina akaunti. Ingia badala yake.'})
                cur = c.execute('INSERT INTO users(email,phone,name,created,last_login,pw_hash) VALUES(?,?,?,?,?,?)', (email, phone, name, int(now), int(now), mkpw(pw)))
                return self.login_ok(c, c.execute('SELECT * FROM users WHERE id=?', (cur.lastrowid,)).fetchone())
        if p == '/api/auth/login':
            who = str(f.get('login', '')).strip(); key = norm_email(who) or norm_phone(who) or who[:60].lower()
            LOGIP[ip] = [t for t in LOGIP.get(ip, []) if now - t < 600]; LOGT[key] = [t for t in LOGT.get(key, []) if now - t < 3600]
            if len(LOGIP) > 5000: LOGIP.clear(); LOGT.clear()
            if len(LOGIP[ip]) >= 8 or len(LOGT[key]) >= 10: return self.out(429, {'error': 'Majaribio mengi mno. Subiri dakika chache.'})
            pw = str(f.get('password', ''))[:200]
            u = db().execute('SELECT * FROM users WHERE email=? OR phone=?', (norm_email(who) or '-', norm_phone(who) or '-')).fetchone()
            if u and u['pw_hash']: good = checkpw(pw, u['pw_hash'])  # slow hash runs OUTSIDE the global lock
            else: dummy_check(pw); good = False
            if not good:
                LOGIP[ip].append(now); LOGT[key].append(now); return self.out(403, {'error': 'Barua pepe/namba au nenosiri si sahihi.'})
            with LOCK, db() as c:
                c.execute('UPDATE users SET last_login=? WHERE id=?', (int(now), u['id']))
                if pw_stale(u['pw_hash']): c.execute('UPDATE users SET pw_hash=? WHERE id=?', (mkpw(pw), u['id']))  # silently upgrade old hashes
                return self.login_ok(c, u)
        if p == '/api/auth/password':  # logged-in customer changes own password
            u = self.me()
            if not u: return self.out(401, {'error': 'login'})
            if pw_err(str(f.get('new', ''))): return self.out(400, {'error': pw_err(str(f.get('new', '')))})
            LOGIP[ip] = [t for t in LOGIP.get(ip, []) if now - t < 600]
            if len(LOGIP[ip]) >= 8: return self.out(429, {'error': 'Majaribio mengi mno. Subiri dakika chache.'})
            if not u['pw_hash'] or not checkpw(str(f.get('old', '')), u['pw_hash']): LOGIP[ip].append(now); return self.out(403, {'error': 'Nenosiri la sasa si sahihi.'})
            cur = tok_hash(self.cookies().get('vs_user', ''))
            with LOCK, db() as c: c.execute('UPDATE users SET pw_hash=? WHERE id=?', (mkpw(str(f['new'])), u['id'])); c.execute('DELETE FROM sessions WHERE user_id=? AND token!=?', (u['id'], cur))  # other devices are logged out
            return self.out(200, {'ok': True})
        if p == '/api/auth/logout':
            with LOCK, db() as c: c.execute('DELETE FROM sessions WHERE token=?', (tok_hash(self.cookies().get('vs_user', '')),))
            return self.out(200, {'ok': True}, hdr=[('Set-Cookie', 'vs_user=; Path=/; HttpOnly; SameSite=Lax; Max-Age=0%s' % ('; Secure' if HTTPS else ''))])
        self.out(404, {'error': 'not found'})
    def form(self, limit=20000):  # public endpoints stay tiny; the logged-in admin may upload big batches of configs
        try: n = max(0, min(int(self.headers.get('Content-Length') or 0), limit))
        except ValueError: n = 0
        self.raw_b = self.rfile.read(n); raw = self.raw_b.decode('utf-8', 'ignore')
        try: return json.loads(raw) if raw.lstrip().startswith('{') else {k: v[0] for k, v in __import__('urllib.parse').parse.parse_qs(raw).items()}
        except Exception: return {}
    def admin(self, s, sub, f, post):
        now = time.time(); ip = (self.headers.get('X-Real-IP') if BIND == '127.0.0.1' else None) or self.client_address[0]
        if sub in ('', '/') and not post: return self.out(200, open(ADMIN_HTML, 'rb').read(), 'text/html')
        if sub == '/api/login' and post:
            if len(FAILS) > 5000: FAILS.clear()
            FAILS[ip] = [t for t in FAILS.get(ip, []) if now - t < 600]; FAILS['*'] = [t for t in FAILS.get('*', []) if now - t < 600]
            if len(FAILS[ip]) >= 5 or len(FAILS['*']) >= 30: return self.out(429, {'error': 'Too many attempts, wait 10 minutes'})
            pw = str(f.get('password', ''))[:200]
            if eq(f.get('user', ''), s['admin_user']) and checkpw(pw, s['admin_hash']):
                for k in [k for k, v in SESS.items() if v < now]: del SESS[k]
                if pw_stale(s['admin_hash']): s['admin_hash'] = mkpw(pw); save(s)
                tok = secrets.token_urlsafe(32); SESS[tok] = now + 43200
                return self.out(200, {'ok': True}, hdr=[('Set-Cookie', 'vs_admin=%s; Path=/%s; HttpOnly; SameSite=Strict; Max-Age=43200%s' % (tok, s['admin_path'], '; Secure' if HTTPS else ''))])
            FAILS[ip].append(now); FAILS['*'].append(now); return self.out(403, {'error': 'Wrong user or password'})
        if SESS.get(self.cookies().get('vs_admin'), 0) < now: return self.out(401, {'error': 'login'})
        if sub.startswith('/api/banner/img/') and not post:
            r = db().execute('SELECT img FROM banners WHERE id=?', (int(sub.rsplit('/', 1)[1]) if sub.rsplit('/', 1)[1].isdigit() else -1,)).fetchone()
            m = re.match(r'data:(image/\w+);base64,(.*)', (r['img'] if r else '') or '', re.S)
            return self.out(200, base64.b64decode(m.group(2)), m.group(1)) if m else self.out(404, {'error': 'none'})
        try:
            if sub == '/api/state':
                purge_now(); c = db(); st = {k: s[k] for k in ('name', 'whatsapp', 'youtube', 'pay_api_url', 'pay_api_key', 'pay_site', 'webhook_secret', 'admin_user', 'login_required')}; st['pay_secret_set'] = 1 if s.get('pay_secret') else 0
                stock = [dict(speed=r[0], d=r[1], vpn=r[2], free=r[3] or 0, total=r[4], exp=r[5] or 0, nx=eat_str(r[6])) for r in c.execute("SELECT speed,duration,COALESCE(vpn,''),SUM(order_token IS NULL AND " + live() + "),COUNT(*),SUM(order_token IS NULL AND NOT " + live() + "),MIN(CASE WHEN order_token IS NULL AND expires>%d THEN expires END) FROM configs GROUP BY 1,2,3" % int(time.time()))]
                orders = [dict(token=r[0], t=time.strftime('%m-%d %H:%M', time.localtime(r[1])), package=r[2], phone=r[3], price=r[4], status=r[5], vpn=VPNS.get(r[6], ''), hwid=r[7] or '') for r in c.execute('SELECT token,created,package,phone,price,status,vpn,hwid FROM orders ORDER BY created DESC LIMIT 300')]
                return self.out(200, dict(version=6, orders_total=c.execute('SELECT COUNT(*) FROM orders').fetchone()[0], **admin_extra(c), settings=st, networks=s['networks'], stock=stock, orders=orders, premium=s['premium'], users=[dict(id=r[5], email=r[0] or '', phone=r[1] or '', name=r[2] or '', t=time.strftime('%m-%d %H:%M', time.localtime(r[3])), orders=r[4], prem=dstr(r[6]) if (r[6] or 0) > time.time() else '') for r in c.execute('SELECT u.email,u.phone,u.name,u.created,(SELECT COUNT(*) FROM orders o WHERE o.user_id=u.id),u.id,u.premium_until FROM users u ORDER BY u.created DESC LIMIT 200')], user_total=c.execute('SELECT COUNT(*) FROM users').fetchone()[0]))
            if sub == '/api/save' and post:
                for k in ('name', 'whatsapp', 'youtube', 'pay_api_url', 'pay_api_key', 'pay_site', 'login_required'):
                    if k in f.get('settings', {}): s[k] = str(f['settings'][k]).strip()[:300]
                if str(f.get('settings', {}).get('pay_secret', '')).strip(): s['pay_secret'] = str(f['settings']['pay_secret']).strip()[:300]  # empty = keep the saved secret
                if 'networks' in f: s['networks'] = clean_networks(f['networks'])
                if 'premium' in f: s['premium'] = clean_premium(f['premium'])
                save(s); return self.out(200, {'ok': True})
            if sub == '/api/configs' and post:
                # 'items' = list of configs (one per uploaded file, any length / multi-line); 'text' = one config per line. No limit on count or length.
                items = [str(x).strip() for x in f['items']] if isinstance(f.get('items'), list) else [l.strip() for l in str(f.get('text', '')).splitlines()]
                items = [x for x in items if x]; n = 0
                ex = str(f.get('expires') or '').strip(); exp = eat_ts(ex) if ex else 0  # valid-until, typed by the admin in EAT
                if ex and not exp: return self.out(400, {'error': 'Wrong date / time'})
                if exp and exp <= now: return self.out(400, {'error': 'That end time (EAT) is already in the past'})
                vpn = str(f.get('vpn') or '') if str(f.get('vpn') or '') in VPNS else ''  # '' = no lock; free offers / eSIM never ask for an ID
                if str(f.get('speed', '')).startswith('offer:'): vpn = ''
                with LOCK, db() as c:
                    for x in items: n += add_config(c, f['speed'], f['duration'], x, vpn, exp, 1 if f.get('autodel') else 0)
                return self.out(200, {'added': n, 'duplicates': len(items) - n})
            if sub == '/api/clearexpired' and post:
                with LOCK, db() as c: c.execute('DELETE FROM configs WHERE speed=? AND duration=? AND order_token IS NULL AND expires>0 AND expires<=?', (f['speed'], f['duration'], int(now)))
                return self.out(200, {'ok': True})
            if sub == '/api/clearstock' and post:
                with LOCK, db() as c: c.execute('DELETE FROM configs WHERE speed=? AND duration=? AND order_token IS NULL', (f['speed'], f['duration']))
                return self.out(200, {'ok': True})
            if sub == '/api/user/password' and post:  # customers can't reset by email, so the admin sets a new password for them
                if pw_err(str(f.get('password', ''))): return self.out(400, {'error': 'Password must be 8-200 characters'})
                with LOCK, db() as c: c.execute('UPDATE users SET pw_hash=? WHERE id=?', (mkpw(str(f['password'])), int(f['id']))); c.execute('DELETE FROM sessions WHERE user_id=?', (int(f['id']),))
                return self.out(200, {'ok': True})
            if sub == '/api/user/premium' and post:  # admin gives / extends / removes Premium for one customer
                uid = int(f['id']); days = int(f.get('days') or 0)
                with LOCK, db() as c:
                    cur = (c.execute('SELECT premium_until FROM users WHERE id=?', (uid,)).fetchone() or [0])[0] or 0
                    until = 0 if f.get('revoke') else max(int(now), int(cur)) + max(1, min(3650, days)) * 86400
                    c.execute('UPDATE users SET premium_until=? WHERE id=?', (until, uid))
                    if until: notify(c, uid, 'Premium 👑', 'Premium yako imewashwa hadi %s.' % dstr(until), 'paid')
                return self.out(200, {'ok': True, 'until': dstr(until)})
            if sub == '/api/user/delete' and post:
                with LOCK, db() as c: c.execute('DELETE FROM sessions WHERE user_id=?', (int(f['id']),)); c.execute('DELETE FROM users WHERE id=?', (int(f['id']),))
                return self.out(200, {'ok': True})
            if sub == '/api/offer/save' and post:
                t, d, ac = str(f.get('title', '')).strip()[:80] or 'New offer', str(f.get('descr', ''))[:2000], 1 if f.get('active') else 0
                try: pr = max(0, int(f.get('price') or 0)) if f.get('kind') == 'esim' else 0  # offers are always FREE; eSIM is PAID
                except Exception: pr = 0
                with LOCK, db() as c:
                    if f.get('id'): oid = int(f['id']); c.execute('UPDATE offers SET title=?,descr=?,price=?,active=?,kind=?,prem=? WHERE id=?', (t, d, pr, ac, 'esim' if f.get('kind') == 'esim' else 'offer', 1 if f.get('prem') else 0, oid))
                    else: oid = c.execute('INSERT INTO offers(title,descr,price,active,created,kind,prem) VALUES(?,?,?,?,?,?,?)', (t, d, pr, ac, int(now), 'esim' if f.get('kind') == 'esim' else 'offer', 1 if f.get('prem') else 0)).lastrowid
                return self.out(200, {'ok': True, 'id': oid})
            if sub == '/api/offer/delete' and post:
                with LOCK, db() as c: c.execute('DELETE FROM offers WHERE id=?', (int(f['id']),)); c.execute('DELETE FROM configs WHERE speed=? AND order_token IS NULL', ('offer:%d' % int(f['id']),))
                return self.out(200, {'ok': True})
            if sub == '/api/banner/save' and post:
                link = str(f.get('link', '')).strip()[:300]; link = link if link.startswith(('#/', 'https://')) else ''
                ps = [x for x in str(f.get('pages', 'all')).split(',') if x in BN_PAGES]; pages = ','.join(ps) if ps and len(ps) < len(BN_PAGES) else 'all'
                bg, fg = (f.get('bg') if HEX.match(str(f.get('bg', ''))) else ''), (f.get('fg') if HEX.match(str(f.get('fg', ''))) else '')
                if not bg: fg = ''
                try: srt = max(-999, min(999, int(f.get('sort') or 0)))
                except Exception: srt = 0
                img = None  # None = leave the picture as it is
                if f.get('img_clear'): img = ''
                elif f.get('img_data'):
                    if len(f['img_data']) > 450000 or not IMG_RE.match(f['img_data']): return self.out(400, {'error': 'Picture must be PNG / JPG / WEBP / GIF under ~300KB'})
                    img = f['img_data']
                v = (str(f.get('title', '')).strip()[:80], str(f.get('text', '')).strip()[:200], f.get('place') if f.get('place') in ('top', 'hero', 'hang', 'popup') else 'top',
                     f.get('color') if f.get('color') in ('gold', 'red', 'black', 'green', 'blue', 'pink') else 'gold', str(f.get('coupon', '')).strip().upper()[:30], 1 if f.get('active') else 0, pdate(f.get('starts')), pdate(f.get('ends'), True),
                     link, str(f.get('btn', '')).strip()[:30], bg, fg, pages, 0 if f.get('anim') in (0, '0', False) else 1, 0 if f.get('dismiss') in (0, '0', False) else 1, srt)
                with LOCK, db() as c:
                    if f.get('id'): bid = int(f['id']); c.execute('UPDATE banners SET title=?,text=?,place=?,color=?,coupon=?,active=?,starts=?,ends=?,link=?,btn=?,bg=?,fg=?,pages=?,anim=?,dismiss=?,sort=? WHERE id=?', v + (bid,))
                    else: bid = c.execute('INSERT INTO banners(title,text,place,color,coupon,active,starts,ends,link,btn,bg,fg,pages,anim,dismiss,sort) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)', v).lastrowid
                    if img is not None: c.execute('UPDATE banners SET img=? WHERE id=?', (img, bid))
                return self.out(200, {'ok': True, 'id': bid})
            if sub == '/api/banner/delete' and post:
                with LOCK, db() as c: c.execute('DELETE FROM banners WHERE id=?', (int(f['id']),))
                return self.out(200, {'ok': True})
            if sub == '/api/coupon/save' and post:
                code = re.sub(r'[^A-Za-z0-9_-]', '', str(f.get('code', ''))).upper()[:30]
                if not code: return self.out(400, {'error': 'Code is required (letters/numbers)'})
                v = (code, 'fix' if f.get('kind') == 'fix' else 'pct', max(0, int(f.get('value') or 0)), 1 if f.get('active') else 0, pdate(f.get('expires'), True), max(0, int(f.get('max_uses') or 0)))
                with LOCK, db() as c:
                    if f.get('id'): c.execute('UPDATE coupons SET code=?,kind=?,value=?,active=?,expires=?,max_uses=? WHERE id=?', v + (int(f['id']),))
                    else: c.execute('INSERT INTO coupons(code,kind,value,active,expires,max_uses) VALUES(?,?,?,?,?,?)', v)
                return self.out(200, {'ok': True})
            if sub == '/api/coupon/delete' and post:
                with LOCK, db() as c: c.execute('DELETE FROM coupons WHERE id=?', (int(f['id']),))
                return self.out(200, {'ok': True})
            if sub == '/api/notify/send' and post:
                t, b, tg = str(f.get('title', '')).strip()[:80], str(f.get('body', '')).strip()[:500], str(f.get('target', '')).strip()
                if not t: return self.out(400, {'error': 'Title is required'})
                with LOCK, db() as c:
                    uid = None
                    if tg and tg.lower() != 'all':
                        u = c.execute('SELECT id FROM users WHERE email=? OR phone=? OR id=?', (norm_email(tg) or '-', norm_phone(tg) or '-', int(tg) if tg.isdigit() and len(tg) < 8 else -1)).fetchone()
                        if not u: return self.out(400, {'error': 'User not found (use email, phone or user id)'})
                        uid = u['id']
                    notify(c, uid, t, b, 'info')
                return self.out(200, {'ok': True})
            if sub == '/api/notify/delete' and post:
                with LOCK, db() as c: c.execute('DELETE FROM notif_read WHERE notif_id=?', (int(f['id']),)); c.execute('DELETE FROM notif_hidden WHERE notif_id=?', (int(f['id']),)); c.execute('DELETE FROM notifs WHERE id=?', (int(f['id']),))
                return self.out(200, {'ok': True})
            if sub == '/api/orders/delete' and post:  # admin: delete chosen orders (tokens) or bulk by age + status; used config rows stay, so they are never sold twice
                toks = [str(t) for t in (f.get('tokens') or [])][:2000]; n = 0
                with LOCK, db() as c:
                    if toks:
                        for t in toks: n += c.execute('DELETE FROM orders WHERE token=?', (t,)).rowcount
                    else:
                        cut = int(time.time()) - max(1, int(f.get('days') or 0)) * 86400  # at least 1 day, so orders still being paid are safe
                        w = {'all': '', 'success': " AND status='success'"}.get(str(f.get('status')), " AND status!='success'")
                        n = c.execute('DELETE FROM orders WHERE created<?' + w, (cut,)).rowcount
                    c.execute("DELETE FROM notifs WHERE kind='pending' AND ref NOT IN (SELECT token FROM orders)")
                return self.out(200, {'deleted': n})
            if sub == '/api/confirm' and post: return self.out(200, {'result': deliver(f.get('token', ''))})
            if sub == '/api/password' and post:
                if not isinstance(f.get('password'), str) or not 10 <= len(f['password']) <= 200: return self.out(400, {'error': 'Password must be 10-200 characters'})
                s['admin_hash'] = mkpw(f['password']); save(s); SESS.clear(); return self.out(200, {'ok': True})
        except Exception as e: print('admin error:', repr(e), flush=True); return self.out(400, {'error': 'Bad request'})
        self.out(404, {'error': 'not found'})
    def do_GET(self):
        s, p = load(), self.path.split('?')[0]
        if p == '/' + s['admin_path'] or p.startswith('/' + s['admin_path'] + '/'): return self.admin(s, p[len(s['admin_path']) + 1:], {}, False)
        if p == '/manifest.webmanifest': return self.out(200, manifest(s), 'application/manifest+json')
        if p == '/sw.js': return self.out(200, SW_JS, 'application/javascript', hdr=[('Service-Worker-Allowed', '/')])
        if p in ('/icon-192.png', '/icon-512.png'): return self.out(200, icon_png(int(p[6:9])), 'image/png')
        if p in ('/', '/index.html'): return self.out(200, open(HTML, 'rb').read() if self.me() else gate_page(s), 'text/html')
        if p == '/api/auth/config': return self.out(200, {'required': login_needed(s)})
        if p == '/api/me':
            u = self.me(); return self.out(200, {'user': pub(u) if u else None})
        if p == '/api/my-orders':
            u = self.me()
            if not u: return self.out(401, {'error': 'login'})
            return self.out(200, {'orders': [dict(token=r[0], t=time.strftime('%Y-%m-%d %H:%M', time.localtime(r[1])), package=r[2], price=r[3], status=r[4], hidden=1 if r[5] else 0) for r in db().execute('SELECT token,created,package,price,status,hidden FROM orders WHERE user_id=? ORDER BY created DESC LIMIT 50', (u['id'],))]})
        if p == '/api/notifications':
            u = self.me()
            if not u: return self.out(401, {'error': 'login'})
            now = int(time.time())
            with LOCK, db() as c:
                for r in c.execute("SELECT token,package FROM orders WHERE user_id=? AND status='pending' AND created<?", (u['id'], now - 300)).fetchall():  # auto-notice for orders waiting too long
                    notify(c, u['id'], 'Oda inasubiri malipo', '%s bado inasubiri malipo. Kama ulishalipa, subiri kidogo; vinginevyo jaribu tena au wasiliana nasi.' % r['package'], 'pending', r['token'])
                rs = c.execute('SELECT n.*, (SELECT 1 FROM notif_read x WHERE x.notif_id=n.id AND x.user_id=?) AS rd FROM notifs n WHERE ((n.user_id IS NULL AND n.created>=?) OR n.user_id=?) AND NOT EXISTS (SELECT 1 FROM notif_hidden h WHERE h.notif_id=n.id AND h.user_id=?) ORDER BY n.created DESC LIMIT 100', (u['id'], u['created'] or 0, u['id'], u['id'])).fetchall()
            items = [dict(id=r['id'], title=r['title'], body=r['body'], kind=r['kind'], t=time.strftime('%m-%d %H:%M', time.localtime(r['created'])), read=1 if r['rd'] else 0) for r in rs]
            return self.out(200, {'items': items, 'unread': sum(1 for i in items if not i['read'])})
        if p == '/api/my-configs':
            u = self.me()
            if not u: return self.out(401, {'error': 'login'})
            items = []
            ES = esim_ids()
            for r in db().execute("SELECT token,created,package,price,config,speed,status,vpn FROM orders WHERE user_id=? AND status IN ('success','need_id') AND COALESCE(hidden,0)=0 ORDER BY created DESC LIMIT 500", (u['id'],)):
                offer = str(r[5] or '').startswith('offer:'); parts = [x.strip() for x in str(r[2]).split(' — ')]
                net, plan, label = (('eSIM' if str(r[5])[6:] in ES else 'Offers'), r[2], '') if offer or len(parts) < 3 else (parts[0], parts[1], ' — '.join(parts[2:]))
                items.append(dict(token=r[0], t=time.strftime('%Y-%m-%d %H:%M', time.localtime(r[1])), package=r[2], price=r[3], config=r[4] or '', kind=('esim' if str(r[5])[6:] in ES else 'offer') if offer else 'package', net=net, plan=plan, label=label, need_id=1 if r[6] == 'need_id' else 0, **(vpn_info(r[7]) if r[6] == 'need_id' else {})))
            return self.out(200, {'items': items})
        if p.startswith('/api/banner-img/'):
            if not self.me(): return self.out(401, {'error': 'login'})
            r = db().execute('SELECT img FROM banners WHERE id=?', (int(p.rsplit('/', 1)[1]) if p.rsplit('/', 1)[1].isdigit() else -1,)).fetchone()
            m = re.match(r'data:(image/\w+);base64,(.*)', (r['img'] if r else '') or '', re.S)
            return self.out(200, base64.b64decode(m.group(2)), m.group(1)) if m else self.out(404, {'error': 'none'})
        if p == '/api/site':
            u = self.me()
            if not u: return self.out(401, {'error': 'login'})
            return self.out(200, site_payload(s, u))
        if p.startswith('/api/order/status/'):
            u = self.me()
            if not u: return self.out(401, {'error': 'login'})
            tk = p.rsplit('/', 1)[1]; o = db().execute('SELECT status,config,user_id,vpn,price FROM orders WHERE token=?', (tk,)).fetchone()
            if not o or o['user_id'] != u['id']: return self.out(404, {'payment_status': 'failed'})
            if o['status'] == 'pending':
                r = pp_poll(s, site_host(s, self.headers.get('Host', '')), tk, o['price'])
                if r == 'ok': deliver(tk)
                elif r == 'failed': fail_order(tk)
                o = db().execute('SELECT status,config,user_id,vpn FROM orders WHERE token=?', (tk,)).fetchone()
            return self.out(200, dict(payment_status=o['status'], config=o['config'] or '', **(vpn_info(o['vpn']) if o['status'] == 'need_id' else {})))
        if p.startswith('/r/'):
            u = self.me()
            if not u: return self.out(302, b'', 'text/html', hdr=[('Location', '/')])
            o = db().execute('SELECT status,config,package,user_id,hidden,speed FROM orders WHERE token=?', (p[3:],)).fetchone()
            if o and (o['user_id'] != u['id'] or o['hidden']): o = None  # someone else's order, or deleted by the customer
            cfg = (o['config'] if o and o['status'] == 'success' else '') or ''
            msg = (esim_html(cfg) if str(o['speed']).startswith('offer:') and str(o['speed'])[6:] in esim_ids() else '<pre style="white-space:pre-wrap;word-break:break-all;background:#111A2E;padding:16px;border-radius:12px">%s</pre>' % hesc(cfg)) if cfg else '<p>Config not available yet (status: %s).</p>' % hesc(o['status'] if o else 'missing')
            return self.out(200, '<meta name=viewport content="width=device-width,initial-scale=1"><body style="background:#0A0F1C;color:#E8EEF8;font-family:sans-serif;max-width:640px;margin:30px auto;padding:0 16px"><h2>%s</h2>%s' % (hesc(s['name']), msg), 'text/html')
        self.out(404, {'error': 'not found'})
    def do_POST(self):
        s, p = load(), self.path.split('?')[0]
        if not self.origin_ok(): return self.out(403, {'error': 'Cross-site request blocked'})
        if len(HITS) > 20000: HITS.clear()
        f = self.form(ADMIN_MAX if p.startswith('/' + s['admin_path'] + '/') else 20000)
        if p.startswith('/' + s['admin_path'] + '/'): return self.admin(s, p[len(s['admin_path']) + 1:], f, True)
        if p.startswith('/api/auth/'): return self.auth(s, p, f)
        if p == '/api/notifications/read':
            u = self.me()
            if not u: return self.out(401, {'error': 'login'})
            with LOCK, db() as c: c.execute('INSERT OR IGNORE INTO notif_read(user_id,notif_id) SELECT ?,id FROM notifs WHERE user_id IS NULL OR user_id=?', (u['id'], u['id']))
            return self.out(200, {'ok': True})
        if p == '/api/notifications/delete':
            u = self.me()
            if not u: return self.out(401, {'error': 'login'})
            with LOCK, db() as c:
                if f.get('all'): c.execute('INSERT OR IGNORE INTO notif_hidden(user_id,notif_id) SELECT ?,id FROM notifs WHERE user_id IS NULL OR user_id=?', (u['id'], u['id']))
                elif str(f.get('id', '')).isdigit(): c.execute('INSERT OR IGNORE INTO notif_hidden VALUES(?,?)', (u['id'], int(f['id'])))
            return self.out(200, {'ok': True})
        if p == '/api/my-configs/delete':
            u = self.me()
            if not u: return self.out(401, {'error': 'login'})
            toks = [str(t)[:60] for t in f.get('tokens', [])[:500]] if isinstance(f.get('tokens'), list) else []
            with LOCK, db() as c:
                for t in toks: c.execute("UPDATE orders SET hidden=1 WHERE token=? AND user_id=? AND status='success'", (t, u['id']))
            return self.out(200, {'ok': True, 'deleted': len(toks)})
        if p == '/api/order/hwid':  # PAID order of a locked VPN app: customer sends HWID / public key -> format checked against that VPN -> config released
            u = self.me()
            if not u: return self.out(401, {'error': 'login'})
            ip, now = self.ip(), time.time(); HITS['h' + ip] = [t for t in HITS.get('h' + ip, []) if now - t < 600] + [now]
            if len(HITS['h' + ip]) > 30: return self.out(429, {'status': 'error', 'message': 'Maombi mengi mno. Subiri dakika chache.'})
            tok, v = str(f.get('token', ''))[:60], str(f.get('hwid', '')).strip()
            with LOCK, db() as c:
                o = c.execute('SELECT * FROM orders WHERE token=? AND user_id=?', (tok, u['id'])).fetchone()
                if not o or o['status'] != 'need_id': return self.out(400, {'status': 'error', 'message': 'Oda hii haihitaji ID.'})
                inf = vpn_info(o['vpn'])
                if not hwid_ok(o['vpn'], v):
                    g = hwid_guess(v); msg = ('ID hii inaonekana ni ya %s, lakini config yako ni ya %s. Weka %s ya %s.' % (g, inf['app'], inf['idn'], inf['app'])) if g else ('%s si sahihi. %s' % (inf['idn'], inf['fmt']))
                    return self.out(400, {'status': 'error', 'message': msg})
                k = c.execute('SELECT content FROM configs WHERE id=? AND order_token=?', (o['cfg_id'], tok)).fetchone()
                if not k: return self.out(400, {'status': 'error', 'message': 'Config haipatikani. Wasiliana nasi.'})
                c.execute("UPDATE orders SET status='success',config=?,hwid=? WHERE token=?", (k['content'], v, tok))
                notify(c, u['id'], 'Config iko tayari ✅', '%s: config yako iko tayari ndani ya "Config Zangu".' % o['package'], 'paid', tok + ':ok')
            return self.out(200, {'status': 'success', 'config': k['content']})
        if p == '/api/coupon':
            cu = self.me()
            if not cu: return self.out(401, {'error': 'login'})
            ip, now = self.ip(), time.time(); HITS['c' + ip] = [t for t in HITS.get('c' + ip, []) if now - t < 600] + [now]
            if len(HITS['c' + ip]) > 30: return self.out(429, {'error': 'Maombi mengi mno. Subiri dakika chache.'})
            with db() as c:
                it = resolve_item(s, c, f, cu)
                if it and it.get('locked'): return self.out(403, {'error': 'Hii ni kwa wanachama wa Premium pekee.'})
                if not it: return self.out(400, {'error': 'Kifurushi hakijapatikana.'})
                newp, k, err = apply_coupon(c, str(f.get('code', '')).strip(), it['price'])
            if err: return self.out(400, {'error': err})
            return self.out(200, {'ok': True, 'price': newp, 'base': it['price'], 'label': ('-%d%%' % k['value']) if k['kind'] == 'pct' else ('-%d/=' % k['value'])})
        if p == '/api/buy-direct':
            me = self.me()
            if not me: return self.out(401, {'status': 'error', 'message': 'Tafadhali ingia kwanza.', 'login': True})
            ip, now = self.ip(), time.time()
            HITS[ip] = [t for t in HITS.get(ip, []) if now - t < 600] + [now]
            if len(HITS[ip]) > 10: return self.out(429, {'status': 'error', 'message': 'Maombi mengi mno. Subiri dakika chache.'})
            with db() as c: it = resolve_item(s, c, f, me)
            if it and it.get('locked'): return self.out(403, {'status': 'error', 'message': 'Hii ni kwa wanachama wa Premium pekee. Pata Premium kwanza.'})
            if not it: return self.out(400, {'status': 'error', 'message': 'Kifurushi hakijapatikana.'})
            phone, code, token = ''.join(ch for ch in str(f.get('phone', '')) if ch.isdigit()), str(f.get('coupon', '')).strip(), secrets.token_urlsafe(16)
            with LOCK, db() as c:
                price, kc = it['price'], None  # price always comes from the server tables, never from the browser
                if code:
                    price, kc, err = apply_coupon(c, code, price)
                    if err: return self.out(400, {'status': 'error', 'message': err})
                if price and (len(phone) != 10 or not phone.startswith('0')):
                    return self.out(400, {'status': 'error', 'message': 'Namba ya simu si sahihi.'})
                if 0 < price < 500: return self.out(400, {'status': 'error', 'message': 'Kiasi cha chini cha malipo ni 500/=.'})
                if price and (c.execute("SELECT COUNT(*) FROM orders WHERE phone=? AND status='pending' AND created>?", (phone, int(now) - 3600)).fetchone()[0] >= 3
                              or c.execute("SELECT COUNT(*) FROM orders WHERE user_id=? AND status='pending' AND created>?", (me['id'], int(now) - 600)).fetchone()[0] >= 5):
                    return self.out(429, {'status': 'error', 'message': 'Una maombi mengi ya malipo yanayosubiri. Subiri dakika chache kisha ujaribu tena.'})
                purge(c)
                if not it['speed'].startswith('member:') and not c.execute('SELECT 1 FROM configs WHERE speed=? AND duration=? AND order_token IS NULL AND ' + live() + ' LIMIT 1', (it['speed'], it['duration'])).fetchone():
                    return self.out(200, {'status': 'error', 'message': ('Ofa hii imeisha kwa sasa.' if not it['price'] else 'eSIM hii imeisha kwa sasa.') if it['speed'].startswith('offer:') else 'Kifurushi hiki kimeisha kwa sasa.'})
                c.execute('INSERT INTO orders(token,created,package,speed,duration,network,phone,price,status,user_id,coupon) VALUES(?,?,?,?,?,?,?,?,?,?,?)', (token, int(now), it['title'], it['speed'], it['duration'], it['network'], phone, price, 'pending', me['id'], kc['code'] if kc else None))
                if kc: c.execute('UPDATE coupons SET used=used+1 WHERE id=?', (kc['id'],))
            if not price:
                st = deliver(token)
                cfg = db().execute('SELECT config FROM orders WHERE token=?', (token,)).fetchone()['config']
                if st != 'success': return self.out(200, {'status': 'error', 'message': 'Config zimeisha kwa sasa.'})
                return self.out(200, {'status': 'success', 'free': True, 'config': cfg, 'redirect': '/r/' + token})
            o = db().execute('SELECT * FROM orders WHERE token=?', (token,)).fetchone()
            err = pay_request(s, token, o, site_host(s, self.headers.get('Host', '')))
            if err:
                fail_order(token, 'Imeshindikana kutuma ombi la malipo')
                return self.out(200, {'status': 'error', 'message': err})
            return self.out(200, {'status': 'success', 'free': False, 'access_token': token, 'redirect': '/r/' + token})
        if eq(p, '/api/webhook/' + s['webhook_secret']):  # provider -> {order_id, status: success|failed}
            if s.get('pay_secret'):  # PeterPay signs every webhook: X-PeterPay-Signature = HMAC-SHA256(raw body, secret key)
                sig = (self.headers.get('X-PeterPay-Signature') or '').strip().lower().replace('sha256=', '')
                if not eq(hmac.new(s['pay_secret'].encode(), getattr(self, 'raw_b', b''), hashlib.sha256).hexdigest(), sig): return self.out(403, {'error': 'Invalid signature'})
            dd = f.get('data') if isinstance(f.get('data'), dict) else {}; g = lambda k: f.get(k) or dd.get(k) or ''
            if str(g('event') or g('type')).startswith('payout'): return self.out(200, {'ok': True})  # payout events are not customer orders
            tok, st = str(g('order_id')), str(g('payment_status') or g('status')).lower()
            if st in ('success', 'completed', 'paid'):  # never trust the webhook alone: PeterPay -> ask its API; others -> amount must cover the price
                o = db().execute('SELECT price FROM orders WHERE token=?', (tok,)).fetchone()
                if o:
                    if pp_on(s): PPLAST.pop(tok, None); ok = pp_poll(s, site_host(s, self.headers.get('Host', '')), tok, o['price']) == 'ok'
                    else:
                        try: amt = float(g('amount') or 0)
                        except Exception: amt = 0
                        ok = not g('amount') or amt >= o['price']
                    if ok: deliver(tok)
            elif st in ('failed', 'cancelled', 'canceled', 'expired'): fail_order(tok)
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
                if x.strip(): n += add_config(c, a[2], a[3], x.strip())
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
        if a[1] not in ('name', 'whatsapp', 'youtube', 'pay_api_url', 'pay_api_key', 'pay_secret', 'pay_site', 'login_required'): sys.exit('unknown key')
        s[a[1]] = a[2]; save(s); print('ok')
    elif a[0] == 'show': print(json.dumps({k: v for k, v in s.items() if k not in ('networks', 'admin_hash', 'pay_secret')}, indent=1)); [print(n['name'], '/', p['id'], [(r['d'], r['price']) for r in p['rows']]) for n, p in allplans(s)]
if __name__ == '__main__': cli(sys.argv[1:] or ['serve'])
