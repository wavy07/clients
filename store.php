<?php
// visibleTech Store - PHP version. Same data as the old Python store (store.db + settings.json), so nothing is lost on upgrade.
// Needs PHP 7.4+ with: sqlite3 (PDO), curl.   Web:  php store.php serve      (runs the built-in PHP web server)
// CLI (used by install.sh menu):  store.php stock | orders | users | confirm TOKEN | addconfigs FILE_OR_FOLDER SPEED DURATION [file] | price SPEED DURATION PRICE | list | ids | edit N FIELD VALUE | plan N FIELD VALUE | admin [reset] | set KEY VALUE | show
error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
ini_set('display_errors', '0'); ini_set('log_errors', '1');
if (!function_exists('mb_substr')) { function mb_substr($s, $a, $n = null) { preg_match('/^.{0,' . (int)$n . '}/su', (string)$s, $m); return isset($m[0]) ? $m[0] : ''; } }
if (!function_exists('mb_strlen')) { function mb_strlen($s) { return (int)preg_match_all('/./su', (string)$s); } }

define('D', getenv('STORE_DIR') ?: '/etc/visibleTech-store');
define('DBF', D . '/store.db'); define('SETF', D . '/settings.json');
define('HTML', getenv('STORE_HTML') ?: D . '/index.html');
define('ADMIN_HTML', getenv('STORE_ADMIN_HTML') ?: D . '/admin.html');
define('APPJS', getenv('STORE_APP_JS') ?: dirname(HTML) . '/app.js');   // the page logic lives here, NOT in index.html: it is handed out only to logged-in customers
define('PORT', (int)(getenv('STORE_PORT') ?: 80));
define('BIND', getenv('STORE_BIND') ?: '0.0.0.0');   // 127.0.0.1 when nginx (domain + HTTPS) sits in front
define('HTTPS', BIND === '127.0.0.1');
define('ADMIN_MAX', 20 * 1024 * 1024);
define('EAT', 3 * 3600);   // East Africa Time (Tanzania) = UTC+3, no daylight saving; admin times are always typed / shown in EAT
define('PW_ITER', 600000);
const MAKE_WAIT = 15;   // seconds the customer waits after sending the ID before the config is released (enforced by the SERVER, not just shown on the screen)
const VPNS = ['ssh' => 'SSH Custom', 'http' => 'HTTP Custom', 'inj' => 'HTTP Injector', 'npv' => 'NPV Tunnel', 'htw' => 'HTTP Tweak', 'ttw' => 'Tunnel Tweak'];
const BN_PAGES = ['home', 'offers', 'packages', 'configs', 'notifs', 'help'];
const HEX_RE = '/^#[0-9a-fA-F]{6}$/D';
const IMG_RE = '/^data:image\/(png|jpeg|webp|gif);base64,[A-Za-z0-9+\/=]+$/D';
const EMAIL_RE = '/^[^@\s]{1,64}@[^@\s]{1,200}\.[^@\s.]{2,}$/D';
$__tz = trim((string)@file_get_contents('/etc/timezone'));
if ($__tz === '' || !@date_default_timezone_set($__tz)) date_default_timezone_set('UTC');

function csp() {   // customer site + login page: NO inline scripts at all. Only the admin panel (secret path + login) still needs its inline handlers.
    $inl = !empty($GLOBALS['CSP_ADMIN']) ? " 'unsafe-inline'" : '';
    return "default-src 'self'; script-src 'self'" . $inl . "; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com https://cdnjs.cloudflare.com; "
        . "font-src 'self' https://fonts.gstatic.com https://cdnjs.cloudflare.com; img-src 'self' data: blob:; connect-src 'self'; manifest-src 'self'; worker-src 'self'; "
        . "object-src 'none'; base-uri 'none'; form-action 'self'; frame-ancestors 'none'";
}
function sec_hdr() {
    $h = [['Content-Security-Policy', csp()], ['X-Content-Type-Options', 'nosniff'], ['X-Frame-Options', 'DENY'], ['Referrer-Policy', 'same-origin'],
          ['Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=()'], ['Cross-Origin-Opener-Policy', 'same-origin']];
    if (HTTPS) $h[] = ['Strict-Transport-Security', 'max-age=31536000'];
    return $h;
}

// ---------------- small helpers ----------------
function eq($a, $b) { return hash_equals((string)$a, (string)$b); }   // constant-time compare
function hesc($t) { return htmlspecialchars((string)$t, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function tok_hash($t) { return hash('sha256', (string)$t); }          // session tokens are stored only as hashes
function rand_url($n) { return rtrim(strtr(base64_encode(random_bytes($n)), '+/', '-_'), '='); }
function cut($v, $n) { return mb_substr((string)$v, 0, $n); }
function tr($v) { if (is_string($v)) return $v !== ''; if (is_array($v)) return count($v) > 0; return (bool)$v; }   // same truthiness the Python store used
function fk($f, $k) { if (!is_array($f) || !array_key_exists($k, $f)) throw new Exception('missing ' . $k); return $f[$k]; }
function pw_err($pw) { $n = mb_strlen($pw); return ($n >= 8 && $n <= 200) ? null : 'Nenosiri liwe na herufi 8 hadi 200.'; }
function dstr($ts) { return $ts ? date('Y-m-d', (int)$ts) : ''; }
function pdate($t, $end = false) {
    $t = trim((string)$t);
    if (!preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})$/', $t, $m) || !checkdate((int)$m[2], (int)$m[3], (int)$m[1])) return 0;
    return mktime(0, 0, 0, (int)$m[2], (int)$m[3], (int)$m[1]) + ($end ? 86399 : 0);
}
function eat_ts($t) {   // 'YYYY-MM-DDTHH:MM' typed by the admin in EAT -> unix time
    $t = substr(str_replace(' ', 'T', trim((string)$t)), 0, 16);
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2})$/', $t, $m)) return 0;
    if ((int)$m[2] < 1 || (int)$m[2] > 12 || (int)$m[3] < 1 || (int)$m[3] > 31 || (int)$m[4] > 23 || (int)$m[5] > 59) return 0;
    return gmmktime((int)$m[4], (int)$m[5], 0, (int)$m[2], (int)$m[3], (int)$m[1]) - EAT;
}
function eat_str($ts) { return $ts ? gmdate('Y-m-d H:i', (int)$ts + EAT) : ''; }
function site_host($s, $hdr) {   // host used for payment callbacks: NEVER blindly trust the Host header (Host-header injection would leak the webhook secret)
    $h = trim((string)($s['pay_site'] ?? ''));
    if ($h === '') $h = trim((string)@file_get_contents(D . '/domain'));
    if ($h === '') { $h = substr((string)$hdr, 0, 100); if (!preg_match('/\A[A-Za-z0-9.-]+(:\d{1,5})?\z/', $h)) $h = ''; }
    return $h;
}

// ---------------- settings ----------------
function rows() { $o = []; foreach (func_get_args() as $x) $o[] = ['d' => $x[0], 'label' => $x[1], 'small' => $x[2], 'price' => $x[3], 'best' => $x[4]]; return $o; }
function defaults() {
    $d = ['name' => 'visibleTech', 'whatsapp' => '255719554187', 'youtube' => '',
        'webhook_secret' => '', 'login_required' => '0', 'admin_user' => 'admin', 'admin_path' => '', 'admin_hash' => '', 'pay_api_url' => '', 'pay_api_key' => '', 'pay_secret' => '', 'pay_api_secret' => '', 'pay_live' => '0', 'pay_site' => '',
        'stock_ttl_hours' => 0, 'stock_daily_clear' => '0', 'stock_clear_hour' => 0, 'videos' => [],
        'networks' => [['id' => 'halotel', 'name' => 'Halotel', 'plans' => [
            ['id' => 'middle', 'cls' => 'mid', 'title' => 'Middle Speed', 'range' => '700Kbps – 1.9MBps', 'footer' => 'Unlimited downloads and uploads. One or two devices.',
             'rows' => rows(['wiki1', '7 Days', '1 Week', 2000, 0], ['wiki2', '14 Days', '2 Weeks', 4000, 1], ['mwezi1', '30 Days', '1 Month', 8000, 0])],
            ['id' => 'normal', 'cls' => 'nor', 'title' => 'Normal Speed', 'range' => '100Kbps – 700Kbps', 'footer' => 'Affordable for everyday use. Unlimited data.',
             'rows' => rows(['wiki1', '7 Days', '1 Week', 1500, 0], ['wiki2', '14 Days', '2 Weeks', 3000, 0], ['mwezi1', '30 Days', '1 Month', 6000, 0])]]]]];
    $d['premium'] = ['on' => 1, 'title' => 'Premium', 'perks' => ['Vifurushi vya bei nafuu unavyoweza kumuuzia mtu mwingine kwa faida', 'Ofa mpya za kila baada ya muda', 'Faida nyingi kwenye eSIM: eSIM za kipekee kwa bei nafuu', 'Huduma nyingi za ubora na bei nafuu'],
        'plans' => [['id' => 'm30', 'label' => 'Siku 30', 'days' => 30, 'price' => 5000]]];
    return $d;
}
function load() {
    $s = json_decode((string)@file_get_contents(SETF), true); if (!is_array($s)) $s = [];
    if (!isset($s['networks']) && isset($s['plans'])) {   // old single-network settings -> new multi-network layout
        $s['networks'] = [['id' => 'halotel', 'name' => ucwords(strtolower(trim((string)(!empty($s['network']) ? $s['network'] : 'Halotel')))), 'plans' => $s['plans']]]; unset($s['plans']);
    }
    foreach (defaults() as $k => $v) if (!array_key_exists($k, $s)) $s[$k] = $v;
    if (!$s['webhook_secret'] || !$s['admin_path']) {
        $s['webhook_secret'] = $s['webhook_secret'] ?: rand_url(18); $s['admin_path'] = $s['admin_path'] ?: 'a_' . bin2hex(random_bytes(6)); save($s);
    }
    return $s;
}
function save($s) {
    if (!is_dir(D)) @mkdir(D, 0700, true);
    @chmod(D, 0700); $t = SETF . '.tmp';
    file_put_contents($t, json_encode($s, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)); @chmod($t, 0600); rename($t, SETF);
}

// ---------------- database ----------------
function db() {
    static $c = null; if ($c) return $c;
    if (!is_dir(D)) @mkdir(D, 0700, true);
    $c = new PDO('sqlite:' . DBF);
    $c->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION); $c->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $c->exec('PRAGMA busy_timeout=20000');
    @chmod(D, 0700); @chmod(DBF, 0600);
    if ((int)$c->query('PRAGMA user_version')->fetchColumn() < 5) {   // schema + old-database upgrades run once
        $c->exec("CREATE TABLE IF NOT EXISTS orders(token TEXT PRIMARY KEY, created INT, package TEXT, speed TEXT, duration TEXT,
          network TEXT, phone TEXT, price INT, status TEXT, config TEXT, user_id INT);
          CREATE TABLE IF NOT EXISTS configs(id INTEGER PRIMARY KEY, speed TEXT, duration TEXT, content TEXT, order_token TEXT);
          CREATE TABLE IF NOT EXISTS users(id INTEGER PRIMARY KEY, email TEXT UNIQUE, phone TEXT UNIQUE, name TEXT, google_sub TEXT, created INT, last_login INT);
          CREATE TABLE IF NOT EXISTS sessions(token TEXT PRIMARY KEY, user_id INT, expires INT);
          CREATE TABLE IF NOT EXISTS offers(id INTEGER PRIMARY KEY, title TEXT, descr TEXT, price INT, active INT DEFAULT 1, created INT);
          CREATE TABLE IF NOT EXISTS banners(id INTEGER PRIMARY KEY, title TEXT, text TEXT, place TEXT, color TEXT, coupon TEXT, active INT DEFAULT 1, starts INT DEFAULT 0, ends INT DEFAULT 0);
          CREATE TABLE IF NOT EXISTS coupons(id INTEGER PRIMARY KEY, code TEXT UNIQUE, kind TEXT, value INT, active INT DEFAULT 1, expires INT DEFAULT 0, max_uses INT DEFAULT 0, used INT DEFAULT 0);
          CREATE TABLE IF NOT EXISTS notifs(id INTEGER PRIMARY KEY, user_id INT, title TEXT, body TEXT, kind TEXT, ref TEXT, created INT);
          CREATE TABLE IF NOT EXISTS notif_read(user_id INT, notif_id INT, PRIMARY KEY(user_id, notif_id));
          CREATE TABLE IF NOT EXISTS notif_hidden(user_id INT, notif_id INT, PRIMARY KEY(user_id, notif_id));
          CREATE TABLE IF NOT EXISTS rl(k TEXT, t REAL);
          CREATE TABLE IF NOT EXISTS notif_files(id INTEGER PRIMARY KEY, notif_id INT, fname TEXT, ftype TEXT, fsize INT DEFAULT 0);
          CREATE INDEX IF NOT EXISTS nf_n ON notif_files(notif_id);
          CREATE INDEX IF NOT EXISTS rl_k ON rl(k, t);
          CREATE TABLE IF NOT EXISTS admin_sess(token TEXT PRIMARY KEY, expires INT);");
        foreach (['ALTER TABLE orders ADD COLUMN user_id INT', "ALTER TABLE offers ADD COLUMN kind TEXT DEFAULT 'offer'", 'ALTER TABLE users ADD COLUMN pw_hash TEXT', 'ALTER TABLE orders ADD COLUMN coupon TEXT', 'ALTER TABLE orders ADD COLUMN hidden INT DEFAULT 0',
                  'ALTER TABLE banners ADD COLUMN link TEXT', 'ALTER TABLE banners ADD COLUMN btn TEXT', 'ALTER TABLE banners ADD COLUMN bg TEXT', 'ALTER TABLE banners ADD COLUMN fg TEXT',
                  'ALTER TABLE banners ADD COLUMN img TEXT', 'ALTER TABLE banners ADD COLUMN pages TEXT', 'ALTER TABLE banners ADD COLUMN anim INT DEFAULT 1', 'ALTER TABLE banners ADD COLUMN dismiss INT DEFAULT 1', 'ALTER TABLE banners ADD COLUMN sort INT DEFAULT 0',
                  "ALTER TABLE configs ADD COLUMN vpn TEXT DEFAULT ''", 'ALTER TABLE configs ADD COLUMN expires INT DEFAULT 0', 'ALTER TABLE configs ADD COLUMN autodel INT DEFAULT 0',
                  'ALTER TABLE orders ADD COLUMN vpn TEXT', 'ALTER TABLE orders ADD COLUMN hwid TEXT', 'ALTER TABLE orders ADD COLUMN cfg_id INT', 'ALTER TABLE users ADD COLUMN premium_until INT DEFAULT 0', 'ALTER TABLE offers ADD COLUMN prem INT DEFAULT 0',
                  'ALTER TABLE configs ADD COLUMN created INT DEFAULT 0', 'ALTER TABLE orders ADD COLUMN ready_at INT DEFAULT 0',
                  'ALTER TABLE coupons ADD COLUMN scope TEXT', 'ALTER TABLE coupons ADD COLUMN per_user INT DEFAULT 0', 'ALTER TABLE coupons ADD COLUMN min_price INT DEFAULT 0'] as $alt) {
            try { $c->exec($alt); } catch (PDOException $e) {}
        }
        migrate_configs($c);
        $c->exec('UPDATE configs SET created=' . time() . ' WHERE COALESCE(created,0)=0');   // stock that existed before this update: its real upload time is unknown, so it counts from now
        $c->exec('PRAGMA user_version=5');
    }
    if ((int)$c->query('PRAGMA user_version')->fetchColumn() < 6) {   // v6: scheduled stock, config descriptions, customer messages
        $c->exec("CREATE TABLE IF NOT EXISTS msgs(id INTEGER PRIMARY KEY, user_id INT, body TEXT, created INT, seen INT DEFAULT 0, replied INT DEFAULT 0);
          CREATE INDEX IF NOT EXISTS msgs_c ON msgs(created);");
        foreach (['ALTER TABLE configs ADD COLUMN starts INT DEFAULT 0', 'ALTER TABLE configs ADD COLUMN descr TEXT', 'ALTER TABLE configs ADD COLUMN showd INT DEFAULT 0', 'ALTER TABLE orders ADD COLUMN cdescr TEXT'] as $alt) {
            try { $c->exec($alt); } catch (PDOException $e) {}
        }
        $c->exec('PRAGMA user_version=6');
    }
    if ((int)$c->query('PRAGMA user_version')->fetchColumn() < 7) {   // v7: offers can be shared by many people, limited in people, or restricted to chosen accounts
        foreach (["ALTER TABLE offers ADD COLUMN dl_mode TEXT DEFAULT 'once'", 'ALTER TABLE offers ADD COLUMN dl_max INT DEFAULT 0', 'ALTER TABLE offers ADD COLUMN dl_users TEXT'] as $alt) {
            try { $c->exec($alt); } catch (PDOException $e) {}
        }
        $c->exec('PRAGMA user_version=7');
    }
    if ((int)$c->query('PRAGMA user_version')->fetchColumn() < 8) {   // v8: banner look (style / effect), popup frequency + delay, audience, countdown
        foreach (["ALTER TABLE banners ADD COLUMN style TEXT DEFAULT ''", "ALTER TABLE banners ADD COLUMN fx TEXT DEFAULT ''", "ALTER TABLE banners ADD COLUMN freq TEXT DEFAULT 'session'", 'ALTER TABLE banners ADD COLUMN delay INT DEFAULT 0', "ALTER TABLE banners ADD COLUMN aud TEXT DEFAULT 'all'", 'ALTER TABLE banners ADD COLUMN cd INT DEFAULT 0'] as $alt) {
            try { $c->exec($alt); } catch (PDOException $e) {}
        }
        $c->exec('PRAGMA user_version=8');
    }
    if ((int)$c->query('PRAGMA user_version')->fetchColumn() < 9) {   // v9: every offer / eSIM can carry its own how-to video link
        try { $c->exec('ALTER TABLE offers ADD COLUMN video TEXT'); } catch (PDOException $e) {}
        $c->exec('PRAGMA user_version=9');
    }
    return $c;
}
function migrate_configs($c) {   // old databases had content TEXT UNIQUE (global): rebuild the table without it (all stock + orders kept)
    $sql = (string)$c->query("SELECT sql FROM sqlite_master WHERE type='table' AND name='configs'")->fetchColumn();
    if (preg_match('/content\s+TEXT\s+UNIQUE/i', $sql)) {
        $c->exec("BEGIN; DROP TABLE IF EXISTS configs_new;
          CREATE TABLE configs_new(id INTEGER PRIMARY KEY, speed TEXT, duration TEXT, content TEXT, order_token TEXT, vpn TEXT DEFAULT '', expires INT DEFAULT 0, autodel INT DEFAULT 0, created INT DEFAULT 0);
          INSERT INTO configs_new(id,speed,duration,content,order_token,vpn,expires,autodel,created) SELECT id,speed,duration,content,order_token,COALESCE(vpn,''),COALESCE(expires,0),COALESCE(autodel,0),COALESCE(created,0) FROM configs;
          DROP TABLE configs; ALTER TABLE configs_new RENAME TO configs; COMMIT;");
    }
    $c->exec('CREATE INDEX IF NOT EXISTS cfg_pick ON configs(speed, duration, order_token)');
}
function q($sql, $p = []) { $st = db()->prepare($sql); $st->execute($p); return $st; }
function qa($sql, $p = []) { return q($sql, $p)->fetchAll(); }
function qn($sql, $p = []) { return q($sql, $p)->fetchAll(PDO::FETCH_NUM); }
function q1($sql, $p = []) { $r = q($sql, $p)->fetch(); return $r ?: null; }
function qv($sql, $p = []) { $r = q($sql, $p)->fetch(PDO::FETCH_NUM); return $r ? $r[0] : null; }
function lock_on() { $fh = fopen(D . '/.lock', 'c'); flock($fh, LOCK_EX); return $fh; }   // one writer at a time (also across the web workers)
function lock_off($fh) { flock($fh, LOCK_UN); fclose($fh); }
function with_lock($fn) { $fh = lock_on(); try { return $fn(); } finally { lock_off($fh); } }
function tx($fn) { $c = db(); $c->beginTransaction(); try { $r = $fn(); $c->commit(); return $r; } catch (\Throwable $e) { if ($c->inTransaction()) $c->rollBack(); throw $e; } }
// rate limits are kept in the database (PHP forgets everything between requests)
function rl_count($k, $win) { if (mt_rand(1, 40) === 1) q('DELETE FROM rl WHERE t<?', [microtime(true) - 7200]); return (int)qv('SELECT COUNT(*) FROM rl WHERE k=? AND t>?', [$k, microtime(true) - $win]); }
function rl_add($k) { q('INSERT INTO rl(k,t) VALUES(?,?)', [$k, microtime(true)]); }

function add_config($speed, $dur, $content, $vpn = '', $exp = 0, $autodel = 0, $starts = 0, $descr = '', $showd = 0) {   // 1 = added; 0 = same config already waiting UNSOLD in this package
    return q('INSERT INTO configs(speed,duration,content,vpn,expires,autodel,created,starts,descr,showd) SELECT ?,?,?,?,?,?,?,?,?,? WHERE NOT EXISTS (SELECT 1 FROM configs WHERE speed=? AND duration=? AND content=? AND order_token IS NULL)',
             [$speed, $dur, $content, $vpn, $exp, $autodel, time(), $starts, $descr, $showd, $speed, $dur, $content])->rowCount();
}
function live() { $n = time(); return '(COALESCE(starts,0)<=' . $n . ' AND (COALESCE(expires,0)=0 OR expires>' . $n . '))'; }   // SQL: config can be bought NOW (its "available from" time has come and it has not ended)
function expd() { return '(COALESCE(expires,0)>0 AND expires<=' . time() . ')'; }   // SQL: past its end time
function pend() { return '(COALESCE(starts,0)>' . time() . ')'; }   // SQL: scheduled - not visible yet
function cnote($k) { return ((int)($k['showd'] ?? 0) === 1) ? (string)($k['descr'] ?? '') : ''; }   // description the customer may see (only when the admin ticked "show to customer")
function clean_cut() {   // unix time of the most recent daily clean-up moment (EAT clock), or 0 when the daily clean-up is off
    $s = load(); if ((string)($s['stock_daily_clear'] ?? '0') !== '1') return 0;
    $h = max(0, min(23, (int)($s['stock_clear_hour'] ?? 0))); $now = time();
    $loc = $now + EAT; $cut = ($loc - ($loc % 86400)) + $h * 3600 - EAT;
    return $cut > $now ? $cut - 86400 : $cut;
}
function purge() {   // unsold stock disappears by itself: (1) batches with an end time + auto-delete, (2) the optional daily clean-up of yesterday's package stock. SOLD / reserved configs are never touched.
    q('DELETE FROM configs WHERE order_token IS NULL AND COALESCE(autodel,0)=1 AND expires>0 AND expires<=?', [time()]);
    $c = clean_cut();
    if ($c) q("DELETE FROM configs WHERE order_token IS NULL AND speed NOT LIKE 'offer:%' AND MAX(COALESCE(created,0),COALESCE(starts,0))<?", [$c]);
}
function purge_now() { with_lock('purge'); }

// ---------------- VPN app lock (HWID / public key) ----------------
function hwid_ok($vpn, $v) {
    $v = (string)$v;
    if ($vpn === 'ssh' || $vpn === 'http') return (bool)preg_match('/\A[a-z0-9]{32}\z/', $v);   // 32 small letters + numbers
    if ($vpn === 'inj') return (bool)preg_match('/\A[A-Z0-9]{32}\z/', $v);                      // 32 CAPITAL letters + numbers
    if ($vpn === 'npv') return preg_match('/\A[A-Za-z0-9_+\/=-]{40,64}\z/', $v) && preg_match('/[a-z]/', $v) && preg_match('/[A-Z]/', $v);
    if ($vpn === 'htw') return (bool)preg_match('/\A[A-F0-9]{32}\z/', $v);                      // HTTP Tweak: 32 CAPITAL hex (A97B22431402F701A4F5C5100AE7BA30)
    if ($vpn === 'ttw') return (bool)preg_match('/\A[a-f0-9]{16}\z/', $v);                      // Tunnel Tweak: 16 small hex (e6693c994e850b75)
    return false;
}
function hwid_guess($v) { return hwid_ok('ssh', $v) ? 'SSH Custom / HTTP Custom' : (hwid_ok('htw', $v) ? 'HTTP Injector / HTTP Tweak' : (hwid_ok('inj', $v) ? 'HTTP Injector' : (hwid_ok('ttw', $v) ? 'Tunnel Tweak' : (hwid_ok('npv', $v) ? 'NPV Tunnel' : '')))); }
// ---------------- how-to video links (set in the admin panel; shown to customers as "Angalia video") ----------------
function vid_url($u) { $u = trim((string)$u); return (strlen($u) <= 300 && preg_match('#^https?://[^\s<>"\']+$#i', $u)) ? $u : ''; }   // only plain http(s) links ever reach the customer
function vid_keys() { $k = ['pay', 'coupon', 'premium']; foreach (array_keys(VPNS) as $v) { $k[] = 'hwid_' . $v; $k[] = 'imp_' . $v; } return $k; }   // pay / coupon / premium + per VPN: hwid_<vpn> (how to get the ID) and imp_<vpn> (how to import the config)
function vid_get($k) { static $v = null; if ($v === null) { $v = load()['videos'] ?? []; if (!is_array($v)) $v = []; } return vid_url($v[$k] ?? ''); }
function vid_clean($in) { $o = []; if (is_array($in)) foreach (vid_keys() as $k) if (isset($in[$k])) { $u = vid_url($in[$k]); if ($u !== '') $o[$k] = $u; } return $o; }
function vpn_info($vpn) {
    if (!isset(VPNS[(string)$vpn])) return [];
    return ['vpn' => $vpn, 'app' => VPNS[$vpn], 'idn' => $vpn === 'npv' ? 'Public Key' : 'HWID', 'hv' => vid_get('hwid_' . $vpn), 'iv' => vid_get('imp_' . $vpn)];   // no format hint is ever shown to the customer; hv = video "how to get the ID", iv = video "how to import the config"
}

// ---------------- passwords (same format as before, so every existing password keeps working) ----------------
function _pbk($pw, $salt, $it) { return hash_pbkdf2('sha256', $pw, $salt, $it, 0, false); }
function mkpw($pw) { $salt = bin2hex(random_bytes(16)); return 'p2$' . PW_ITER . '$' . $salt . '$' . _pbk($pw, $salt, PW_ITER); }
function checkpw($pw, $h) {   // understands new 'p2$iter$salt$hash' and the old 'salt$hash' (100k) format
    try {
        $h = (string)$h; $pw = mb_substr((string)$pw, 0, 200); $x = explode('$', $h);
        if (strpos($h, 'p2$') === 0) { if (count($x) !== 4) return false; $it = (int)$x[1]; $salt = $x[2]; $hx = $x[3]; }
        else { if (count($x) !== 2) return false; $salt = $x[0]; $hx = $x[1]; $it = 100000; }
        return $it >= 1000 && $it <= 5000000 && $salt !== '' && hash_equals(_pbk($pw, $salt, $it), $hx);
    } catch (\Throwable $e) { return false; }
}
function pw_stale($h) { return strpos((string)$h, 'p2$' . PW_ITER . '$') !== 0; }
function dummy_check($pw) { static $d = null; if (!$d) $d = mkpw('dummy-password'); checkpw($pw, $d); }   // same cost for unknown accounts

// ---------------- what the admin panel sends ----------------
function clean_networks($nl) {
    $out = []; $seen = [];
    foreach (array_slice(is_array($nl) ? $nl : [], 0, 20) as $n) {
        if (!is_array($n)) continue;
        $plans = [];
        foreach (array_slice(is_array($n['plans'] ?? null) ? $n['plans'] : [], 0, 12) as $p) {
            $pid = preg_replace('/[^A-Za-z0-9_-]/', '', trim(cut($p['id'] ?? '', 20)));
            if ($pid === '' || isset($seen[$pid])) $pid = 'p' . bin2hex(random_bytes(3));
            $seen[$pid] = 1; $rs = []; $rseen = [];
            foreach (array_slice(is_array($p['rows'] ?? null) ? $p['rows'] : [], 0, 30) as $r) {
                $d = preg_replace('/[^A-Za-z0-9_-]/', '', trim(cut($r['d'] ?? '', 20)));
                if ($d === '' || isset($rseen[$d])) $d = 'd' . bin2hex(random_bytes(2));
                $rseen[$d] = 1;
                $rs[] = ['d' => $d, 'label' => cut($r['label'] ?? '', 40), 'small' => cut($r['small'] ?? '', 40), 'price' => max(0, (int)($r['price'] ?? 0)), 'best' => tr($r['best'] ?? 0) ? 1 : 0];
            }
            $cls = $p['cls'] ?? '';
            $plans[] = ['id' => $pid, 'cls' => in_array($cls, ['mid', 'nor', 'gld', 'grn'], true) ? $cls : 'nor', 'title' => cut($p['title'] ?? '', 40),
                        'range' => cut($p['range'] ?? '', 40), 'footer' => cut($p['footer'] ?? '', 160), 'rows' => $rs];
        }
        $nid = preg_replace('/[^A-Za-z0-9_-]/', '', trim(cut($n['id'] ?? '', 20))); if ($nid === '') $nid = 'n' . bin2hex(random_bytes(3));
        $nm = cut($n['name'] ?? '', 40);
        $out[] = ['id' => $nid, 'name' => $nm !== '' ? $nm : 'Network', 'prem' => tr($n['prem'] ?? 0) ? 1 : 0, 'plans' => $plans];
    }
    return $out;
}
function clean_premium($p) {
    $p = is_array($p) ? $p : []; $plans = []; $seen = [];
    foreach (array_slice(is_array($p['plans'] ?? null) ? $p['plans'] : [], 0, 8) as $x) {
        $mid = substr(preg_replace('/[^A-Za-z0-9]/', '', (string)($x['id'] ?? '')), 0, 12);
        if ($mid === '' || isset($seen[$mid])) $mid = 'm' . bin2hex(random_bytes(3));
        $seen[$mid] = 1;
        $days = (int)($x['days'] ?? 0); if (!$days) $days = 30; $days = max(1, min(3650, $days)); $price = max(0, (int)($x['price'] ?? 0));
        $lb = cut($x['label'] ?? '', 40);
        $plans[] = ['id' => $mid, 'label' => $lb !== '' ? $lb : $days . ' days', 'days' => $days, 'price' => $price];
    }
    $perks = [];
    foreach (array_slice(is_array($p['perks'] ?? null) ? $p['perks'] : [], 0, 8) as $t) { $t = cut(trim((string)$t), 90); if ($t !== '') $perks[] = $t; }
    $title = cut(trim((string)($p['title'] ?? '')), 30);
    return ['on' => tr($p['on'] ?? 0) ? 1 : 0, 'title' => $title !== '' ? $title : 'Premium', 'perks' => $perks, 'plans' => $plans];
}
function find_row($s, $speed, $dur) {   // -> [plan, row, network] or [null, null, null]
    foreach ($s['networks'] as $n) foreach ($n['plans'] as $p) if ($p['id'] === (string)$speed) foreach ($p['rows'] as $r) if ($r['d'] === (string)$dur) return [$p, $r, $n];
    return [null, null, null];
}


// ---------------- notification attachments / direct configs / user lookup (admin) ----------------
const NF_MAX = 8 * 1024 * 1024; const NF_TOTAL = 12 * 1024 * 1024; const NF_COUNT = 5;
function find_user($tg) {   // email / phone / user id -> users row or null
    $tg = trim((string)$tg); if ($tg === '') return null;
    return q1('SELECT * FROM users WHERE email=? OR phone=? OR id=?', [norm_email($tg) ?: '-', norm_phone($tg) ?: '-', (ctype_digit($tg) && strlen($tg) < 8) ? (int)$tg : -1]);
}
function nf_prepare($files) {   // validate what the admin attached BEFORE anything is saved -> [[name, mime, raw], ...]; out(400) on a problem
    $o = []; $tot = 0;
    if (!is_array($files)) return $o;
    foreach (array_slice($files, 0, NF_COUNT) as $x) {
        if (!is_array($x)) continue;
        $d = (string)($x['data'] ?? ''); if (preg_match('#^data:[^;,]*;base64,#', $d, $m)) $d = substr($d, strlen($m[0]));
        $raw = base64_decode($d, true); if ($raw === false || $raw === '') continue;
        if (strlen($raw) > NF_MAX) out(400, ['error' => 'A file is bigger than 8 MB']);
        $tot += strlen($raw); if ($tot > NF_TOTAL) out(400, ['error' => 'Files together are bigger than 12 MB']);
        $name = trim(preg_replace('/[^A-Za-z0-9._ -]+/', '_', basename(str_replace('\\', '/', (string)($x['name'] ?? 'file')))), ' .'); if ($name === '') $name = 'file';
        $h = substr($raw, 0, 12); $mime = 'application/octet-stream';   // type is decided from the bytes, never from what the browser says; only real pictures are shown inline
        if (strpos($h, "\x89PNG") === 0) $mime = 'image/png'; elseif (strpos($h, "\xFF\xD8\xFF") === 0) $mime = 'image/jpeg'; elseif (strpos($h, 'GIF8') === 0) $mime = 'image/gif'; elseif (strpos($h, 'RIFF') === 0 && substr($h, 8, 4) === 'WEBP') $mime = 'image/webp';
        $o[] = [mb_substr($name, 0, 80), $mime, $raw];
    }
    return $o;
}
function nf_store($nid, $prep) {
    $dir = D . '/nf'; if (!is_dir($dir)) @mkdir($dir, 0700, true);
    foreach ($prep as $x) {
        q('INSERT INTO notif_files(notif_id,fname,ftype,fsize) VALUES(?,?,?,?)', [$nid, $x[0], $x[1], strlen($x[2])]);
        $fid = (int)db()->lastInsertId(); file_put_contents($dir . '/' . $fid, $x[2]); @chmod($dir . '/' . $fid, 0600);
    }
}
function nf_purge($nid) {
    foreach (qa('SELECT id FROM notif_files WHERE notif_id=?', [$nid]) as $r) @unlink(D . '/nf/' . (int)$r['id']);
    q('DELETE FROM notif_files WHERE notif_id=?', [$nid]);
}
function users_page($qq, $off, $lim) {   // -> ['users' => [...], 'total' => n]; $qq searches name / email / phone / id
    $w = ''; $p = [];
    $qq = trim((string)$qq);
    if ($qq !== '') {
        $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $qq) . '%';
        $w = " WHERE u.email LIKE ? ESCAPE '\\' OR u.phone LIKE ? ESCAPE '\\' OR u.name LIKE ? ESCAPE '\\' OR u.phone=? OR CAST(u.id AS TEXT)=?";
        $p = [$like, $like, $like, norm_phone($qq) ?: '-', $qq];
    }
    $total = (int)qv('SELECT COUNT(*) FROM users u' . $w, $p); $users = [];
    foreach (qn('SELECT u.email,u.phone,u.name,u.created,(SELECT COUNT(*) FROM orders o WHERE o.user_id=u.id),u.id,u.premium_until,u.last_login,(SELECT COALESCE(SUM(o.price),0) FROM orders o WHERE o.user_id=u.id AND o.status=\'success\') FROM users u' . $w . ' ORDER BY u.created DESC LIMIT ' . (int)$lim . ' OFFSET ' . (int)$off, $p) as $r)
        $users[] = ['id' => (int)$r[5], 'email' => $r[0] ?: '', 'phone' => $r[1] ?: '', 'name' => $r[2] ?: '', 't' => date('m-d H:i', (int)$r[3]), 'orders' => (int)$r[4], 'prem' => ((int)$r[6]) > time() ? dstr($r[6]) : '', 'last' => $r[7] ? eat_str($r[7]) : '', 'spent' => (int)$r[8]];
    return ['users' => $users, 'total' => $total];
}

// ---------------- paid -> hand out one unused config ----------------
function deliver($token) { return with_lock(function () use ($token) { return tx(function () use ($token) { return deliver_in($token); }); }); }
function deliver_in($token) {
    purge();
    $o = q1('SELECT * FROM orders WHERE token=?', [$token]);
    if (!$o) return 'failed';
    if (in_array($o['status'], ['success', 'need_id'], true)) return $o['status'];
    if (strpos((string)$o['speed'], 'member:') === 0) {   // paid membership -> extend Premium (stacks on top of the remaining time)
        $days = ctype_digit((string)$o['duration']) ? (int)$o['duration'] : 0;
        $usr = q1('SELECT premium_until FROM users WHERE id=?', [$o['user_id']]);
        if (!$usr || $days <= 0) return 'failed';
        $until = max(time(), (int)$usr['premium_until']) + $days * 86400;
        q('UPDATE users SET premium_until=? WHERE id=?', [$until, $o['user_id']]);
        q("UPDATE orders SET status='success',config=?,hidden=1 WHERE token=?", ['Premium hadi ' . dstr($until), $token]); q("DELETE FROM notifs WHERE kind='pending' AND ref=?", [$token]);
        notify($o['user_id'], 'Karibu Premium 👑', sprintf('Premium yako imewashwa hadi %s. Vifurushi na ofa za Premium sasa zimefunguka.', dstr($until)), 'paid', $token);
        return 'success';
    }
    $k = q1('SELECT id,content,vpn,descr,showd FROM configs WHERE speed=? AND duration=? AND order_token IS NULL AND ' . live() . ' ORDER BY id LIMIT 1', [$o['speed'], $o['duration']]);
    if (!$k) {
        q("UPDATE orders SET status='no_stock' WHERE token=?", [$token]); q("DELETE FROM notifs WHERE kind='pending' AND ref=?", [$token]);
        if ($o['user_id']) notify($o['user_id'], 'Config zimeisha', sprintf('%s: malipo yamepokelewa lakini config zimeisha kwa sasa. Wasiliana nasi utapata config au kurejeshewa pesa.', $o['package']), 'issue', $token);
        return 'no_stock';
    }
    q('UPDATE configs SET order_token=? WHERE id=?', [$token, $k['id']]);
    if (strpos((string)$o['speed'], 'offer:') !== 0 && isset(VPNS[(string)$k['vpn']])) {   // ANY package config of a locked VPN app (paid, coupon - even 100% off - or free): keep it reserved, customer must send HWID / public key first. Only OFFERS / eSIM (plain downloads) never ask
        q("UPDATE orders SET status='need_id',vpn=?,cfg_id=? WHERE token=?", [$k['vpn'], $k['id'], $token]); q("DELETE FROM notifs WHERE kind='pending' AND ref=?", [$token]);
        if ($o['user_id']) { $vi = vpn_info($k['vpn']); notify($o['user_id'], $o['price'] ? 'Malipo yamefanikiwa ✅' : 'Weka ID yako', sprintf('%s: weka %s ya %s ndani ya "Config Zangu" ili upate config yako.', $o['package'], $vi['idn'], VPNS[$k['vpn']]), 'paid', $token); }
        return 'need_id';
    }
    q("UPDATE orders SET status='success',config=?,cdescr=? WHERE token=?", [$k['content'], cnote($k), $token]); q("DELETE FROM notifs WHERE kind='pending' AND ref=?", [$token]);
    $es = false;
    if (strpos((string)$o['speed'], 'offer:') === 0) { $oid = substr((string)$o['speed'], 6); $es = (bool)q1("SELECT 1 FROM offers WHERE id=? AND kind='esim'", [ctype_digit($oid) ? (int)$oid : -1]); }
    if ($o['user_id']) notify($o['user_id'], $o['price'] ? 'Malipo yamefanikiwa ✅' : 'Config yako iko tayari ✅',
        sprintf($es ? '%s: eSIM yako iko tayari. Ifungue ndani ya ukurasa wa eSIM, sehemu ya "eSIM Zangu".' : '%s: config yako iko tayari. Itafute wakati wowote ndani ya "Config Zangu".', $o['package']), 'paid', $token);
    return 'success';
}
function release_hwid($o) {   // the countdown is over: hand the reserved config to the customer (call with the store lock held) -> config text or null
    $k = q1('SELECT content,descr,showd FROM configs WHERE id=? AND order_token=?', [$o['cfg_id'], $o['token']]);
    if (!$k) return null;
    q("UPDATE orders SET status='success',config=?,cdescr=? WHERE token=? AND status='need_id'", [$k['content'], cnote($k), $o['token']]);
    notify($o['user_id'], 'Config iko tayari ✅', sprintf('%s: config yako iko tayari ndani ya "Config Zangu".', $o['package']), 'paid', $o['token'] . ':ok');
    return $k['content'];
}
function release_due($uid) {   // finish every countdown of this customer that is over (so the config arrives even if the page was closed)
    with_lock(function () use ($uid) { foreach (qa("SELECT * FROM orders WHERE user_id=? AND status='need_id' AND COALESCE(ready_at,0)>0 AND ready_at<=?", [$uid, time()]) as $r) release_hwid($r); });
}
function notify($uid, $title, $body, $kind = 'info', $ref = null) {   // uid null = everybody
    if ($ref && q1('SELECT 1 FROM notifs WHERE kind=? AND ref=?', [$kind, $ref])) return;
    q('INSERT INTO notifs(user_id,title,body,kind,ref,created) VALUES(?,?,?,?,?,?)', [$uid, $title, $body, $kind, $ref, time()]);
}
function fail_order($tok, $why = 'Malipo yameshindikana') {
    with_lock(function () use ($tok, $why) {
        $o = q1("SELECT user_id,package,coupon FROM orders WHERE token=? AND status='pending'", [$tok]);
        if (!$o) return;
        q("UPDATE orders SET status='failed' WHERE token=?", [$tok]);
        if (!empty($o['coupon'])) q('UPDATE coupons SET used=MAX(0,used-1) WHERE code=? COLLATE NOCASE', [$o['coupon']]); q("DELETE FROM notifs WHERE kind='pending' AND ref=?", [$tok]);
        if ($o['user_id']) notify($o['user_id'], $why, sprintf('%s: malipo yameshindikana. Hakuna pesa iliyokatwa. Jaribu tena au wasiliana nasi.', $o['package']), 'issue', $tok);
    });
}

// ---------------- PeterPay (direct cURL: PeterPay accepts PHP/cURL requests, so no bridge script is needed any more) ----------------
function pp_on($s) { return stripos((string)($s['pay_api_url'] ?? ''), 'peterpay') !== false; }   // URL = https://api.peterpay.link/api/v1 , API key = public key (pk_...), secret = webhook secret (sk_...)
function pp_send($url, $h, $payload, $verify, $timeout = 25) {
    $ch = curl_init($url);
    $ca = D . '/ca-bundle.pem';   // optional: system CAs + PeterPay's missing intermediate certificate
    if ($verify && is_file($ca)) curl_setopt($ch, CURLOPT_CAINFO, $ca);
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $payload, CURLOPT_HTTPHEADER => $h, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => $timeout, CURLOPT_CONNECTTIMEOUT => min(10, $timeout),
        CURLOPT_SSL_VERIFYPEER => $verify, CURLOPT_SSL_VERIFYHOST => $verify ? 2 : 0]);
    $raw = curl_exec($ch);
    $r = ['raw' => $raw === false ? '' : (string)$raw, 'code' => (int)curl_getinfo($ch, CURLINFO_HTTP_CODE), 'errno' => curl_errno($ch), 'err' => curl_error($ch)];
    curl_close($ch); return $r;
}
function pp_call($s, $host, $path, $body, $timeout = 25) {
    $GLOBALS['PP_ERRNO'] = 0;   // POST to PeterPay -> [http code, json array]
    if (!function_exists('curl_init')) { error_log('PeterPay: php-curl is not installed (apt install php-curl)'); return [0, []]; }
    $url = rtrim($s['pay_api_url'], '/') . $path; $site = !empty($s['pay_site']) ? $s['pay_site'] : explode(':', (string)$host)[0];
    $h = ['X-API-KEY: ' . $s['pay_api_key'], 'X-PeterPay-Site: ' . $site, 'Content-Type: application/json', 'Accept: application/json', 'User-Agent: PeterPay-Client/1.0'];
    $payload = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $r = pp_send($url, $h, $payload, true, $timeout);   // certificate is ALWAYS verified (never disabled, as the PeterPay docs require). If you get SSL errors, install ca-certificates / update the server's CA bundle.
    $GLOBALS['PP_ERRNO'] = $r['errno'];
    if (!$r['code']) { error_log('PeterPay connection error: ' . ($r['err'] ?: 'no reply') . ' url=' . $url); return [0, []]; }
    if (trim($r['raw']) === '') { error_log('PeterPay replied with an EMPTY body: HTTP ' . $r['code'] . ' url=' . $url); return [$r['code'], []]; }
    $d = json_decode($r['raw'], true);
    if (!is_array($d)) { error_log('PeterPay replied with something that is not JSON: HTTP ' . $r['code'] . ' url=' . $url . ' body=' . substr($r['raw'], 0, 400)); return [$r['code'], []]; }
    return [$r['code'], $d];
}
// ---------------- EasyPay (https://api.easypay.co.tz): X-API-Key + X-Secret-Key, POST /v1/payments, webhooks only (no polling endpoint) ----------------
function ep_on($s) { return stripos((string)($s['pay_api_url'] ?? ''), 'easypay') !== false; }   // settings: URL = https://api.easypay.co.tz | API key = epk_... | pay_api_secret = ep_sk_... | pay_secret = WEBHOOK signing secret
function ep_call($s, $path, $body, $idem, $timeout = 20) {   // POST to EasyPay -> [http code, json array]
    $GLOBALS['PP_ERRNO'] = 0;
    if (!function_exists('curl_init')) { error_log('EasyPay: php-curl is not installed (apt install php-curl)'); return [0, []]; }
    $base = rtrim((string)$s['pay_api_url'], '/'); if (!preg_match('~/v1$~', $base)) $base .= '/v1';
    $url = $base . $path;
    $h = ['Content-Type: application/json', 'Accept: application/json', 'X-API-Key: ' . $s['pay_api_key'], 'X-Secret-Key: ' . ($s['pay_api_secret'] ?? ''), 'X-Idempotency-Key: ' . $idem, 'User-Agent: VisibleTech-Store/1.0'];
    $r = pp_send($url, $h, json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), true, $timeout);   // certificate is always verified
    $GLOBALS['PP_ERRNO'] = $r['errno'];
    if (!$r['code']) { error_log('EasyPay connection error: ' . ($r['err'] ?: 'no reply') . ' url=' . $url); return [0, []]; }
    $d = json_decode($r['raw'], true);
    if (!is_array($d)) { error_log('EasyPay replied with something that is not JSON: HTTP ' . $r['code'] . ' url=' . $url . ' body=' . substr($r['raw'], 0, 300)); return [$r['code'], []]; }
    return [$r['code'], $d];
}
function ep_request($s, $token, $o) {   // returns null when the request was accepted (or may be in flight), else an error text for the customer
    if (empty($s['pay_api_key']) || empty($s['pay_api_secret'])) { error_log('EasyPay: set BOTH the public key (epk_...) and the secret key (ep_sk_...) in the admin settings'); return 'Mipangilio ya malipo haijakamilika. Wasiliana nasi.'; }
    if (empty($s['pay_secret'])) { error_log('EasyPay: set the webhook signing secret in the admin settings, otherwise payments can never be confirmed'); return 'Mipangilio ya malipo haijakamilika. Wasiliana nasi.'; }
    $ph = preg_replace('/\D/', '', (string)$o['phone']); if (strpos($ph, '255') === 0 && strlen($ph) === 12) $ph = '0' . substr($ph, 3);   // EasyPay wants the local format 0712345678
    $amt = (int)round((float)$o['price']); if ($amt < 1) return 'Kiasi si sahihi.';
    $idem = substr('vt-order-' . $token, 0, 100);   // 10-100 chars; the same order always replays the same payment instead of charging twice
    $body = ['amount' => (string)$amt, 'currency' => 'TZS', 'mode' => 'instant', 'payerPhone' => $ph, 'payerName' => 'Customer',
        'description' => cut((string)($o['package'] ?? 'Order'), 200), 'merchantReference' => $token, 'metadata' => ['orderId' => $token]];
    list($code, $d) = ep_call($s, '/payments', $body, $idem);
    if ($code >= 500) list($code, $d) = ep_call($s, '/payments', $body, $idem);   // docs: on 5xx retry ONCE with the same idempotency key
    $err = is_array($d['errors'] ?? null) ? (string)($d['errors']['code'] ?? '') : '';
    if ($code === 201 && !empty($d['success']) && !empty($d['data']['reference'])) { error_log('EasyPay payment OK: order ' . substr($token, 0, 6) . '... amount ' . $amt . ' ref ' . $d['data']['reference']); return null; }
    if ($code === 0 && (int)($GLOBALS['PP_ERRNO'] ?? 0) === 28) { error_log('EasyPay payment TIMEOUT: order ' . substr($token, 0, 6) . '... kept pending, the webhook will settle it'); return null; }
    if ($code === 409 && $err === 'IDEMPOTENCY_ERROR') return null;   // the same order is already being processed
    error_log('EasyPay payment failed: HTTP ' . $code . ' ' . json_encode($d));
    if ($err === 'CHANNEL_PAUSED') return 'Mtandao huu wa malipo umesimama kwa muda. Jaribu tena baadaye.';
    $m = [400 => 'Namba ya simu au kiasi si sahihi.', 401 => 'Mipangilio ya malipo si sahihi (API key). Wasiliana nasi.', 403 => 'Malipo yamezuiwa (application haijawashwa au IP haijaruhusiwa). Wasiliana nasi.', 429 => 'Maombi mengi sana. Jaribu tena baada ya dakika moja.'];
    return $m[$code] ?? 'Imeshindikana kutuma ombi la malipo.';
}
// ---------------- SonicPesa (https://api.sonicpesa.com/api/v1): X-API-KEY + X-API-SECRET, POST /payment/create_order, webhook X-SonicPesa-Signature ----------------
function sp_on($s) { return stripos((string)($s['pay_api_url'] ?? ''), 'sonicpesa') !== false; }   // settings: URL = https://api.sonicpesa.com | API key = X-API-KEY | pay_api_secret = X-API-SECRET (also signs the webhooks)
function sp_map_put($prov, $token) {   // SonicPesa makes its OWN order id (sp_...), so remember prov id <-> our order token
    $d = D . '/sp'; if (!is_dir($d)) @mkdir($d, 0700, true);
    @file_put_contents($d . '/o_' . sha1($prov), $token, LOCK_EX); @file_put_contents($d . '/t_' . sha1($token), $prov, LOCK_EX);
    if (mt_rand(1, 100) === 1) foreach (glob($d . '/*') ?: [] as $x) if (@filemtime($x) < time() - 90 * 86400) @unlink($x);
}
function sp_map_token($prov) { $f = D . '/sp/o_' . sha1($prov); return is_file($f) ? trim((string)@file_get_contents($f)) : ''; }
function sp_map_prov($token) { $f = D . '/sp/t_' . sha1($token); return is_file($f) ? trim((string)@file_get_contents($f)) : ''; }
function sp_call($s, $path, $body, $timeout = 20) {   // POST to SonicPesa -> [http code, json array]
    $GLOBALS['PP_ERRNO'] = 0;
    if (!function_exists('curl_init')) { error_log('SonicPesa: php-curl is not installed (apt install php-curl)'); return [0, []]; }
    $base = rtrim((string)$s['pay_api_url'], '/'); if (!preg_match('~/api/v1$~', $base)) $base .= '/api/v1';
    $url = $base . $path;
    $h = ['Content-Type: application/json', 'Accept: application/json', 'X-API-KEY: ' . $s['pay_api_key'], 'X-API-SECRET: ' . ($s['pay_api_secret'] ?? ''), 'User-Agent: VisibleTech-Store/1.0'];
    $r = pp_send($url, $h, json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), true, $timeout);   // certificate is always verified
    $GLOBALS['PP_ERRNO'] = $r['errno'];
    if (!$r['code']) { error_log('SonicPesa connection error: ' . ($r['err'] ?: 'no reply') . ' url=' . $url); return [0, []]; }
    $d = json_decode($r['raw'], true);
    if (!is_array($d)) { error_log('SonicPesa replied with something that is not JSON: HTTP ' . $r['code'] . ' url=' . $url . ' body=' . substr($r['raw'], 0, 300)); return [$r['code'], []]; }
    return [$r['code'], $d];
}
function sp_request($s, $token, $o, $host) {   // returns null when the USSD push was sent, else an error text for the customer
    if (empty($s['pay_api_key']) || empty($s['pay_api_secret'])) { error_log('SonicPesa: set BOTH the API key and the API secret in the admin settings'); return 'Mipangilio ya malipo haijakamilika. Wasiliana nasi.'; }
    $ph = preg_replace('/\D/', '', (string)$o['phone']); if (strpos($ph, '0') === 0) $ph = '255' . substr($ph, 1); elseif (strlen($ph) === 9) $ph = '255' . $ph;
    $amt = (int)round((float)$o['price']); if ($amt < 1) return 'Kiasi si sahihi.';
    $mail = ''; if (!empty($o['user_id'])) { $u = q1('SELECT email FROM users WHERE id=?', [$o['user_id']]); $mail = (string)($u['email'] ?? ''); }
    if ($mail === '') { $hd = preg_replace('/[^a-z0-9.-]/i', '', (string)$host); if (strpos($hd, '.') === false) $hd = 'example.com'; $mail = 'customer@' . $hd; }
    $body = ['buyer_email' => $mail, 'buyer_name' => 'Customer', 'buyer_phone' => $ph, 'amount' => $amt, 'currency' => 'TZS'];
    list($code, $d) = sp_call($s, '/payment/create_order', $body, 40);   // HaloPesa / mobile-money gateways can take 20-30s to answer: 20s was too short and failed good orders
    if ($code === 0 && in_array((int)($GLOBALS['PP_ERRNO'] ?? 0), [5, 6, 7, 35, 56], true)) { usleep(1200000); list($code, $d) = sp_call($s, '/payment/create_order', $body, 40); }   // retry ONLY when the request never reached SonicPesa (DNS / connect / TLS error) - never after a timeout, that could charge the customer twice
    $prov = (string)($d['data']['order_id'] ?? '');
    if (($code === 200 || $code === 201) && ($d['status'] ?? '') === 'success' && $prov !== '') { sp_map_put($prov, $token); error_log('SonicPesa payment OK: order ' . substr($token, 0, 6) . '... amount ' . $amt . ' ref ' . $prov); return null; }
    error_log('SonicPesa payment failed: HTTP ' . $code . ' ' . json_encode($d));
    if ($code === 0 && (int)($GLOBALS['PP_ERRNO'] ?? 0) === 28) return 'Mtandao wa malipo umechelewa kujibu. Subiri dakika 2: kama ombi la malipo limefika kwenye simu yako, weka PIN. Kama halijafika, jaribu tena.';
    if ($code === 0) return 'Imeshindikana kuwasiliana na mtoa huduma wa malipo. Jaribu tena.';
    $m = [400 => 'Namba ya simu au kiasi si sahihi.', 401 => 'Mipangilio ya malipo si sahihi (API key). Wasiliana nasi.', 403 => 'Malipo yamezuiwa (salio au ruhusa hazitoshi kwa akaunti ya malipo). Wasiliana nasi.'];
    return $m[$code] ?? 'Imeshindikana kutuma ombi la malipo.';
}
function sp_poll($s, $token, $price, $timeout = 25) {   // ask SonicPesa directly (backup for a missed webhook): 'ok' = really paid (amount checked), 'failed', or null = still waiting
    $prov = sp_map_prov($token); if ($prov === '') return null;
    $f = D . '/sp/p_' . sha1($token); $now = microtime(true);
    if (is_file($f) && $now - (float)@file_get_contents($f) < 3) return null;
    @file_put_contents($f, (string)$now);
    list($code, $d) = sp_call($s, '/payment/order_status', ['order_id' => $prov], $timeout);
    $x = is_array($d['data'] ?? null) ? $d['data'] : [];
    $st = strtoupper((string)($x['payment_status'] ?? ''));
    if ($st === 'SUCCESS' && (float)($x['amount'] ?? 0) >= $price && strtoupper((string)($x['currency'] ?? 'TZS')) === 'TZS') return 'ok';
    if (in_array($st, ['CANCELLED', 'CANCELED', 'REJECTED', 'FAILED', 'EXPIRED'], true)) return 'failed';
    return null;
}
function pay_request($s, $token, $o, $host) {   // returns null when the request was sent, else an error text for the customer
    if (!$s['pay_api_url']) return null;   // no provider: order stays pending until webhook / manual confirm
    if (ep_on($s)) return ep_request($s, $token, $o);
    if (sp_on($s)) return sp_request($s, $token, $o, $host);
    if (pp_on($s)) {
        $ph = preg_replace('/\D/', '', (string)$o['phone']); if (strpos($ph, '0') === 0) $ph = '255' . substr($ph, 1);
        $amt = (int)$o['price'];
        if ($amt < 500 || $amt > 3000000) return 'Kiasi si sahihi (kiwango cha chini 500/=, cha juu 3,000,000/=).';
        list($code, $d) = pp_call($s, $host, '/create_order', ['amount' => (int)$o['price'], 'buyer_phone' => $ph, 'buyer_name' => 'Customer', 'order_id' => $token]);
        if (($code === 200 || $code === 201) && strtolower((string)($d['status'] ?? '')) === 'success') { error_log('PeterPay create_order OK: order ' . substr($token, 0, 6) . '... amount ' . (int)$o['price']); return null; }
        if ($code === 0 && (int)($GLOBALS['PP_ERRNO'] ?? 0) === 28) { error_log('PeterPay create_order TIMEOUT: order ' . substr($token, 0, 6) . '... kept pending, status will be checked by order_status/webhook'); return null; }   // docs: on timeout check the same order_id, do not fail it
        error_log('PeterPay create_order failed: ' . $code . ' ' . json_encode($d));
        $m = [401 => 'Mipangilio ya malipo si sahihi (API key). Wasiliana nasi.', 403 => 'Malipo yamezuiwa (IP/website haijaruhusiwa). Wasiliana nasi.', 422 => 'Namba ya simu au kiasi si sahihi (kiwango cha chini 500/=).'];
        return $m[$code] ?? 'Imeshindikana kutuma ombi la malipo.';
    }
    $body = json_encode(['order_id' => $token, 'amount' => $o['price'], 'phone' => $o['phone'], 'network' => $o['network'],
        'callback_url' => (BIND === '127.0.0.1' ? 'https' : 'http') . '://' . $host . '/api/webhook/' . $s['webhook_secret']]);
    $ch = curl_init($s['pay_api_url']);
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $s['pay_api_key']], CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20]);
    $r = curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    return ($r !== false && $code >= 200 && $code < 300) ? null : 'Imeshindikana kutuma ombi la malipo.';
}
function pp_poll($s, $host, $token, $price, $timeout = 25) {   // ask PeterPay directly (backup for a missed webhook): 'ok' = really paid (amount checked), 'failed', or null = still waiting
    if (sp_on($s)) return sp_poll($s, $token, $price, $timeout);
    if (!pp_on($s)) return null;
    $dir = D . '/pp'; if (!is_dir($dir)) @mkdir($dir, 0700, true);
    $f = $dir . '/' . sha1($token); $now = microtime(true);
    if (is_file($f) && $now - (float)@file_get_contents($f) < 3) return null;
    file_put_contents($f, (string)$now);
    if (mt_rand(1, 50) === 1) foreach (glob($dir . '/*') ?: [] as $x) if (@filemtime($x) < time() - 3600) @unlink($x);
    list($code, $d) = pp_call($s, $host, '/order_status', ['order_id' => $token], $timeout);
    $x = is_array($d['data'] ?? null) ? $d['data'] : [];
    $st = strtoupper((string)($x['payment_status'] ?? '')); $amt = (float)($x['amount'] ?? 0);
    if ($st === 'COMPLETED' && $amt >= $price) return 'ok';
    if (in_array($st, ['FAILED', 'CANCELLED', 'CANCELED', 'EXPIRED'], true)) return 'failed';
    return null;
}

// ---------------- customer accounts: email + phone + password ----------------
function norm_email($t) { $t = strtolower(trim((string)$t)); return (strlen($t) <= 254 && preg_match(EMAIL_RE, $t)) ? $t : null; }
function norm_phone($t) {   // 0712345678 / +255712345678 -> 255712345678
    $d = preg_replace('/\D/', '', (string)$t);
    if (strpos($d, '00') === 0) $d = substr($d, 2);
    if (strpos($d, '0') === 0 && strlen($d) === 10) $d = '255' . substr($d, 1); elseif (strlen($d) === 9) $d = '255' . $d;
    return (strlen($d) >= 11 && strlen($d) <= 15 && $d[0] !== '0') ? $d : null;
}
function is_prem($u) { return (int)($u['premium_until'] ?? 0) > time(); }
function pub($u) { return ['name' => $u['name'] ?: '', 'email' => $u['email'] ?: '', 'phone' => $u['phone'] ?: '', 'premium' => is_prem($u) ? 1 : 0, 'premium_until' => is_prem($u) ? dstr($u['premium_until']) : '']; }
function login_needed($s) { return true; }   // login is ALWAYS required: nothing is served to anonymous visitors

// ---------------- uploaded FILES (.hc / .ehi / .npv ...): stored and handed out byte-for-byte, never converted to text ----------------
const FILE_MAX = 3 * 1024 * 1024;
function vt_is_file($c) { $c = (string)$c; return $c !== '' && $c[0] === '{' && strpos(substr($c, 0, 40), '"vtfile"') !== false; }
function vt_name($n) {   // safe file name: only letters, numbers . _ - and space; the extension (.hc / .ehi) is kept
    $n = basename(str_replace('\\', '/', (string)$n));
    $n = trim(preg_replace('/[^A-Za-z0-9._ -]+/', '_', $n), ' .');
    if ($n === '') $n = 'config';
    return strlen($n) > 80 ? substr($n, -80) : $n;
}
function vt_norm($x) {   // admin upload -> canonical stored text, or null when it is not a valid file item
    $d = json_decode((string)$x, true);
    if (!is_array($d) || empty($d['vtfile'])) return null;
    $raw = base64_decode((string)($d['data'] ?? ''), true);
    if ($raw === false || $raw === '' || strlen($raw) > FILE_MAX) return null;
    return json_encode(['vtfile' => 1, 'name' => vt_name($d['name'] ?? ''), 'data' => base64_encode($raw)], JSON_UNESCAPED_SLASHES);
}
function vt_parts($c) {   // -> [name, raw bytes] or null
    if (!vt_is_file($c)) return null;
    $d = json_decode((string)$c, true); if (!is_array($d) || empty($d['vtfile'])) return null;
    $raw = base64_decode((string)($d['data'] ?? ''), true); if ($raw === false) return null;
    return [vt_name($d['name'] ?? ''), $raw];
}
function cfg_pub($c, $tok) {   // what the customer's browser receives: text configs unchanged; a file becomes a small description + a protected download link (the bytes are NOT inside the JSON)
    $p = vt_parts($c); if (!$p) return (string)$c;
    $txt = (strlen($p[1]) <= 200000 && strpos($p[1], "\0") === false && @preg_match('//u', $p[1]) === 1) ? 1 : 0;
    return json_encode(['vtfile' => 1, 'name' => $p[0], 'size' => strlen($p[1]), 'txt' => $txt, 'url' => '/api/config-file/' . rawurlencode((string)$tok)], JSON_UNESCAPED_SLASHES);
}
function cfg_admin($c) { $p = vt_parts($c); return $p ? ('📎 ' . $p[0] . ' (' . strlen($p[1]) . ' bytes)') : (string)$c; }
function vt_page($p, $tok) {   // /r/<token> page for a file order
    return '<p><b>' . hesc($p[0]) . '</b> (' . strlen($p[1]) . ' bytes)</p><p><a style="color:#3DDC84" href="/api/config-file/' . hesc(rawurlencode((string)$tok)) . '">⬇ Download</a></p>';
}

// ---------------- offers: who may download and how many people (v7) ----------------
function offer_shared($o) { return (($o['kind'] ?? '') ?: 'offer') !== 'esim' && (($o['dl_mode'] ?? 'once') === 'shared'); }   // shared = the same file is given to MANY people (never used up)
function offer_users($o) { $x = json_decode((string)($o['dl_users'] ?? ''), true); return is_array($x) ? array_values(array_filter(array_map('intval', $x))) : []; }
function offer_allowed($o, $u) { $l = offer_users($o); return !$l || ($u && in_array((int)$u['id'], $l, true)); }   // empty list = every account
function offer_claimed($oid) { return (int)qv("SELECT COUNT(DISTINCT user_id) FROM orders WHERE speed=? AND status='success' AND user_id IS NOT NULL", ['offer:' . (int)$oid]); }   // PEOPLE that already got it
function offer_had($oid, $uid) { return (bool)q1("SELECT 1 FROM orders WHERE speed=? AND user_id=? AND status='success'", ['offer:' . (int)$oid, (int)$uid]); }
function offer_users_txt($o) {
    $ids = offer_users($o); if (!$ids) return ''; $t = [];
    foreach (qa('SELECT id,email,phone FROM users WHERE id IN (' . implode(',', array_map('intval', $ids)) . ')') as $x) $t[] = $x['email'] ?: ($x['phone'] ?: (string)$x['id']);
    return implode("\n", $t);
}
function claim_shared($of, $u, $title) {   // one person takes a shared offer: nothing is used up, the limit counts PEOPLE; the checks and the writes happen under the store lock, so two people can never squeeze past the limit
    return with_lock(function () use ($of, $u, $title) { return tx(function () use ($of, $u, $title) {
        purge(); $sp = 'offer:' . (int)$of['id']; $now = time();
        $ks = qa('SELECT id,content,descr,showd FROM configs WHERE speed=? AND order_token IS NULL AND ' . live() . ' ORDER BY id', [$sp]);
        if (!$ks) return ['err' => 'Ofa hii imeisha kwa sasa.'];
        $mx = (int)($of['dl_max'] ?? 0);
        if ($mx > 0 && !offer_had($of['id'], $u['id']) && offer_claimed($of['id']) >= $mx) return ['err' => 'Ofa hii imefikia kikomo cha watu walioruhusiwa kupakua.'];
        $first = null;
        foreach ($ks as $k) {
            $ex = q1("SELECT token FROM orders WHERE speed=? AND user_id=? AND cfg_id=? AND status='success'", [$sp, $u['id'], $k['id']]);
            if ($ex) { $tok = $ex['token']; q('UPDATE orders SET hidden=0 WHERE token=?', [$tok]); }   // already had it (maybe deleted it from the list): bring it back, does not use a second place
            else {
                $tok = rand_url(16);
                q('INSERT INTO orders(token,created,package,speed,duration,network,phone,price,status,config,cdescr,user_id,hidden,cfg_id) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,0,?)', [$tok, $now, $title, $sp, 'stock', '', '', 0, 'success', $k['content'], cnote($k), $u['id'], $k['id']]);
                notify($u['id'], 'Config yako iko tayari ✅', sprintf('%s: config yako iko tayari. Itafute wakati wowote ndani ya "Config Zangu".', $title), 'paid', $tok);
            }
            if (!$first) $first = ['tok' => $tok, 'cfg' => $k['content'], 'descr' => cnote($k)];
        }
        return $first;
    }); });
}

// ---------------- offers / eSIM / banners / coupons / notifications ----------------
function esim_ids() { return array_map('strval', array_column(qa("SELECT id FROM offers WHERE kind='esim'"), 'id')); }
function esim_parts($cfg) {   // one eSIM stock item: JSON {esim:1, qr, link, code, smdp} | raw QR image (data URL) | link | activation code
    $cfg = trim((string)$cfg); $d = [];
    if (strpos($cfg, '{') === 0) { $d = json_decode($cfg, true); if (!is_array($d)) $d = []; }
    elseif (strpos($cfg, 'data:image/') === 0) $d = ['qr' => $cfg];
    elseif (preg_match('#^https?://\S+$#', $cfg)) $d = ['link' => $cfg];
    elseif ($cfg !== '') $d = ['code' => $cfg];
    $qr = (string)($d['qr'] ?? ''); $qr = preg_match(IMG_RE, $qr) ? $qr : '';
    $link = (string)($d['link'] ?? ''); $link = preg_match('#^https?://\S+$#', $link) ? $link : '';
    $code = (string)($d['code'] ?? '');
    $rx = '/LPA:1\$([^$\s&"\'<>]+)\$([^$\s&"\'<>]*)(\$[^\s&"\'<>]*)?/i';
    $mc = []; $ml = []; preg_match($rx, rawurldecode($code), $mc); preg_match($rx, rawurldecode($link), $ml); $m = $mc ?: $ml;
    $smdp = (string)($d['smdp'] ?? '');
    return ['qr' => $qr, 'link' => $link, 'lpa' => $m ? $m[0] : '', 'smdp' => $smdp !== '' ? $smdp : ($m ? $m[1] : ''),
            'act' => $mc ? $mc[2] : ($code !== '' ? $code : ($ml ? $ml[2] : ''))];
}
function esim_html($cfg) {   // simple page for /r/<token> of an eSIM order
    $p = esim_parts($cfg); $box = 'background:#111A2E;padding:12px;border-radius:12px;margin:6px 0 14px;word-break:break-all;white-space:pre-wrap'; $h = '';
    if ($p['qr']) $h .= sprintf('<img alt="eSIM QR code" src="%s" style="display:block;max-width:260px;width:100%%;margin:10px auto;background:#fff;padding:10px;border-radius:12px">', hesc($p['qr']));
    if ($p['link']) $h .= sprintf('<p><a style="color:#3DDC84" href="%s" target="_blank" rel="noopener">Open eSIM link</a></p><div style="%s">%s</div>', hesc($p['link']), $box, hesc($p['link']));
    foreach ([['Activation code (LPA)', 'lpa'], ['SM-DP+ Address', 'smdp'], ['Activation Code', 'act']] as $x) {
        if ($p[$x[1]]) $h .= sprintf('<small>%s</small><div style="%s">%s</div>', $x[0], $box, hesc($p[$x[1]]));
    }
    return $h ?: '<p>eSIM is empty.</p>';
}
function offer_price($o) { return (($o['kind'] ?: 'offer') === 'esim') ? max(0, (int)$o['price']) : 0; }   // only eSIM can have a price
function resolve_item($s, $f, $u = null) {   // what is being bought: a membership, an admin "offer" / eSIM, or a classic network/plan/duration package. ['locked'=>1] = Premium only
    $mid = (string)($f['member_id'] ?? '');
    if ($mid !== '') {
        $pm = $s['premium'] ?? []; $x = null;
        foreach (($pm['plans'] ?? []) as $y) if ($y['id'] === $mid) { $x = $y; break; }
        return ($x && !empty($pm['on'])) ? ['title' => '👑 ' . ($pm['title'] ?? 'Premium') . ' — ' . $x['label'], 'speed' => 'member:' . $mid, 'duration' => (string)$x['days'], 'network' => '', 'price' => (int)$x['price'], 'kind' => 'member', 'mid' => $mid] : null;
    }
    $oid = (string)($f['offer_id'] ?? '');
    if ($oid !== '') {
        $o = q1('SELECT * FROM offers WHERE id=? AND active=1', [ctype_digit($oid) ? (int)$oid : -1]);
        if ($o && $o['prem'] && !($u && is_prem($u))) return ['locked' => 1];
        if ($o && !offer_allowed($o, $u)) return null;   // offer limited to chosen accounts: anybody else gets the same answer as for an unknown offer
        return $o ? ['title' => $o['title'], 'speed' => 'offer:' . $o['id'], 'duration' => 'stock', 'network' => '', 'price' => offer_price($o), 'kind' => (($o['kind'] ?: 'offer') === 'esim') ? 'esim' : 'offer', 'oid' => (string)$o['id']] : null;   // offers are always FREE; eSIM is PAID
    }
    list($plan, $r, $net) = find_row($s, $f['speed_type'] ?? '', $f['duration'] ?? '');
    if ($r && !empty($net['prem']) && !($u && is_prem($u))) return ['locked' => 1];
    return $r ? ['title' => $net['name'] . ' — ' . $plan['title'] . ' — ' . $r['label'], 'speed' => $plan['id'], 'duration' => $r['d'], 'network' => $net['name'], 'price' => (int)$r['price'], 'kind' => 'package', 'net_id' => (string)$net['id'], 'plan_id' => (string)$plan['id']] : null;
}
function coupon_uses($code, $uid = null) {   // REAL uses counted from the orders: finished ones, or waiting for payment for less than 1 hour (an abandoned order does not burn a use)
    $w = "coupon=? COLLATE NOCASE AND (status IN ('success','need_id','no_stock') OR (status='pending' AND created>?))"; $p = [$code, time() - 3600];
    if ($uid !== null) { $w .= ' AND user_id=?'; $p[] = (int)$uid; }
    return (int)qv('SELECT COUNT(*) FROM orders WHERE ' . $w, $p);
}
function coupon_scope($k) {   // who / what a coupon is limited to (empty list = no limit)
    $x = json_decode((string)($k['scope'] ?? ''), true); if (!is_array($x)) $x = [];
    return ['users' => array_values(array_filter(array_map('intval', (array)($x['users'] ?? [])))),
            'items' => array_values(array_filter(array_map('strval', (array)($x['items'] ?? [])), function ($v) { return $v !== ''; })),
            'aud' => in_array($x['aud'] ?? '', ['premium', 'free'], true) ? $x['aud'] : 'all'];
}
function coupon_item_ok($keys, $it) {   // does the thing being bought belong to the coupon's item / group list?
    if (!$it || !empty($it['locked'])) return false;
    $kind = (string)($it['kind'] ?? '');
    foreach ($keys as $key) {
        $p = explode(':', $key, 2); $a = $p[0]; $b = $p[1] ?? '';
        if ($a === 'kind' && $b === $kind) return true;
        if ($a === 'net' && $kind === 'package' && $b === (string)($it['net_id'] ?? '')) return true;
        if ($a === 'plan' && $kind === 'package' && $b === (string)($it['plan_id'] ?? '')) return true;
        if ($a === 'pkg' && $kind === 'package' && $b === ($it['speed'] . '|' . $it['duration'])) return true;
        if ($a === 'offer' && ($kind === 'offer' || $kind === 'esim') && $b === (string)($it['oid'] ?? '')) return true;
        if ($a === 'member' && $kind === 'member' && $b === (string)($it['mid'] ?? '')) return true;
    }
    return false;
}
function apply_coupon($code, $price, $u = null, $it = null) {   // -> [new price, coupon row, error]
    $bad = 'Kodi si sahihi.';
    $k = q1('SELECT * FROM coupons WHERE code=? COLLATE NOCASE', [$code]);
    if (!$k || !$k['active']) return [$price, null, $bad];
    $sc = coupon_scope($k);
    // the private rules come FIRST and give the SAME answer as an unknown code: nobody can find out which codes exist or who they belong to
    if ($sc['users'] && (!$u || !in_array((int)$u['id'], $sc['users'], true))) return [$price, null, $bad];
    if ($sc['aud'] === 'premium' && !($u && is_prem($u))) return [$price, null, $bad];
    if ($sc['aud'] === 'free' && $u && is_prem($u)) return [$price, null, $bad];
    if ($sc['items'] && !coupon_item_ok($sc['items'], $it)) return [$price, null, $bad];
    if ($k['expires'] && $k['expires'] < time()) return [$price, null, 'Kodi hii imeisha muda wake.'];
    if ($price <= 0) return [$price, null, 'Kodi hii haihitajiki kwa kifurushi cha bure.'];
    if ((int)$k['min_price'] > 0 && $price < (int)$k['min_price']) return [$price, null, sprintf('Kodi hii ni kwa manunuzi ya angalau %d/=.', (int)$k['min_price'])];
    if ($k['max_uses'] && coupon_uses($k['code']) >= (int)$k['max_uses']) return [$price, null, 'Kodi hii imefikia kikomo cha matumizi.'];
    if ((int)$k['per_user'] > 0 && $u && coupon_uses($k['code'], $u['id']) >= (int)$k['per_user']) return [$price, null, 'Umeshatumia kodi hii kiwango cha juu kinachoruhusiwa.'];
    $off = $k['kind'] === 'pct' ? intdiv($price * min(100, (int)$k['value']), 100) : (int)$k['value'];
    return [max(0, $price - $off), $k, null];
}
function bn_pub($r) {   // banner as the customer site needs it
    return ['id' => (int)$r['id'], 'title' => $r['title'], 'text' => $r['text'], 'place' => $r['place'], 'color' => $r['color'], 'coupon' => $r['coupon'] ?: '', 'link' => $r['link'] ?: '', 'btn' => $r['btn'] ?: '',
            'bg' => $r['bg'] ?: '', 'fg' => $r['fg'] ?: '', 'pages' => $r['pages'] ?: 'all', 'anim' => $r['anim'] === 0 || $r['anim'] === '0' ? 0 : 1, 'dismiss' => $r['dismiss'] === 0 || $r['dismiss'] === '0' ? 0 : 1,
            'img' => $r['img'] ? sprintf('/api/banner-img/%d?v=%d', $r['id'], strlen($r['img'])) : '',
            'style' => (string)($r['style'] ?? ''), 'fx' => (string)($r['fx'] ?? ''), 'freq' => (string)(($r['freq'] ?? '') ?: 'session'), 'delay' => (int)($r['delay'] ?? 0), 'aud' => (string)(($r['aud'] ?? '') ?: 'all'),
            'cd' => (int)($r['cd'] ?? 0), 'ends' => ((int)($r['cd'] ?? 0) && (int)$r['ends'] > 0) ? (int)$r['ends'] : 0];
}
function admin_extra() {
    $st = [];
    foreach (qn("SELECT speed,SUM(order_token IS NULL AND " . live() . "),COUNT(*),SUM(order_token IS NULL AND " . expd() . ") FROM configs WHERE speed LIKE 'offer:%' GROUP BY 1") as $r) $st[$r[0]] = [(int)$r[1], (int)$r[2], (int)$r[3]];
    $sd = []; foreach (qn("SELECT speed,COUNT(*) FROM orders WHERE speed LIKE 'offer:%' AND status IN ('success','need_id') GROUP BY 1") as $r) $sd[$r[0]] = (int)$r[1];
    $offers = []; $banners = []; $coupons = []; $notifs = [];
    foreach (qa('SELECT * FROM offers ORDER BY id DESC') as $r) {
        $x = $st['offer:' . $r['id']] ?? [0, 0, 0];
        $offers[] = ['id' => (int)$r['id'], 'title' => $r['title'], 'descr' => $r['descr'], 'price' => offer_price($r), 'active' => (int)$r['active'], 'kind' => $r['kind'] ?: 'offer', 'prem' => (int)$r['prem'], 'free' => $x[0], 'total' => $x[1], 'exp' => $x[2], 'sold' => $sd['offer:' . $r['id']] ?? 0,
                     'video' => vid_url($r['video'] ?? ''), 'dl_mode' => offer_shared($r) ? 'shared' : 'once', 'dl_max' => (int)($r['dl_max'] ?? 0), 'users_txt' => offer_users_txt($r), 'claimed' => offer_claimed($r['id'])];
    }
    foreach (qa('SELECT * FROM banners ORDER BY COALESCE(sort,0), id DESC') as $r)
        $banners[] = array_merge(bn_pub($r), ['img' => '', 'has_img' => $r['img'] ? 1 : 0, 'active' => (int)$r['active'], 'starts' => dstr($r['starts']), 'ends' => dstr($r['ends']), 'sort' => (int)$r['sort']]);
    foreach (qa('SELECT * FROM coupons ORDER BY id DESC') as $r)
    {
        $sc = coupon_scope($r); $ut = [];
        if ($sc['users']) foreach (qa('SELECT id,email,phone FROM users WHERE id IN (' . implode(',', array_map('intval', $sc['users'])) . ')') as $x) $ut[] = $x['email'] ?: ($x['phone'] ?: (string)$x['id']);
        $coupons[] = ['id' => (int)$r['id'], 'code' => $r['code'], 'kind' => $r['kind'], 'value' => (int)$r['value'], 'active' => (int)$r['active'], 'expires' => dstr($r['expires']), 'max_uses' => (int)$r['max_uses'], 'used' => coupon_uses($r['code']),
                      'per_user' => (int)($r['per_user'] ?? 0), 'min_price' => (int)($r['min_price'] ?? 0), 'audience' => $sc['aud'], 'users_txt' => implode("\n", $ut), 'items' => $sc['items']];
    }
    foreach (qa("SELECT n.id,n.title,n.body,n.created,u.email,u.phone,u.name,n.user_id,(SELECT COUNT(*) FROM notif_files f WHERE f.notif_id=n.id) AS nfc FROM notifs n LEFT JOIN users u ON u.id=n.user_id WHERE n.kind='info' ORDER BY n.created DESC LIMIT 100") as $r)
        $notifs[] = ['id' => (int)$r['id'], 'title' => $r['title'], 'body' => $r['body'], 'files' => (int)$r['nfc'], 't' => date('m-d H:i', (int)$r['created']), 'to' => $r['user_id'] ? ($r['email'] ?: ($r['phone'] ?: ($r['name'] ?: 'user'))) : 'ALL USERS'];
    return ['offers' => $offers, 'banners' => $banners, 'coupons' => $coupons, 'notifs' => $notifs];
}
function site_payload($s, $u = null) {
    $now = time(); purge_now(); $prem = (bool)($u && is_prem($u)); $pm = $s['premium'] ?? [];
    $free = [];
    foreach (qn("SELECT speed,SUM(order_token IS NULL AND " . live() . ") FROM configs WHERE speed LIKE 'offer:%' GROUP BY 1") as $r) $free[$r[0]] = (int)$r[1];
    $offers = [];
    foreach (qa('SELECT * FROM offers WHERE active=1 ORDER BY id DESC') as $r) {
        if (!offer_allowed($r, $u)) continue;   // an offer limited to chosen accounts is invisible to everybody else
        $fr = $free['offer:' . $r['id']] ?? 0;
        $row = ['id' => (int)$r['id'], 'title' => $r['title'], 'descr' => $r['descr'], 'price' => offer_price($r), 'kind' => $r['kind'] ?: 'offer', 'prem' => $r['prem'] ? 1 : 0, 'free' => $fr, 'video' => vid_url($r['video'] ?? '')];
        if (offer_shared($r)) {
            $mx = (int)($r['dl_max'] ?? 0); $cl = offer_claimed($r['id']); $had = $u ? offer_had($r['id'], $u['id']) : false;
            $row['shared'] = 1; $row['left'] = $mx > 0 ? max(0, $mx - $cl) : -1;
            $row['free'] = ($fr > 0 && ($mx === 0 || $cl < $mx || $had)) ? 1 : 0;
        }
        $offers[] = $row;
    }
    $banners = [];
    foreach (qa('SELECT * FROM banners WHERE active=1 AND (starts=0 OR starts<=?) AND (ends=0 OR ends>=?) ORDER BY COALESCE(sort,0), id DESC', [$now, $now]) as $r) $banners[] = bn_pub($r);
    $nets = array_values(array_filter($s['networks'], function ($n) use ($prem) { return $prem || empty($n['prem']); }));
    $locked = 0;
    if (!$prem) { foreach ($s['networks'] as $n) if (!empty($n['prem'])) $locked++; foreach ($offers as $o) if ($o['prem']) $locked++; }   // what a free user is missing (teaser only)
    $offers = array_values(array_filter($offers, function ($o) use ($prem) { return $prem || !$o['prem']; }));
    $d = ['name' => $s['name'], 'whatsapp' => $s['whatsapp'], 'youtube' => $s['youtube'], 'networks' => $nets, 'offers' => $offers, 'banners' => $banners, 'videos' => ['pay' => vid_get('pay'), 'coupon' => vid_get('coupon'), 'premium' => vid_get('premium')]];
    $pl = [];
    foreach (($pm['plans'] ?? []) as $x) $pl[] = ['id' => $x['id'], 'label' => $x['label'], 'days' => $x['days'], 'price' => $x['price']];
    $d['premium'] = ['on' => !empty($pm['on']) ? 1 : 0, 'title' => $pm['title'] ?? 'Premium', 'perks' => $pm['perks'] ?? [], 'plans' => $pl, 'active' => $prem ? 1 : 0, 'until' => $prem ? dstr($u['premium_until']) : '', 'locked' => $locked];
    return $d;
}

// Login page: built into the program so visitors who are not logged in get ONLY this (never any site content, whatever index.html says)
$LOGIN_PAGE = <<<'EOT'
<!DOCTYPE html><html lang="sw"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex"><meta name="theme-color" content="#070D0A"><link rel="manifest" href="/manifest.webmanifest"><link rel="apple-touch-icon" href="/icon-192.png"><meta name="apple-mobile-web-app-capable" content="yes"><meta name="mobile-web-app-capable" content="yes"><meta name="apple-mobile-web-app-title" content="Visible Tech"><meta name="application-name" content="Visible Tech"><title>__NAME__ – Ingia</title>
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
<div class="tabs"><button id="t1" class="on">Ingia</button><button id="t2">Jisajili</button></div>
<form id="f1"><input id="li" autocomplete="username" placeholder="Barua pepe au namba ya simu" required><input id="lp" type="password" autocomplete="current-password" placeholder="Nenosiri" required><button class="go" id="b1">Ingia</button></form>
<form id="f2" hidden><input id="sn" autocomplete="name" placeholder="Jina (si lazima)"><input id="se" type="email" autocomplete="email" placeholder="Barua pepe" required><input id="sp" type="tel" autocomplete="tel" placeholder="Namba ya simu (0712345678)" required><input id="sw" type="password" autocomplete="new-password" placeholder="Nenosiri (angalau herufi 8)" required><button class="go" id="b2">Fungua akaunti</button></form>
<div id="msg" role="alert"></div><div class="lock">🔒 Kwa wanachama pekee</div></main>
<script src="/gate.js"></script></body></html>
EOT;
$GATE_JS = <<<'EOT'
const $=i=>document.getElementById(i);if('serviceWorker' in navigator)navigator.serviceWorker.register('/sw.js').catch(()=>{});
function tab(n){$('f1').hidden=n!=1;$('f2').hidden=n!=2;$('t1').className=n==1?'on':'';$('t2').className=n==2?'on':'';$('msg').textContent=''}
async function post(u,b){const r=await fetch(u,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(b)});let d={};try{d=await r.json()}catch(e){}if(!r.ok)throw (d.error||'Kuna hitilafu. Jaribu tena.');return d}
async function run(btn,fn){$('msg').textContent='';btn.disabled=true;try{await fn();location.reload()}catch(m){$('msg').textContent=String(m);btn.disabled=false}}
function doIn(e){e.preventDefault();run($('b1'),()=>post('/api/auth/login',{login:$('li').value.trim(),password:$('lp').value}));return false}
function doUp(e){e.preventDefault();run($('b2'),()=>post('/api/auth/register',{name:$('sn').value,email:$('se').value,phone:$('sp').value,password:$('sw').value}));return false}
$('t1').addEventListener('click',()=>tab(1));$('t2').addEventListener('click',()=>tab(2));$('f1').addEventListener('submit',doIn);$('f2').addEventListener('submit',doUp);
EOT;
function gate_page($s) { global $LOGIN_PAGE; return str_replace('__NAME__', htmlspecialchars((string)$s['name'], ENT_NOQUOTES, 'UTF-8'), $LOGIN_PAGE); }

// ---------------- installable web app (PWA): manifest + service worker + icons, served WITHOUT login ----------------
function icon_png($n) {   // app icon drawn with plain PHP (the visibleTech 3 bars on dark green-black), kept inside the "maskable" safe zone
    static $cache = []; if (isset($cache[$n])) return $cache[$n];
    $u = 0.56 * $n / 26.0; $ox = ($n - 26 * $u) / 2.0; $oy = ($n - 25 * $u) / 2.0;
    $bars = [[2, 18, 6, 10, [11, 138, 62]], [12, 11, 6, 17, [229, 184, 0]], [22, 3, 6, 25, [10, 127, 181]]];
    $rws = '';
    for ($y = 0; $y < $n; $y++) {
        $row = str_repeat(chr(7) . chr(13) . chr(10), $n);
        foreach ($bars as $b) {
            list($x, $y0, $w, $h, $col) = $b;
            if ((int)round($oy + ($y0 - 3) * $u) <= $y && $y < (int)round($oy + ($y0 - 3 + $h) * $u)) {
                $a = (int)round($ox + ($x - 2) * $u); $e = (int)round($ox + ($x - 2 + $w) * $u);
                if ($e > $a) $row = substr_replace($row, str_repeat(chr($col[0]) . chr($col[1]) . chr($col[2]), $e - $a), $a * 3, ($e - $a) * 3);
            }
        }
        $rws .= "\0" . $row;
    }
    $ch = function ($t, $d) { return pack('N', strlen($d)) . $t . $d . pack('N', crc32($t . $d) & 0xffffffff); };
    return $cache[$n] = "\x89PNG\r\n\x1a\n" . $ch('IHDR', pack('NNCCCCC', $n, $n, 8, 2, 0, 0, 0)) . $ch('IDAT', gzcompress($rws, 9)) . $ch('IEND', '');
}
function manifest($s) {
    $nm = (string)$s['name']; $ic = [];
    foreach ([192, 512] as $z) foreach (['any', 'maskable'] as $p) $ic[] = ['src' => '/icon-' . $z . '.png', 'sizes' => $z . 'x' . $z, 'type' => 'image/png', 'purpose' => $p];
    return json_encode(['name' => 'Visible Tech', 'short_name' => 'Visible Tech', 'description' => 'Config za internet na eSIM, papo hapo.', 'id' => '/', 'start_url' => '/', 'scope' => '/', 'display' => 'standalone', 'display_override' => ['standalone', 'minimal-ui'], 'orientation' => 'portrait',
        'background_color' => '#070D0A', 'theme_color' => '#070D0A', 'lang' => 'sw', 'icons' => $ic], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
$SW_JS = <<<'EOT'
// visibleTech service worker: makes the site installable. It caches NOTHING (accounts, orders and configs stay private); only shows a friendly page when the phone is offline.
self.addEventListener('install', e => self.skipWaiting());
self.addEventListener('activate', e => e.waitUntil(self.clients.claim()));
self.addEventListener('fetch', e => {
  const r = e.request;
  if (r.method !== 'GET' || r.mode !== 'navigate') return;
  e.respondWith(fetch(r).catch(() => new Response('<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><body style="background:#070D0A;color:#EAF3EC;font-family:sans-serif;display:grid;place-items:center;min-height:100vh;text-align:center;padding:20px"><div><h2>Hakuna mtandao</h2><p style="color:#97ABA0;margin:8px 0 18px">Washa data au Wi-Fi kisha jaribu tena.</p><button onclick="location.reload()" style="padding:12px 24px;border:0;border-radius:12px;background:#0B8A3E;color:#fff;font-size:16px">Jaribu tena</button></div></body>', {headers: {'Content-Type': 'text/html; charset=utf-8'}})));
});
EOT;

// ---------------- HTTP layer ----------------
function out($code, $body, $ctype = 'application/json', $hdr = []) {
    $b = $ctype === 'application/json' ? json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR) : (string)$body;
    http_response_code($code);
    header('Content-Type: ' . (strpos($ctype, 'image/') === 0 ? $ctype : $ctype . '; charset=utf-8'));
    header('Content-Length: ' . strlen($b)); header('Cache-Control: no-store'); header_remove('X-Powered-By');
    foreach (sec_hdr() as $h) header($h[0] . ': ' . $h[1]);
    foreach ($hdr as $h) header($h[0] . ': ' . $h[1], false);
    echo $b; exit;
}
function ip() { return (BIND === '127.0.0.1' && !empty($_SERVER['HTTP_X_REAL_IP'])) ? $_SERVER['HTTP_X_REAL_IP'] : ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'); }
function origin_ok() {   // CSRF defence in depth: a browser request coming from another site is refused
    if (($_SERVER['HTTP_SEC_FETCH_SITE'] ?? '') === 'cross-site') return false;
    $o = $_SERVER['HTTP_ORIGIN'] ?? ''; if ($o === '') return true;
    $h = parse_url($o); $nl = strtolower(($h['host'] ?? '') . (isset($h['port']) ? ':' . $h['port'] : ''));
    return $nl === strtolower($_SERVER['HTTP_HOST'] ?? '');
}
function form($limit = 20000) {   // public endpoints stay tiny; the logged-in admin may upload big batches of configs
    $n = max(0, min((int)($_SERVER['CONTENT_LENGTH'] ?? 0), $limit));
    $GLOBALS['RAW_B'] = $n > 0 ? (string)stream_get_contents(fopen('php://input', 'rb'), $n) : '';
    $t = ltrim($GLOBALS['RAW_B']);
    if ($t !== '' && $t[0] === '{') { $d = json_decode($GLOBALS['RAW_B'], true); return is_array($d) ? $d : []; }
    parse_str($GLOBALS['RAW_B'], $d); return is_array($d) ? $d : [];
}
function me() {   // logged-in customer (row) or null
    static $m = false; if ($m !== false) return $m;
    $ck = $_COOKIE['vs_user'] ?? ''; if ($ck === '') return $m = null;
    $r = q1('SELECT u.* FROM sessions s JOIN users u ON u.id=s.user_id WHERE s.token=? AND s.expires>?', [tok_hash($ck), time()]);
    return $m = ($r ?: null);
}
function login_ok($u) {   // create session + cookie
    $now = time(); $tok = rand_url(32);
    q('DELETE FROM sessions WHERE expires<?', [$now]);
    q('DELETE FROM sessions WHERE user_id=? AND token NOT IN (SELECT token FROM sessions WHERE user_id=? ORDER BY expires DESC LIMIT 9)', [$u['id'], $u['id']]);   // max 10 devices
    q('INSERT INTO sessions VALUES(?,?,?)', [tok_hash($tok), $u['id'], $now + 2592000]);
    out(200, ['ok' => true, 'user' => pub($u)], 'application/json', [['Set-Cookie', 'vs_user=' . $tok . '; Path=/; HttpOnly; SameSite=Lax; Max-Age=2592000' . (HTTPS ? '; Secure' : '')]]);
}
function auth($s, $p, $f) {   // all /api/auth/* endpoints
    $now = time(); $ip = ip();
    if ($p === '/api/auth/register') {
        if (rl_count('reg:' . $ip, 3600) >= 10) out(429, ['error' => 'Maombi mengi mno. Jaribu tena baadaye.']);
        $email = norm_email($f['email'] ?? ''); $phone = norm_phone($f['phone'] ?? ''); $pw = (string)($f['password'] ?? ''); $name = cut(trim((string)($f['name'] ?? '')), 60);
        if (!$email) out(400, ['error' => 'Barua pepe si sahihi.']);
        if (!$phone) out(400, ['error' => 'Namba ya simu si sahihi.']);
        if (pw_err($pw)) out(400, ['error' => pw_err($pw)]);
        rl_add('reg:' . $ip); $hash = mkpw($pw);
        $lk = lock_on();
        if (q1('SELECT 1 FROM users WHERE email=? OR phone=?', [$email, $phone])) out(409, ['error' => 'Barua pepe au namba hii tayari ina akaunti. Ingia badala yake.']);
        q('INSERT INTO users(email,phone,name,created,last_login,pw_hash) VALUES(?,?,?,?,?,?)', [$email, $phone, $name, $now, $now, $hash]);
        $uid = db()->lastInsertId(); lock_off($lk);
        login_ok(q1('SELECT * FROM users WHERE id=?', [$uid]));
    }
    if ($p === '/api/auth/login') {
        $who = trim((string)($f['login'] ?? '')); $key = norm_email($who) ?: (norm_phone($who) ?: strtolower(cut($who, 60)));
        if (rl_count('lip:' . $ip, 600) >= 8 || rl_count('lk:' . $key, 3600) >= 10) out(429, ['error' => 'Majaribio mengi mno. Subiri dakika chache.']);
        $pw = cut((string)($f['password'] ?? ''), 200);
        $u = q1('SELECT * FROM users WHERE email=? OR phone=?', [norm_email($who) ?: '-', norm_phone($who) ?: '-']);
        if ($u && $u['pw_hash']) $good = checkpw($pw, $u['pw_hash']); else { dummy_check($pw); $good = false; }   // slow hash runs OUTSIDE the lock
        if (!$good) { rl_add('lip:' . $ip); rl_add('lk:' . $key); out(403, ['error' => 'Barua pepe/namba au nenosiri si sahihi.']); }
        $nh = pw_stale($u['pw_hash']) ? mkpw($pw) : null;   // silently upgrade old hashes
        with_lock(function () use ($u, $now, $nh) { q('UPDATE users SET last_login=? WHERE id=?', [$now, $u['id']]); if ($nh) q('UPDATE users SET pw_hash=? WHERE id=?', [$nh, $u['id']]); });
        login_ok($u);
    }
    if ($p === '/api/auth/password') {   // logged-in customer changes own password
        $u = me(); if (!$u) out(401, ['error' => 'login']);
        $new = (string)($f['new'] ?? ''); if (pw_err($new)) out(400, ['error' => pw_err($new)]);
        if (rl_count('lip:' . $ip, 600) >= 8) out(429, ['error' => 'Majaribio mengi mno. Subiri dakika chache.']);
        if (!$u['pw_hash'] || !checkpw((string)($f['old'] ?? ''), $u['pw_hash'])) { rl_add('lip:' . $ip); out(403, ['error' => 'Nenosiri la sasa si sahihi.']); }
        $cur = tok_hash($_COOKIE['vs_user'] ?? ''); $nh = mkpw($new);
        with_lock(function () use ($u, $nh, $cur) { q('UPDATE users SET pw_hash=? WHERE id=?', [$nh, $u['id']]); q('DELETE FROM sessions WHERE user_id=? AND token!=?', [$u['id'], $cur]); });   // other devices are logged out
        out(200, ['ok' => true]);
    }
    if ($p === '/api/auth/logout') {
        with_lock(function () { q('DELETE FROM sessions WHERE token=?', [tok_hash($_COOKIE['vs_user'] ?? '')]); });
        out(200, ['ok' => true], 'application/json', [['Set-Cookie', 'vs_user=; Path=/; HttpOnly; SameSite=Lax; Max-Age=0' . (HTTPS ? '; Secure' : '')]]);
    }
    out(404, ['error' => 'not found']);
}

// ---------------- admin panel ----------------
function admin($s, $sub, $f, $post) {
    $GLOBALS['CSP_ADMIN'] = 1;
    $now = time(); $ip = ip();
    if (($sub === '' || $sub === '/') && !$post) out(200, file_get_contents(ADMIN_HTML), 'text/html');
    if ($sub === '/api/login' && $post) {
        if (rl_count('adm:' . $ip, 600) >= 5 || rl_count('adm:*', 600) >= 30) out(429, ['error' => 'Too many attempts, wait 10 minutes']);
        $pw = cut((string)($f['password'] ?? ''), 200);
        if (eq($f['user'] ?? '', $s['admin_user']) && checkpw($pw, $s['admin_hash'])) {
            q('DELETE FROM admin_sess WHERE expires<?', [$now]);
            if (pw_stale($s['admin_hash'])) { $s['admin_hash'] = mkpw($pw); save($s); }
            $tok = rand_url(32); q('INSERT INTO admin_sess VALUES(?,?)', [tok_hash($tok), $now + 43200]);
            out(200, ['ok' => true], 'application/json', [['Set-Cookie', 'vs_admin=' . $tok . '; Path=/' . $s['admin_path'] . '; HttpOnly; SameSite=Strict; Max-Age=43200' . (HTTPS ? '; Secure' : '')]]);
        }
        rl_add('adm:' . $ip); rl_add('adm:*'); out(403, ['error' => 'Wrong user or password']);
    }
    if (!qv('SELECT 1 FROM admin_sess WHERE token=? AND expires>?', [tok_hash($_COOKIE['vs_admin'] ?? ''), $now])) out(401, ['error' => 'login']);
    if (strpos($sub, '/api/banner/img/') === 0 && !$post) {
        $id = substr($sub, strrpos($sub, '/') + 1); $r = q1('SELECT img FROM banners WHERE id=?', [ctype_digit($id) ? (int)$id : -1]);
        if ($r && preg_match('#^data:(image/\w+);base64,(.*)$#s', (string)$r['img'], $m)) out(200, base64_decode($m[2]), $m[1]);
        out(404, ['error' => 'none']);
    }
    try {
        if ($sub === '/api/state') {
            purge_now(); $st = [];
            foreach (['name', 'whatsapp', 'youtube', 'pay_api_url', 'pay_api_key', 'pay_site', 'webhook_secret', 'admin_user', 'login_required', 'stock_ttl_hours', 'stock_daily_clear', 'stock_clear_hour'] as $k) $st[$k] = $s[$k];
            $st['videos'] = (object)(is_array($s['videos'] ?? null) ? $s['videos'] : []);
            $st['pay_secret_set'] = !empty($s['pay_secret']) ? 1 : 0; $st['pay_api_secret_set'] = !empty($s['pay_api_secret']) ? 1 : 0; $st['pay_live'] = (string)($s['pay_live'] ?? '0');
            $stock = [];
            foreach (qn("SELECT speed,duration,COALESCE(vpn,''),SUM(order_token IS NULL AND " . live() . "),COUNT(*),SUM(order_token IS NULL AND " . expd() . "),MIN(CASE WHEN order_token IS NULL AND expires>" . time() . " THEN expires END),SUM(order_token IS NOT NULL),SUM(order_token IS NULL AND " . pend() . "),MIN(CASE WHEN order_token IS NULL AND COALESCE(starts,0)>" . time() . " THEN starts END) FROM configs GROUP BY 1,2,3") as $r)
                $stock[] = ['speed' => $r[0], 'd' => $r[1], 'vpn' => $r[2], 'free' => (int)$r[3], 'total' => (int)$r[4], 'exp' => (int)$r[5], 'nx' => eat_str($r[6]), 'sold' => (int)$r[7], 'sch' => (int)$r[8], 'ns' => eat_str($r[9])];
            $orders = [];
            foreach (qa('SELECT token,created,package,phone,price,status,vpn,hwid FROM orders ORDER BY created DESC LIMIT 300') as $r)
                $orders[] = ['token' => $r['token'], 't' => date('m-d H:i', (int)$r['created']), 'package' => $r['package'], 'phone' => $r['phone'], 'price' => (int)$r['price'], 'status' => $r['status'], 'vpn' => VPNS[(string)$r['vpn']] ?? '', 'hwid' => $r['hwid'] ?: ''];
            $up = users_page('', 0, 50); $users = $up['users'];
            out(200, array_merge(['version' => 8, 'msg_unread' => (int)qv('SELECT COUNT(*) FROM msgs WHERE seen=0'), 'orders_total' => (int)qv('SELECT COUNT(*) FROM orders')], admin_extra(),
                ['settings' => $st, 'networks' => $s['networks'], 'stock' => $stock, 'orders' => $orders, 'premium' => $s['premium'], 'users' => $users, 'user_total' => $up['total']]));
        }
        if ($sub === '/api/save' && $post) {
            $set = is_array($f['settings'] ?? null) ? $f['settings'] : [];
            foreach (['name', 'whatsapp', 'youtube', 'pay_api_url', 'pay_api_key', 'pay_site', 'login_required'] as $k) if (array_key_exists($k, $set)) $s[$k] = cut(trim((string)$set[$k]), 300);
            if (array_key_exists('stock_ttl_hours', $set)) $s['stock_ttl_hours'] = (int)max(0, min(8760, (float)$set['stock_ttl_hours']));   // default "delete unsold package stock after N hours" for every new upload (0 = never)
            if (array_key_exists('stock_daily_clear', $set)) $s['stock_daily_clear'] = ((string)$set['stock_daily_clear'] === '1') ? '1' : '0';
            if (array_key_exists('stock_clear_hour', $set)) $s['stock_clear_hour'] = max(0, min(23, (int)$set['stock_clear_hour']));
            if (trim((string)($set['pay_secret'] ?? '')) !== '') $s['pay_secret'] = cut(trim((string)$set['pay_secret']), 300);   // empty = keep the saved secret
            if (array_key_exists('pay_live', $set)) $s['pay_live'] = ((string)$set['pay_live'] === '1') ? '1' : '0';
            if (trim((string)($set['pay_api_secret'] ?? '')) !== '') $s['pay_api_secret'] = cut(trim((string)$set['pay_api_secret']), 300);   // EasyPay X-Secret-Key; empty = keep the saved one
            if (array_key_exists('networks', $f)) $s['networks'] = clean_networks($f['networks']);
            if (array_key_exists('premium', $f)) $s['premium'] = clean_premium($f['premium']);
            if (array_key_exists('videos', $set)) $s['videos'] = vid_clean($set['videos']);   // how-to video links (empty = no video shown)
            save($s); out(200, ['ok' => true]);
        }
        if ($sub === '/api/msgcount') {   // the admin page asks this every few seconds: a new customer message shows up as a notification
            $l = q1('SELECT m.id,m.body,u.name,u.email,u.phone FROM msgs m LEFT JOIN users u ON u.id=m.user_id WHERE m.seen=0 ORDER BY m.id DESC LIMIT 1');
            out(200, ['unread' => (int)qv('SELECT COUNT(*) FROM msgs WHERE seen=0'), 'last' => $l ? (int)$l['id'] : 0, 'who' => $l ? (string)($l['name'] ?: ($l['email'] ?: $l['phone'])) : '', 'text' => $l ? cut($l['body'], 80) : '']);
        }
        if ($sub === '/api/msgs') {
            $rows = [];
            foreach (qa('SELECT m.id,m.body,m.created,m.seen,m.replied,m.user_id,u.name,u.email,u.phone FROM msgs m LEFT JOIN users u ON u.id=m.user_id ORDER BY m.id DESC LIMIT 200') as $r)
                $rows[] = ['id' => (int)$r['id'], 'body' => (string)$r['body'], 't' => eat_str($r['created']), 'seen' => (int)$r['seen'], 'replied' => (int)$r['replied'], 'uid' => (int)$r['user_id'], 'who' => (string)($r['name'] ?: ($r['email'] ?: ($r['phone'] ?: 'user'))), 'email' => (string)$r['email'], 'phone' => (string)$r['phone']];
            out(200, ['msgs' => $rows, 'unread' => (int)qv('SELECT COUNT(*) FROM msgs WHERE seen=0')]);
        }
        if ($sub === '/api/msg/read' && $post) { with_lock(function () use ($f) { if (tr($f['all'] ?? 0)) q('UPDATE msgs SET seen=1'); else q('UPDATE msgs SET seen=1 WHERE id=?', [(int)fk($f, 'id')]); }); out(200, ['ok' => true]); }
        if ($sub === '/api/msg/delete' && $post) { with_lock(function () use ($f) { if (tr($f['all_read'] ?? 0)) q('DELETE FROM msgs WHERE seen=1'); else q('DELETE FROM msgs WHERE id=?', [(int)fk($f, 'id')]); }); out(200, ['ok' => true]); }
        if ($sub === '/api/msg/reply' && $post) {   // the answer reaches the customer as a normal notification (bell) in their account
            $id = (int)fk($f, 'id'); $t = cut(trim((string)($f['text'] ?? '')), 1000); if ($t === '') out(400, ['error' => 'Write the reply first']);
            $m = q1('SELECT user_id FROM msgs WHERE id=?', [$id]); if (!$m || !$m['user_id']) out(404, ['error' => 'Message not found']);
            with_lock(function () use ($m, $t, $id) { notify((int)$m['user_id'], 'Jibu kutoka kwa Admin 💬', $t, 'info'); q('UPDATE msgs SET seen=1,replied=1 WHERE id=?', [$id]); });
            out(200, ['ok' => true]);
        }
        if ($sub === '/api/backups') out(200, bk_info($s));
        if ($sub === '/api/backup/now' && $post) { @set_time_limit(0); try { $r = bk_make('manual'); } catch (\Throwable $e) { out(500, ['error' => 'Backup failed: ' . $e->getMessage()]); } out(200, ['ok' => true, 'name' => $r[0], 'size' => $r[1]]); }
        if ($sub === '/api/backup/delete' && $post) { $nm = (string)fk($f, 'name'); if (!bk_name_ok($nm)) out(400, ['error' => 'Bad name']); @unlink(bk_dir() . '/' . $nm); out(200, ['ok' => true]); }
        if ($sub === '/api/backup/settings' && $post) {
            $s['backup_every'] = (int)max(0, min(1440, (int)($f['every'] ?? 30))); $s['backup_keep'] = (int)max(1, min(1000, (int)($f['keep'] ?? 48))); save($s); out(200, ['ok' => true]);
        }
        if (strpos($sub, '/api/backup/get/') === 0 && !$post) {   // download one backup (admin only; the backup folder is outside the web site)
            $nm = rawurldecode((string)substr($sub, strlen('/api/backup/get/'))); $fp = bk_dir() . '/' . $nm;
            if (!bk_name_ok($nm) || !is_file($fp)) out(404, ['error' => 'none']);
            http_response_code(200); header('Content-Type: application/gzip'); header('Content-Length: ' . filesize($fp)); header('Content-Disposition: attachment; filename="' . $nm . '"'); header('Cache-Control: no-store'); header_remove('X-Powered-By');
            foreach (sec_hdr() as $h) header($h[0] . ': ' . $h[1]);
            readfile($fp); exit;
        }
        if ($sub === '/api/backup/restore' && $post) {   // from a backup that is on this server ('name') or from a file the admin uploaded in pieces ('u')
            @set_time_limit(0);
            if ((string)($f['confirm'] ?? '') !== 'RESTORE') out(400, ['error' => 'Confirmation missing']);
            $file = ''; $tmpUp = '';
            if (isset($f['name'])) { $nm = (string)$f['name']; if (!bk_name_ok($nm) || !is_file(bk_dir() . '/' . $nm)) out(404, ['error' => 'Backup not found']); $file = bk_dir() . '/' . $nm; }
            else {
                $u = (string)($f['u'] ?? ''); $part = D . '/.up_' . $u . '.part';
                if (!preg_match('/\A[a-f0-9]{16}\z/', $u) || !is_file($part)) out(404, ['error' => 'Uploaded file not found - upload it again']);
                $tmpUp = D . '/bk_up_' . $u . '.tar.gz'; if (!rename($part, $tmpUp)) out(500, ['error' => 'Cannot read the uploaded file']); $file = $tmpUp;
            }
            try { $r = bk_restore($file, tr($f['files'] ?? 0), (string)($_COOKIE['vs_admin'] ?? '')); }
            catch (\Throwable $e) { if ($tmpUp) @unlink($tmpUp); error_log('restore failed: ' . $e->getMessage()); out(400, ['error' => 'Restore failed: ' . $e->getMessage() . ' (nothing was changed)']); }
            if ($tmpUp) @unlink($tmpUp);
            out(200, ['ok' => true, 'info' => $r]);
        }
        if ($sub === '/api/configs' && $post) {   // 'items' = list of configs (one per uploaded file); 'text' = one config per line. No limit on count or length.
            $items = [];
            if (is_array($f['items'] ?? null)) foreach ($f['items'] as $x) $items[] = trim((string)$x); else foreach (preg_split('/\r\n|\r|\n/', (string)($f['text'] ?? '')) as $l) $items[] = trim($l);
            $items = array_values(array_filter($items, function ($x) { return $x !== ''; }));
            foreach ($items as $i => $x) if (vt_is_file($x)) { $nx = vt_norm($x); if ($nx === null) out(400, ['error' => 'A file is empty, damaged or bigger than 3 MB']); $items[$i] = $nx; }   // uploaded files are checked and stored exactly as they are
            $ex = trim((string)($f['expires'] ?? '')); $exp = $ex !== '' ? eat_ts($ex) : 0;   // valid-until, typed by the admin in EAT
            if ($ex !== '' && !$exp) out(400, ['error' => 'Wrong date / time']);
            if ($exp && $exp <= $now) out(400, ['error' => 'That end time (EAT) is already in the past']);
            $sx = trim((string)($f['starts'] ?? '')); $st0 = $sx !== '' ? eat_ts($sx) : 0;   // "available from" (EAT): before this moment the stock is invisible and cannot be bought
            if ($sx !== '' && !$st0) out(400, ['error' => 'Wrong "available from" date / time']);
            $dn = (int)max(0, min(100000, (float)($f['drip_n'] ?? 0))); $dv = (float)max(0, min(8760, (float)($f['drip_every'] ?? 0))); $de = (int)round($dv * ((($f['drip_unit'] ?? 'h') === 'd') ? 86400 : 3600)); $off = max(0, min(100000000, (int)($f['offset'] ?? 0)));
            if (!$st0 || $dn < 1 || $de < 60) { $dn = 0; $de = 0; }   // step-by-step release needs a start time
            $descr = cut(trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', (string)($f['descr'] ?? ''))), 1000); $showd = ($descr !== '' && tr($f['showd'] ?? 0)) ? 1 : 0;   // note on the config; the customer sees it ONLY when showd = 1
            $vpn = in_array($f['vpn'] ?? '', array_keys(VPNS), true) ? $f['vpn'] : '';   // '' = no lock; free offers / eSIM never ask for an ID
            $speed = (string)fk($f, 'speed'); $dur = (string)fk($f, 'duration'); if (strpos($speed, 'offer:') === 0) $vpn = '';
            $ad = tr($f['autodel'] ?? 0) ? 1 : 0; $n = 0; $ttl = 0;
            if (!$exp && strpos($speed, 'offer:') !== 0) {   // package stock: "delete unsold after N hours / days" - counted from the moment each config becomes available
                $hasTtl = array_key_exists('ttl', $f); $tt = $hasTtl ? (float)$f['ttl'] : (float)($s['stock_ttl_hours'] ?? 0); $un = ($hasTtl && ($f['ttl_unit'] ?? 'h') === 'd') ? 86400 : 3600;
                $secs = (int)round(max(0, min(365 * 86400 / $un, $tt)) * $un);
                if ($secs > 0) { $ttl = $secs; $ad = 1; }
            }
            $plan = [];   // per config: [available from, ends]
            foreach ($items as $i => $x) {
                $a = $st0 ? $st0 + ($dn ? intdiv($off + $i, $dn) * $de : 0) : 0;   // the moment config #i becomes available
                if ($a <= $now) $a = 0;
                $e = $exp ?: ($ttl ? max($now, $a) + $ttl : 0);
                if ($exp && $a && $exp <= $a) out(400, ['error' => 'The end time (EAT) must be AFTER the "available from" time (a config starts at ' . eat_str($a) . ')']);
                $plan[$i] = [$a, $e];
            }
            with_lock(function () use ($items, $plan, $speed, $dur, $vpn, $ad, $descr, $showd, &$n) { tx(function () use ($items, $plan, $speed, $dur, $vpn, $ad, $descr, $showd, &$n) { foreach ($items as $i => $x) $n += add_config($speed, $dur, $x, $vpn, $plan[$i][1], $ad, $plan[$i][0], $descr, $showd); }); });
            out(200, ['added' => $n, 'duplicates' => count($items) - $n, 'ends' => ($plan && $plan[0][1]) ? eat_str($plan[0][1]) : '', 'from' => ($plan && $plan[0][0]) ? eat_str($plan[0][0]) : '']);
        }
        if ($sub === '/api/clearexpired' && $post) { $sp = fk($f, 'speed'); $du = fk($f, 'duration'); with_lock(function () use ($sp, $du, $now) { q('DELETE FROM configs WHERE speed=? AND duration=? AND order_token IS NULL AND expires>0 AND expires<=?', [$sp, $du, $now]); }); out(200, ['ok' => true]); }
        if ($sub === '/api/inventory' && $post) {   // every config of ONE package: remaining / sold / expired, and who bought each sold one
            purge_now();
            $sp = (string)fk($f, 'speed'); $du = (string)fk($f, 'duration'); $view = in_array($f['view'] ?? '', ['free', 'sold', 'expired', 'scheduled', 'all'], true) ? $f['view'] : 'free';
            $pg = max(0, min(100000, (int)($f['page'] ?? 0))); $per = 30; $qq = trim(cut((string)($f['q'] ?? ''), 80));
            $lv = live(); $w = 'c.speed=? AND c.duration=?'; $pr = [$sp, $du];
            if ($view === 'free') $w .= ' AND c.order_token IS NULL AND ' . $lv;
            elseif ($view === 'sold') $w .= (strpos($sp, 'offer:') === 0 ? " AND (c.order_token IS NOT NULL OR EXISTS(SELECT 1 FROM orders x WHERE x.cfg_id=c.id AND x.status='success'))" : ' AND c.order_token IS NOT NULL');
            elseif ($view === 'expired') $w .= ' AND c.order_token IS NULL AND ' . expd();
            elseif ($view === 'scheduled') $w .= ' AND c.order_token IS NULL AND ' . pend();
            if ($qq !== '') { $w .= ' AND (u.email LIKE ? OR u.phone LIKE ? OR u.name LIKE ? OR o.phone LIKE ? OR o.token LIKE ? OR CAST(c.id AS TEXT)=?)'; $lk = '%' . $qq . '%'; array_push($pr, $lk, $lk, $lk, $lk, $qq . '%', $qq); }
            $from = 'FROM configs c LEFT JOIN orders o ON o.token=c.order_token LEFT JOIN users u ON u.id=o.user_id WHERE ' . $w;
            $tot = (int)qv('SELECT COUNT(*) ' . $from, $pr);
            $ord = $view === 'sold' ? 'o.created DESC, c.id DESC' : ($view === 'scheduled' ? 'c.starts ASC, c.id ASC' : 'c.id ' . ($view === 'all' ? 'DESC' : 'ASC'));
            $rows = [];
            foreach (qa('SELECT c.id,c.vpn,c.created,c.expires,c.starts,c.descr,c.showd,c.autodel,c.order_token,LENGTH(c.content) AS len,SUBSTR(c.content,1,200) AS pv,o.created AS oc,o.price,o.status AS os,o.hwid,o.phone AS ophone,o.coupon,u.id AS uid,u.name AS un,u.email AS ue,u.phone AS up,(SELECT COUNT(*) FROM orders x WHERE x.cfg_id=c.id AND x.status=\'success\') AS dlc ' . $from . ' ORDER BY ' . $ord . ' LIMIT ' . $per . ' OFFSET ' . ($pg * $per), $pr) as $r) {
                $sold = $r['order_token'] !== null; $ex = (int)$r['expires'];
                $st = $sold ? ($r['os'] === 'need_id' ? 'reserved' : 'sold') : (($ex > 0 && $ex <= $now) ? 'expired' : ((int)$r['starts'] > $now ? 'scheduled' : 'free'));
                $row = ['id' => (int)$r['id'], 'st' => $st, 'vpn' => VPNS[(string)$r['vpn']] ?? '', 'up' => eat_str($r['created']), 'ends' => $ex ? eat_str($ex) : '', 'from' => (int)$r['starts'] > $now ? eat_str($r['starts']) : '', 'descr' => (string)$r['descr'], 'showd' => (int)$r['showd'], 'auto' => (int)$r['autodel'], 'len' => (int)$r['len'], 'pv' => vt_is_file($r['pv']) ? '📎 ' . (preg_match('/"name":"([^"]*)"/', (string)$r['pv'], $mm) ? $mm[1] : 'file') : cut((string)$r['pv'], 40)];
                $dlc = (int)$r['dlc']; $row['dl'] = max($dlc, $sold ? 1 : 0); $row['dlw'] = [];   // how many times this file was downloaded + the last few people
                if ($dlc > 0) foreach (qa("SELECT u.name,u.email,u.phone FROM orders x JOIN users u ON u.id=x.user_id WHERE x.cfg_id=? AND x.status='success' ORDER BY x.created DESC LIMIT 8", [(int)$r['id']]) as $w2) $row['dlw'][] = (string)($w2['name'] ?: ($w2['email'] ?: $w2['phone']));
                if ($sold) $row['buyer'] = ['t' => eat_str($r['oc']), 'price' => (int)$r['price'], 'os' => (string)$r['os'], 'hwid' => (string)($r['hwid'] ?? ''), 'who' => (string)($r['un'] ?: ($r['ue'] ?: ($r['up'] ?: ($r['ophone'] ?: '')))), 'email' => (string)$r['ue'], 'phone' => (string)($r['up'] ?: $r['ophone']), 'uid' => (int)$r['uid'], 'coupon' => (string)($r['coupon'] ?? ''), 'tok' => substr((string)$r['order_token'], 0, 8)];
                $rows[] = $row;
            }
            $c = qn('SELECT SUM(order_token IS NULL AND ' . $lv . '),SUM(order_token IS NOT NULL),SUM(order_token IS NULL AND ' . expd() . '),COUNT(*),SUM(order_token IS NULL AND ' . pend() . ') FROM configs WHERE speed=? AND duration=?', [$sp, $du])[0];
            $isOf = strpos($sp, 'offer:') === 0; $dl = (int)qv("SELECT COUNT(*) FROM orders WHERE speed=? AND duration=? AND status IN ('success','need_id')", [$sp, $du]);
            $soldN = $isOf ? (int)qv("SELECT COUNT(*) FROM configs c WHERE c.speed=? AND c.duration=? AND (c.order_token IS NOT NULL OR EXISTS(SELECT 1 FROM orders x WHERE x.cfg_id=c.id AND x.status='success'))", [$sp, $du]) : (int)$c[1];
            out(200, ['rows' => $rows, 'total' => $tot, 'page' => $pg, 'per' => $per, 'counts' => ['dl' => $dl, 'free' => (int)$c[0], 'sold' => $soldN, 'expired' => (int)$c[2], 'all' => (int)$c[3], 'scheduled' => (int)$c[4]]]);
        }
        if ($sub === '/api/config/get' && $post) {   // the full text of one config (admin only) so it can be copied
            $r = q1('SELECT content FROM configs WHERE id=?', [(int)fk($f, 'id')]); if (!$r) out(404, ['error' => 'Config not found']);
            out(200, ['content' => cfg_admin($r['content'])]);
        }
        if ($sub === '/api/config/delete' && $post) {   // only an UNSOLD config can be deleted; a sold one belongs to the customer's order
            $id = (int)fk($f, 'id'); $n = 0; $force = tr($f['force'] ?? 0);   // force = also delete a SOLD / downloaded file (the customer keeps the copy inside his own order); one reserved for a customer who has not sent his ID yet is never deleted
            with_lock(function () use ($id, $force, &$n) { $n = q($force ? "DELETE FROM configs WHERE id=? AND (order_token IS NULL OR order_token NOT IN (SELECT token FROM orders WHERE status='need_id'))" : 'DELETE FROM configs WHERE id=? AND order_token IS NULL', [$id])->rowCount(); });
            out(200, ['ok' => true, 'deleted' => $n]);
        }
        if ($sub === '/api/clearold' && $post) {   // delete UNSOLD configs of one package that were uploaded more than N hours ago
            $sp = (string)fk($f, 'speed'); $du = (string)fk($f, 'duration'); $hrs = max(1, min(8760, (int)fk($f, 'hours'))); $n = 0;
            with_lock(function () use ($sp, $du, $hrs, $now, &$n) { $n = q('DELETE FROM configs WHERE speed=? AND duration=? AND order_token IS NULL AND MAX(COALESCE(created,0),COALESCE(starts,0))<?', [$sp, $du, $now - $hrs * 3600])->rowCount(); });
            out(200, ['ok' => true, 'deleted' => $n]);
        }
        if ($sub === '/api/clearsold' && $post) {   // delete the SOLD rows of one package / offer / eSIM (static history); customers keep their copy; ones waiting for the customer's ID stay
            $sp = (string)fk($f, 'speed'); $du = (string)fk($f, 'duration'); $n = 0;
            with_lock(function () use ($sp, $du, &$n) { $n = q("DELETE FROM configs WHERE speed=? AND duration=? AND order_token IS NOT NULL AND order_token NOT IN (SELECT token FROM orders WHERE status='need_id')", [$sp, $du])->rowCount(); });
            out(200, ['ok' => true, 'deleted' => $n]);
        }
        if ($sub === '/api/clearstock' && $post) { $sp = fk($f, 'speed'); $du = fk($f, 'duration'); with_lock(function () use ($sp, $du) { q('DELETE FROM configs WHERE speed=? AND duration=? AND order_token IS NULL', [$sp, $du]); }); out(200, ['ok' => true]); }
        if ($sub === '/api/user/password' && $post) {   // customers can't reset by email, so the admin sets a new password for them
            if (pw_err((string)($f['password'] ?? ''))) out(400, ['error' => 'Password must be 8-200 characters']);
            $id = (int)fk($f, 'id'); $h = mkpw((string)$f['password']);
            with_lock(function () use ($id, $h) { q('UPDATE users SET pw_hash=? WHERE id=?', [$h, $id]); q('DELETE FROM sessions WHERE user_id=?', [$id]); });
            out(200, ['ok' => true]);
        }
        if ($sub === '/api/user/premium' && $post) {   // admin gives / extends / removes Premium for one customer
            $uid = (int)fk($f, 'id'); $days = (int)($f['days'] ?? 0); $until = 0;
            with_lock(function () use ($uid, $days, $f, $now, &$until) {
                $cur = (int)qv('SELECT premium_until FROM users WHERE id=?', [$uid]);
                $until = tr($f['revoke'] ?? 0) ? 0 : max($now, $cur) + max(1, min(3650, $days)) * 86400;
                q('UPDATE users SET premium_until=? WHERE id=?', [$until, $uid]);
                if ($until) notify($uid, 'Premium 👑', sprintf('Premium yako imewashwa hadi %s.', dstr($until)), 'paid');
            });
            out(200, ['ok' => true, 'until' => dstr($until)]);
        }
        if ($sub === '/api/user/delete' && $post) { $id = (int)fk($f, 'id'); with_lock(function () use ($id) { q('DELETE FROM sessions WHERE user_id=?', [$id]); q('DELETE FROM users WHERE id=?', [$id]); }); out(200, ['ok' => true]); }
        if ($sub === '/api/offer/save' && $post) {
            $t = cut(trim((string)($f['title'] ?? '')), 80); if ($t === '') $t = 'New offer'; $d = cut($f['descr'] ?? '', 2000); $ac = tr($f['active'] ?? 0) ? 1 : 0;
            $kind = (($f['kind'] ?? '') === 'esim') ? 'esim' : 'offer'; $pr = $kind === 'esim' ? max(0, (int)($f['price'] ?? 0)) : 0;   // offers are always FREE; eSIM is PAID
            $prem = tr($f['prem'] ?? 0) ? 1 : 0; $oid = 0; $vid = vid_url($f['video'] ?? '');
            $mode = ($kind === 'offer' && ($f['dl_mode'] ?? '') === 'shared') ? 'shared' : 'once';   // shared = same file for MANY people
            $mx = $kind === 'offer' ? max(0, min(1000000, (int)($f['dl_max'] ?? 0))) : 0;            // max PEOPLE (0 = everyone)
            $uids = [];   // "only these accounts": email / phone / user id, one per line; every one must exist
            if ($kind === 'offer') foreach (preg_split('/[\s,;]+/', (string)($f['users_txt'] ?? ''), -1, PREG_SPLIT_NO_EMPTY) as $tg) {
                if (count($uids) >= 500) break;
                $ur = find_user($tg); if (!$ur) out(400, ['error' => 'Customer not found: ' . cut($tg, 60)]);
                $uids[(int)$ur['id']] = 1;
            }
            $dlu = json_encode(array_keys($uids));
            with_lock(function () use ($f, $t, $d, $pr, $ac, $kind, $prem, $now, $mode, $mx, $dlu, $vid, &$oid) {
                if (tr($f['id'] ?? 0)) { $oid = (int)$f['id']; q('UPDATE offers SET title=?,descr=?,price=?,active=?,kind=?,prem=?,dl_mode=?,dl_max=?,dl_users=?,video=? WHERE id=?', [$t, $d, $pr, $ac, $kind, $prem, $mode, $mx, $dlu, $vid, $oid]); }
                else { q('INSERT INTO offers(title,descr,price,active,created,kind,prem,dl_mode,dl_max,dl_users,video) VALUES(?,?,?,?,?,?,?,?,?,?,?)', [$t, $d, $pr, $ac, $now, $kind, $prem, $mode, $mx, $dlu, $vid]); $oid = (int)db()->lastInsertId(); }
            });
            out(200, ['ok' => true, 'id' => $oid]);
        }
        if ($sub === '/api/offer/delete' && $post) { $id = (int)fk($f, 'id'); with_lock(function () use ($id) { q('DELETE FROM offers WHERE id=?', [$id]); q('DELETE FROM configs WHERE speed=? AND order_token IS NULL', ['offer:' . $id]); }); out(200, ['ok' => true]); }
        if ($sub === '/api/banner/save' && $post) {
            $link = cut(trim((string)($f['link'] ?? '')), 300); $link = (strpos($link, '#/') === 0 || strpos($link, 'https://') === 0) ? $link : '';
            $ps = array_values(array_filter(explode(',', (string)($f['pages'] ?? 'all')), function ($x) { return in_array($x, BN_PAGES, true); }));
            $pages = ($ps && count($ps) < count(BN_PAGES)) ? implode(',', $ps) : 'all';
            $bg = preg_match(HEX_RE, (string)($f['bg'] ?? '')) ? $f['bg'] : ''; $fg = preg_match(HEX_RE, (string)($f['fg'] ?? '')) ? $f['fg'] : ''; if (!$bg) $fg = '';
            $srt = max(-999, min(999, (int)($f['sort'] ?? 0)));
            $img = null;   // null = leave the picture as it is
            if (tr($f['img_clear'] ?? 0)) $img = '';
            elseif (tr($f['img_data'] ?? '')) {
                $im = (string)$f['img_data'];
                if (strlen($im) > 450000 || !preg_match(IMG_RE, $im)) out(400, ['error' => 'Picture must be PNG / JPG / WEBP / GIF under ~300KB']);
                $img = $im;
            }
            $an = $f['anim'] ?? null; $dm = $f['dismiss'] ?? null;
            $v = [cut(trim((string)($f['title'] ?? '')), 80), cut(trim((string)($f['text'] ?? '')), 200), in_array($f['place'] ?? '', ['top', 'slider', 'hero', 'hang', 'popup', 'float', 'bottom'], true) ? $f['place'] : 'top',
                  in_array($f['color'] ?? '', ['gold', 'red', 'black', 'green', 'blue', 'pink', 'purple', 'orange', 'teal', 'sunset', 'ocean', 'night'], true) ? $f['color'] : 'gold', strtoupper(cut(trim((string)($f['coupon'] ?? '')), 30)), tr($f['active'] ?? 0) ? 1 : 0,
                  pdate($f['starts'] ?? ''), pdate($f['ends'] ?? '', true), $link, cut(trim((string)($f['btn'] ?? '')), 30), $bg, $fg, $pages,
                  ($an === 0 || $an === '0' || $an === false) ? 0 : 1, ($dm === 0 || $dm === '0' || $dm === false) ? 0 : 1, $srt,
                  in_array($f['style'] ?? '', ['glass', 'outline', 'neon', 'soft', 'stripes', 'pill'], true) ? $f['style'] : '', in_array($f['fx'] ?? '', ['pulse', 'shine', 'bounce', 'glow', 'marquee'], true) ? $f['fx'] : '',
                  in_array($f['freq'] ?? '', ['visit', 'day', 'once'], true) ? $f['freq'] : 'session', max(0, min(60, (int)($f['delay'] ?? 0))), in_array($f['aud'] ?? '', ['premium', 'free'], true) ? $f['aud'] : 'all', tr($f['cd'] ?? 0) ? 1 : 0];
            $bid = 0;
            with_lock(function () use ($f, $v, $img, &$bid) {
                if (tr($f['id'] ?? 0)) { $bid = (int)$f['id']; q('UPDATE banners SET title=?,text=?,place=?,color=?,coupon=?,active=?,starts=?,ends=?,link=?,btn=?,bg=?,fg=?,pages=?,anim=?,dismiss=?,sort=?,style=?,fx=?,freq=?,delay=?,aud=?,cd=? WHERE id=?', array_merge($v, [$bid])); }
                else { q('INSERT INTO banners(title,text,place,color,coupon,active,starts,ends,link,btn,bg,fg,pages,anim,dismiss,sort,style,fx,freq,delay,aud,cd) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)', $v); $bid = (int)db()->lastInsertId(); }
                if ($img !== null) q('UPDATE banners SET img=? WHERE id=?', [$img, $bid]);
            });
            out(200, ['ok' => true, 'id' => $bid]);
        }
        if ($sub === '/api/banner/delete' && $post) { $id = (int)fk($f, 'id'); with_lock(function () use ($id) { q('DELETE FROM banners WHERE id=?', [$id]); }); out(200, ['ok' => true]); }
        if ($sub === '/api/coupon/save' && $post) {
            $code = strtoupper(cut(preg_replace('/[^A-Za-z0-9_-]/', '', (string)($f['code'] ?? '')), 30));
            if ($code === '') out(400, ['error' => 'Code is required (letters/numbers)']);
            $kind = (($f['kind'] ?? '') === 'fix') ? 'fix' : 'pct'; $val = max(0, (int)($f['value'] ?? 0)); if ($kind === 'pct') $val = min(100, $val);
            $aud = in_array($f['audience'] ?? '', ['premium', 'free'], true) ? $f['audience'] : 'all';
            $uids = [];   // "only these customers": typed as email / phone / user id, one per line; every one must exist
            foreach (preg_split('/[\s,;]+/', (string)($f['users_txt'] ?? ''), -1, PREG_SPLIT_NO_EMPTY) as $tg) {
                if (count($uids) >= 200) break;
                $ur = find_user($tg); if (!$ur) out(400, ['error' => 'Customer not found: ' . cut($tg, 60)]);
                $uids[(int)$ur['id']] = 1;
            }
            $items = [];   // "only these items / groups": kind:x | net:id | plan:id | pkg:plan|duration | offer:id | member:id
            foreach (array_slice(is_array($f['items'] ?? null) ? $f['items'] : [], 0, 300) as $x) { $x = (string)$x; if (preg_match('/\A(kind|net|plan|pkg|offer|member):[A-Za-z0-9_|.-]{1,60}\z/', $x)) $items[$x] = 1; }
            $scope = json_encode(['users' => array_keys($uids), 'items' => array_keys($items), 'aud' => $aud]);
            $v = [$code, $kind, $val, tr($f['active'] ?? 0) ? 1 : 0, pdate($f['expires'] ?? '', true), max(0, (int)($f['max_uses'] ?? 0)), $scope, max(0, (int)($f['per_user'] ?? 0)), max(0, (int)($f['min_price'] ?? 0))];
            $dup = q1('SELECT id FROM coupons WHERE code=? COLLATE NOCASE', [$code]);
            if ($dup && (int)$dup['id'] !== (int)($f['id'] ?? 0)) out(400, ['error' => 'Another coupon already uses this code']);
            with_lock(function () use ($f, $v, $code) {
                if (tr($f['id'] ?? 0)) {
                    $old = q1('SELECT code FROM coupons WHERE id=?', [(int)$f['id']]);
                    q('UPDATE coupons SET code=?,kind=?,value=?,active=?,expires=?,max_uses=?,scope=?,per_user=?,min_price=? WHERE id=?', array_merge($v, [(int)$f['id']]));
                    if ($old && strcasecmp($old['code'], $code) !== 0) q('UPDATE orders SET coupon=? WHERE coupon=? COLLATE NOCASE', [$code, $old['code']]);   // keep the use-counts when a code is renamed
                } else q('INSERT INTO coupons(code,kind,value,active,expires,max_uses,scope,per_user,min_price) VALUES(?,?,?,?,?,?,?,?,?)', $v);
            });
            out(200, ['ok' => true]);
        }
        if ($sub === '/api/coupon/delete' && $post) { $id = (int)fk($f, 'id'); with_lock(function () use ($id) { q('DELETE FROM coupons WHERE id=?', [$id]); }); out(200, ['ok' => true]); }
        if ($sub === '/api/notify/send' && $post) {   // text and/or up to 5 files (pictures, PDF, apk, zip ...)
            $t = cut(trim((string)($f['title'] ?? '')), 80); $b = cut(trim((string)($f['body'] ?? '')), 500); $tg = trim((string)($f['target'] ?? ''));
            if ($t === '') out(400, ['error' => 'Title is required']);
            $prep = nf_prepare($f['files'] ?? []);
            $fn = function () use ($t, $b, $tg, $prep) {
                $uid = null;
                if ($tg !== '' && strtolower($tg) !== 'all') { $u = find_user($tg); if (!$u) return false; $uid = $u['id']; }
                notify($uid, $t, $b, 'info'); $nid = (int)db()->lastInsertId();
                if ($prep) nf_store($nid, $prep);
                return true;
            };
            if (!with_lock($fn)) out(400, ['error' => 'User not found (use email, phone or user id)']);
            out(200, ['ok' => true, 'files' => count($prep)]);
        }
        if ($sub === '/api/notify/delete' && $post) {
            $id = (int)fk($f, 'id');
            with_lock(function () use ($id) { nf_purge($id); q('DELETE FROM notif_read WHERE notif_id=?', [$id]); q('DELETE FROM notif_hidden WHERE notif_id=?', [$id]); q('DELETE FROM notifs WHERE id=?', [$id]); });
            out(200, ['ok' => true]);
        }
        if ($sub === '/api/sendconfig' && $post) {   // admin hands a config straight into ONE customer's "Config Zangu" (no payment, no stock, no ID question)
            $u = find_user($f['target'] ?? ''); if (!$u) out(400, ['error' => 'User not found (use email, phone or user id)']);
            $items = [];
            if (is_array($f['items'] ?? null)) foreach (array_slice($f['items'], 0, 30) as $x) $items[] = trim((string)$x); else $items[] = trim((string)($f['config'] ?? ''));
            $items = array_values(array_filter($items, function ($x) { return $x !== '' && strlen($x) <= (vt_is_file($x) ? 4400000 : 400000); }));
            foreach ($items as $i => $x) if (vt_is_file($x)) { $nx = vt_norm($x); if ($nx === null) out(400, ['error' => 'A file is empty, damaged or bigger than 3 MB']); $items[$i] = $nx; }
            if (!$items) out(400, ['error' => 'Paste a config or choose a file first (max 400 KB each)']);
            $cl = function ($v, $n, $d) { $v = cut(trim(str_replace(' — ', ' - ', (string)$v)), $n); return $v !== '' ? $v : $d; };
            $net = $cl($f['network'] ?? '', 40, 'Admin'); $plan = $cl($f['title'] ?? '', 80, 'Config maalum'); $lab = $cl($f['label'] ?? '', 60, 'Imetumwa na admin');
            $pkg = $net . ' — ' . $plan . ' — ' . $lab; $uid = (int)$u['id'];
            with_lock(function () use ($items, $pkg, $net, $uid, $now) {
                tx(function () use ($items, $pkg, $net, $uid, $now) {
                    foreach ($items as $x) {
                        $tok = rand_url(16);
                        q('INSERT INTO orders(token,created,package,speed,duration,network,phone,price,status,config,user_id,hidden) VALUES(?,?,?,?,?,?,?,?,?,?,?,0)', [$tok, $now, $pkg, 'direct', 'direct', $net, '', 0, 'success', $x, $uid]);
                        notify($uid, 'Umetumiwa config 🎁', sprintf('%s: umetumiwa config. Itafute ndani ya "Config Zangu".', $pkg), 'paid', $tok);
                    }
                });
            });
            out(200, ['ok' => true, 'sent' => count($items)]);
        }
        if ($sub === '/api/user/list' && $post) { $r = users_page($f['q'] ?? '', max(0, (int)($f['offset'] ?? 0)), 50); out(200, $r); }
        if ($sub === '/api/user/detail' && $post) {   // everything known about one customer
            $id = (int)fk($f, 'id'); $u = q1('SELECT * FROM users WHERE id=?', [$id]); if (!$u) out(404, ['error' => 'User not found']);
            $ord = [];
            foreach (qa("SELECT token,created,package,speed,phone,price,status,coupon,vpn,hwid,hidden,(CASE WHEN COALESCE(config,'')='' THEN 0 ELSE 1 END) AS hc FROM orders WHERE user_id=? ORDER BY created DESC LIMIT 300", [$id]) as $r)
                $ord[] = ['token' => $r['token'], 't' => eat_str($r['created']), 'package' => $r['package'], 'phone' => $r['phone'] ?: '', 'price' => (int)$r['price'], 'status' => $r['status'], 'coupon' => $r['coupon'] ?: '',
                          'vpn' => VPNS[(string)$r['vpn']] ?? '', 'hwid' => $r['hwid'] ?: '', 'deleted' => $r['hidden'] ? 1 : 0, 'has_config' => (int)$r['hc'], 'direct' => $r['speed'] === 'direct' ? 1 : 0];
            $cnt = []; foreach (qa('SELECT status,COUNT(*) AS c FROM orders WHERE user_id=? GROUP BY status', [$id]) as $r) $cnt[$r['status']] = (int)$r['c'];
            out(200, ['user' => ['id' => (int)$u['id'], 'name' => $u['name'] ?: '', 'email' => $u['email'] ?: '', 'phone' => $u['phone'] ?: '', 'created' => eat_str($u['created']), 'last_login' => $u['last_login'] ? eat_str($u['last_login']) : '',
                                 'premium' => is_prem($u) ? dstr($u['premium_until']) : '', 'google' => !empty($u['google_sub']) ? 1 : 0, 'has_password' => !empty($u['pw_hash']) ? 1 : 0,
                                 'devices' => (int)qv('SELECT COUNT(*) FROM sessions WHERE user_id=? AND expires>?', [$id, $now]),
                                 'spent' => (int)qv("SELECT COALESCE(SUM(price),0) FROM orders WHERE user_id=? AND status='success'", [$id]),
                                 'coupons_used' => (int)qv("SELECT COUNT(*) FROM orders WHERE user_id=? AND COALESCE(coupon,'')!=''", [$id]),
                                 'notifs' => (int)qv('SELECT COUNT(*) FROM notifs WHERE user_id=? AND kind=\'info\'', [$id])], 'counts' => $cnt, 'orders' => $ord]);
        }
        if ($sub === '/api/order/config' && $post) { $o = q1('SELECT config,hwid,vpn FROM orders WHERE token=?', [cut($f['token'] ?? '', 60)]); if (!$o) out(404, ['error' => 'Order not found']); out(200, ['config' => cfg_admin($o['config'] ?: ''), 'hwid' => $o['hwid'] ?: '']); }
        if ($sub === '/api/orders/delete' && $post) {   // admin: delete chosen orders (tokens) or bulk by age + status; used config rows stay, so they are never sold twice
            $toks = array_slice(array_map('strval', is_array($f['tokens'] ?? null) ? $f['tokens'] : []), 0, 2000); $n = 0;
            with_lock(function () use ($toks, $f, &$n) {
                if ($toks) { foreach ($toks as $t) $n += q('DELETE FROM orders WHERE token=?', [$t])->rowCount(); }
                else {
                    $cutoff = time() - max(1, (int)($f['days'] ?? 0)) * 86400;   // at least 1 day, so orders still being paid are safe
                    $st = (string)($f['status'] ?? ''); $w = $st === 'all' ? '' : ($st === 'success' ? " AND status='success'" : " AND status!='success'");
                    $n = q('DELETE FROM orders WHERE created<?' . $w, [$cutoff])->rowCount();
                }
                q("DELETE FROM notifs WHERE kind='pending' AND ref NOT IN (SELECT token FROM orders)");
            });
            out(200, ['deleted' => $n]);
        }
        if ($sub === '/api/confirm' && $post) out(200, ['result' => deliver((string)($f['token'] ?? ''))]);
        if ($sub === '/api/password' && $post) {
            if (!is_string($f['password'] ?? null) || mb_strlen($f['password']) < 10 || mb_strlen($f['password']) > 200) out(400, ['error' => 'Password must be 10-200 characters']);
            $s['admin_hash'] = mkpw($f['password']); save($s); q('DELETE FROM admin_sess'); out(200, ['ok' => true]);
        }
    } catch (\Throwable $e) { error_log('admin error: ' . $e->getMessage()); out(400, ['error' => 'Bad request']); }
    out(404, ['error' => 'not found']);
}

// ---------------- automatic backups (every 30 min by default) + restore ----------------
// A backup = ONE .tar.gz with: the database (consistent snapshot), settings.json, index.html, admin.html, app.js, notification attachments (nf/) and payment maps (sp/).
// It is saved OUTSIDE the web site (default /root/visibleTech-backups, or STORE_BACKUP_DIR) and can be downloaded / restored from the admin panel (menu: Backups).
function bk_dir() {
    static $d = null; if ($d !== null) return $d;
    $c = getenv('STORE_BACKUP_DIR') ?: '/root/visibleTech-backups';
    if (!is_dir($c)) @mkdir($c, 0700, true);
    if (!is_dir($c) || !is_writable($c)) { $c = D . '/backups'; if (!is_dir($c)) @mkdir($c, 0700, true); }
    @chmod($c, 0700); return $d = $c;
}
function bk_name_ok($n) { return (bool)preg_match('/\AvisibleTech-backup-[0-9]{8}-[0-9]{6}(-[a-z][a-z-]{0,20})?\.tar\.gz\z/', (string)$n); }
function bk_list() {
    $o = [];
    foreach (glob(bk_dir() . '/visibleTech-backup-*.tar.gz') ?: [] as $f) {
        $n = basename($f); if (!bk_name_ok($n)) continue;
        $k = preg_match('/\AvisibleTech-backup-[0-9]{8}-[0-9]{6}-([a-z-]+)\.tar\.gz\z/', $n, $m) ? $m[1] : 'auto';
        $o[] = ['name' => $n, 'size' => (int)filesize($f), 't' => eat_str(filemtime($f)), 'kind' => $k];
    }
    usort($o, function ($a, $b) { return strcmp($b['name'], $a['name']); });
    return $o;
}
function bk_info($s) {
    $m = D . '/.last_backup';
    return ['dir' => bk_dir(), 'every' => (int)($s['backup_every'] ?? 30), 'keep' => (int)($s['backup_keep'] ?? 48), 'last' => is_file($m) ? eat_str(filemtime($m)) : '', 'items' => bk_list(), 'free' => (int)@disk_free_space(bk_dir())];
}
function rm_rf($p) {
    if (is_link($p) || is_file($p)) { @unlink($p); return; }
    if (!is_dir($p)) return;
    foreach (scandir($p) as $x) if ($x !== '.' && $x !== '..') rm_rf($p . '/' . $x);
    @rmdir($p);
}
function mv_file($a, $b) { if (@rename($a, $b)) return true; if (@copy($a, $b)) { @unlink($a); return true; } return false; }
function bk_make($tag = '') {   // -> [file name, size]; throws a readable error
    if (!class_exists('PharData')) throw new Exception('the PHP "phar" extension is missing on this server');
    $lk = fopen(D . '/.backup.lock', 'c'); if (!$lk || !flock($lk, LOCK_EX | LOCK_NB)) { if ($lk) fclose($lk); throw new Exception('another backup is running right now'); }
    $tmp = D . '/bk_' . bin2hex(random_bytes(6)); $tar = $tmp . '.tar';
    try {
        if (!@mkdir($tmp, 0700, true)) throw new Exception('cannot create a temporary folder');
        $snap = $tmp . '/store.db';
        try { $c = db(); $c->exec('VACUUM INTO ' . $c->quote($snap)); }   // consistent copy even while customers are buying
        catch (\Throwable $e) { @unlink($snap); with_lock(function () use ($snap) { if (!copy(DBF, $snap)) throw new Exception('cannot copy the database'); }); }
        if (is_file(SETF)) copy(SETF, $tmp . '/settings.json');
        foreach ([HTML => 'index.html', ADMIN_HTML => 'admin.html', APPJS => 'app.js'] as $src => $dst) if (is_file($src)) copy($src, $tmp . '/' . $dst);
        foreach (['nf', 'sp'] as $dn) {
            if (!is_dir(D . '/' . $dn)) continue;
            @mkdir($tmp . '/' . $dn, 0700);
            foreach (scandir(D . '/' . $dn) as $fn) if ($fn !== '.' && $fn !== '..' && is_file(D . '/' . $dn . '/' . $fn)) copy(D . '/' . $dn . '/' . $fn, $tmp . '/' . $dn . '/' . $fn);
        }
        file_put_contents($tmp . '/manifest.json', json_encode(['app' => 'visibleTech-store', 'v' => 1, 'created' => time(), 'created_eat' => eat_str(time()),
            'orders' => (int)qv('SELECT COUNT(*) FROM orders'), 'users' => (int)qv('SELECT COUNT(*) FROM users'), 'configs' => (int)qv('SELECT COUNT(*) FROM configs')]));
        $name = 'visibleTech-backup-' . gmdate('Ymd-His', time() + EAT) . ($tag !== '' ? '-' . $tag : '') . '.tar.gz'; $final = bk_dir() . '/' . $name;
        $ph = new PharData($tar); $ph->buildFromDirectory($tmp); $ph->compress(Phar::GZ); unset($ph);
        if (!is_file($tar . '.gz') || !mv_file($tar . '.gz', $final)) throw new Exception('cannot write into ' . bk_dir());
        @chmod($final, 0600); @touch(D . '/.last_backup');
        if ($tag === '') {   // automatic backups: keep only the newest N (manual / before-restore ones are never deleted automatically)
            $keep = (int)(load()['backup_keep'] ?? 48); $auto = array_values(array_filter(bk_list(), function ($x) { return $x['kind'] === 'auto'; }));
            foreach (array_slice($auto, max(1, $keep)) as $x) @unlink(bk_dir() . '/' . $x['name']);
        }
        return [$name, (int)filesize($final)];
    } finally {
        rm_rf($tmp); @unlink($tar); @unlink($tar . '.gz'); flock($lk, LOCK_UN); fclose($lk);
    }
}
function bk_tick() {   // runs on web requests: when the interval has passed, a backup is started in the background (no cron needed; cron also works: php store.php backup)
    $ev = (int)(load()['backup_every'] ?? 30); if ($ev <= 0) return;
    $m = D . '/.last_backup'; $now = time();
    if (is_file($m) && $now - (int)filemtime($m) < $ev * 60) return;
    $fh = @fopen(D . '/.bk_tick', 'c'); if (!$fh) return;
    if (flock($fh, LOCK_EX | LOCK_NB)) {
        clearstatcache(true, $m);
        if (!is_file($m) || $now - (int)filemtime($m) >= $ev * 60) {
            @touch($m);   // claim this slot first: a burst of visitors starts only ONE backup
            if (function_exists('exec')) @exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' backup >/dev/null 2>&1 &');
        }
        flock($fh, LOCK_UN);
    }
    fclose($fh);
}
function admin_ok() { return (bool)qv('SELECT 1 FROM admin_sess WHERE token=? AND expires>?', [tok_hash($_COOKIE['vs_admin'] ?? ''), time()]); }
function bk_chunk() {   // the admin page uploads a backup file in 4 MB pieces (works with the normal 32 MB request limit)
    if (!admin_ok()) out(401, ['error' => 'login']);
    $u = (string)($_GET['u'] ?? ''); $i = (int)($_GET['i'] ?? 0);
    if (!preg_match('/\A[a-f0-9]{16}\z/', $u)) out(400, ['error' => 'Bad upload id']);
    $len = (int)($_SERVER['CONTENT_LENGTH'] ?? 0); if ($len < 1 || $len > 8 * 1048576) out(400, ['error' => 'Bad piece size']);
    $raw = (string)stream_get_contents(fopen('php://input', 'rb'), $len); if (strlen($raw) !== $len) out(400, ['error' => 'Piece was cut - try again']);
    foreach (glob(D . '/.up_*.part') ?: [] as $old) if (filemtime($old) < time() - 7200) @unlink($old);
    $fp = D . '/.up_' . $u . '.part';
    if ($i === 0) { if (file_put_contents($fp, $raw) === false) out(500, ['error' => 'Cannot save the upload']); @chmod($fp, 0600); }
    else {
        if (!is_file($fp)) out(400, ['error' => 'Upload was not started']);
        if (filesize($fp) + $len > 2 * 1024 * 1048576) out(400, ['error' => 'File is too big (max 2 GB)']);
        if (file_put_contents($fp, $raw, FILE_APPEND) === false) out(500, ['error' => 'Cannot save the upload']);
    }
    clearstatcache(true, $fp); out(200, ['ok' => true, 'size' => (int)filesize($fp)]);
}
function bk_restore($file, $withFiles = false, $adminTok = '') {   // replaces database + settings (+ attachments, + site files if asked) by the backup. A safety backup of the CURRENT state is made first.
    if (!class_exists('PharData')) throw new Exception('the PHP "phar" extension is missing on this server');
    $tmp = D . '/bk_r_' . bin2hex(random_bytes(6));
    try {
        if (!@mkdir($tmp, 0700, true)) throw new Exception('cannot create a temporary folder');
        $ph = new PharData($file); $bn = basename($file); $got = 0;
        foreach (new RecursiveIteratorIterator($ph) as $fi) {   // only known file names are read - nothing else in the archive is ever written to disk
            if ($fi->isDir()) continue;
            $pn = $fi->getPathname(); $pos = strpos($pn, $bn . '/'); if ($pos === false) continue;
            $rel = substr($pn, $pos + strlen($bn) + 1);
            if (!preg_match('/\A(store\.db|settings\.json|index\.html|admin\.html|app\.js|manifest\.json|(nf|sp)\/[A-Za-z0-9_.-]{1,80})\z/', $rel) || strpos($rel, '..') !== false) continue;
            if (strpos($rel, '/') !== false) { $dd = $tmp . '/' . explode('/', $rel)[0]; if (!is_dir($dd)) @mkdir($dd, 0700); }
            if (!copy($pn, $tmp . '/' . $rel)) throw new Exception('cannot read ' . $rel . ' from the backup'); $got++;
        }
        $mf = is_file($tmp . '/manifest.json') ? json_decode((string)file_get_contents($tmp . '/manifest.json'), true) : null;
        if (!$got || !is_file($tmp . '/store.db') || !is_array($mf) || ($mf['app'] ?? '') !== 'visibleTech-store') throw new Exception('this is not a visibleTech Store backup file');
        $t = new PDO('sqlite:' . $tmp . '/store.db'); $t->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        if ((string)$t->query('PRAGMA integrity_check')->fetchColumn() !== 'ok') throw new Exception('the database inside the backup is damaged');
        foreach (['orders', 'configs', 'users', 'sessions'] as $tb) if (!$t->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name=" . $t->quote($tb))->fetchColumn()) throw new Exception('the database inside the backup is incomplete');
        $t = null;
        for ($k = 0; ; $k++) { try { bk_make('before-restore'); break; } catch (\Throwable $e) { if ($k >= 20 || strpos($e->getMessage(), 'another backup') === false) throw new Exception('could not save the current state first (' . $e->getMessage() . ')'); sleep(1); } }
        with_lock(function () use ($tmp, $withFiles) {
            $new = DBF . '.restore'; if (!copy($tmp . '/store.db', $new)) throw new Exception('cannot write the database'); @chmod($new, 0600);
            foreach (['-wal', '-shm', '-journal'] as $x) @unlink(DBF . $x);
            if (!rename($new, DBF)) throw new Exception('cannot replace the database');
            if (is_file($tmp . '/settings.json')) {   // the admin address + login + webhook secret of THIS server always survive a restore (otherwise the admin URL changes and the panel shows "not found")
                $sj = SETF . '.restore';
                $ns = json_decode((string)file_get_contents($tmp . '/settings.json'), true); if (!is_array($ns)) $ns = [];
                $cu = json_decode((string)@file_get_contents(SETF), true); if (!is_array($cu)) $cu = [];
                foreach (['admin_path', 'admin_user', 'admin_hash', 'webhook_secret'] as $kk) if (!empty($cu[$kk])) $ns[$kk] = $cu[$kk];
                if (file_put_contents($sj, json_encode($ns, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)) !== false) { @chmod($sj, 0600); rename($sj, SETF); }
            }
            if ($withFiles) foreach (['index.html' => HTML, 'admin.html' => ADMIN_HTML, 'app.js' => APPJS] as $src => $dst) if (is_file($tmp . '/' . $src)) { copy($tmp . '/' . $src, $dst . '.restore'); @chmod($dst . '.restore', 0644); rename($dst . '.restore', $dst); }
            foreach (['nf', 'sp'] as $dn) {
                if (!is_dir($tmp . '/' . $dn)) continue;
                $dd = D . '/' . $dn; if (!is_dir($dd)) @mkdir($dd, 0700, true);
                foreach (scandir($dd) as $fn) if ($fn !== '.' && $fn !== '..' && is_file($dd . '/' . $fn)) @unlink($dd . '/' . $fn);
                foreach (scandir($tmp . '/' . $dn) as $fn) if ($fn !== '.' && $fn !== '..') { copy($tmp . '/' . $dn . '/' . $fn, $dd . '/' . $fn); @chmod($dd . '/' . $fn, 0600); }
            }
        });
        if ($adminTok !== '') { try { $n = new PDO('sqlite:' . DBF); $n->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION); $n->prepare('INSERT OR REPLACE INTO admin_sess VALUES(?,?)')->execute([tok_hash($adminTok), time() + 43200]); } catch (\Throwable $e) {} }   // the admin who restored stays logged in
        return ['created' => (string)($mf['created_eat'] ?? ''), 'orders' => (int)($mf['orders'] ?? 0), 'users' => (int)($mf['users'] ?? 0), 'configs' => (int)($mf['configs'] ?? 0)];
    } finally { rm_rf($tmp); }
}

// ---------------- customer routes ----------------
function do_get($s, $p) {
    $ap = '/' . $s['admin_path'];
    if ($p === $ap || strpos($p, $ap . '/') === 0) admin($s, (string)substr($p, strlen($ap)), [], false);
    if ($p === '/manifest.webmanifest') out(200, manifest($s), 'application/manifest+json');
    if ($p === '/gate.js') { global $GATE_JS; out(200, $GATE_JS, 'application/javascript'); }
    if ($p === '/sw.js') { global $SW_JS; out(200, $SW_JS, 'application/javascript', [['Service-Worker-Allowed', '/']]); }
    if ($p === '/icon-192.png' || $p === '/icon-512.png') out(200, icon_png((int)substr($p, 6, 3)), 'image/png');
    if ($p === '/' || $p === '/index.html') out(200, me() ? file_get_contents(HTML) : gate_page($s), 'text/html');
    if ($p === '/app.js') {   // the page logic: only a logged-in customer receives it (index.html itself holds no logic)
        if (!me() || !is_file(APPJS)) out(404, ['error' => 'not found']);
        out(200, file_get_contents(APPJS), 'application/javascript');
    }
    if ($p === '/api/auth/config') out(200, ['required' => login_needed($s)]);
    if ($p === '/api/me') { $u = me(); out(200, ['user' => $u ? pub($u) : null]); }
    if ($p === '/api/my-orders') {
        $u = me(); if (!$u) out(401, ['error' => 'login']); $o = [];
        foreach (qa('SELECT token,created,package,price,status,hidden FROM orders WHERE user_id=? ORDER BY created DESC LIMIT 50', [$u['id']]) as $r)
            $o[] = ['token' => $r['token'], 't' => date('Y-m-d H:i', (int)$r['created']), 'package' => $r['package'], 'price' => (int)$r['price'], 'status' => $r['status'], 'hidden' => $r['hidden'] ? 1 : 0];
        out(200, ['orders' => $o]);
    }
    if ($p === '/api/notifications') {
        $u = me(); if (!$u) out(401, ['error' => 'login']); $now = time();
        $rs = with_lock(function () use ($u, $now) {
            foreach (qa("SELECT token,package FROM orders WHERE user_id=? AND status='pending' AND created<?", [$u['id'], $now - 300]) as $r)   // auto-notice for orders waiting too long
                notify($u['id'], 'Oda inasubiri malipo', sprintf('%s bado inasubiri malipo. Kama ulishalipa, subiri kidogo; vinginevyo jaribu tena au wasiliana nasi.', $r['package']), 'pending', $r['token']);
            return qa('SELECT n.*, (SELECT 1 FROM notif_read x WHERE x.notif_id=n.id AND x.user_id=?) AS rd FROM notifs n WHERE ((n.user_id IS NULL AND n.created>=?) OR n.user_id=?) AND NOT EXISTS (SELECT 1 FROM notif_hidden h WHERE h.notif_id=n.id AND h.user_id=?) ORDER BY n.created DESC LIMIT 100',
                      [$u['id'], (int)$u['created'], $u['id'], $u['id']]);
        });
        $items = []; $un = 0; $fm = [];
        if ($rs) foreach (qa('SELECT id,notif_id,fname,ftype,fsize FROM notif_files WHERE notif_id IN (' . implode(',', array_map('intval', array_column($rs, 'id'))) . ') ORDER BY id') as $x)
            $fm[(int)$x['notif_id']][] = ['id' => (int)$x['id'], 'name' => $x['fname'], 'size' => (int)$x['fsize'], 'img' => strpos((string)$x['ftype'], 'image/') === 0 ? 1 : 0];
        foreach ($rs as $r) { $rd = $r['rd'] ? 1 : 0; if (!$rd) $un++; $items[] = ['id' => (int)$r['id'], 'title' => $r['title'], 'body' => $r['body'], 'kind' => $r['kind'], 't' => date('m-d H:i', (int)$r['created']), 'read' => $rd, 'files' => $fm[(int)$r['id']] ?? []]; }
        out(200, ['items' => $items, 'unread' => $un]);
    }
    if (strpos($p, '/api/notif-file/') === 0) {   // attachment of a notification: only the customer it was sent to (or everybody, for a broadcast) can open it
        $u = me(); if (!$u) out(401, ['error' => 'login']);
        $id = substr($p, strrpos($p, '/') + 1);
        $x = q1('SELECT f.id,f.fname,f.ftype,n.user_id AS nu,n.created AS nc FROM notif_files f JOIN notifs n ON n.id=f.notif_id WHERE f.id=?', [ctype_digit($id) ? (int)$id : -1]);
        if (!$x || ($x['nu'] !== null ? $x['nu'] != $u['id'] : $x['nc'] < $u['created'])) out(404, ['error' => 'none']);
        $fp = D . '/nf/' . (int)$x['id']; if (!is_file($fp)) out(404, ['error' => 'none']);
        $img = strpos((string)$x['ftype'], 'image/') === 0;
        out(200, file_get_contents($fp), $x['ftype'], [['Content-Disposition', ($img ? 'inline' : 'attachment') . '; filename="' . $x['fname'] . '"']]);
    }
    if (strpos($p, '/api/config-file/') === 0) {   // the ORIGINAL uploaded file, byte for byte. Only its owner can get it, and only as a download (never rendered by the browser)
        $u = me(); if (!$u) out(401, ['error' => 'login']);
        $o = q1('SELECT status,config,user_id,hidden FROM orders WHERE token=?', [rawurldecode((string)substr($p, strlen('/api/config-file/')))]);
        if (!$o || $o['user_id'] != $u['id'] || $o['hidden'] || $o['status'] !== 'success') out(404, ['error' => 'none']);
        $vp = vt_parts($o['config']); if (!$vp) out(404, ['error' => 'none']);
        http_response_code(200); header('Content-Type: application/octet-stream'); header('Content-Length: ' . strlen($vp[1]));
        header('Content-Disposition: attachment; filename="' . $vp[0] . '"'); header('Cache-Control: no-store'); header_remove('X-Powered-By');
        foreach (sec_hdr() as $h) header($h[0] . ': ' . $h[1]);
        echo $vp[1]; exit;
    }
    if ($p === '/api/my-configs') {
        $u = me(); if (!$u) out(401, ['error' => 'login']); $items = []; $ES = esim_ids(); release_due($u['id']); $OV = []; foreach (qa('SELECT id,video FROM offers') as $x) $OV[(string)$x['id']] = vid_url($x['video'] ?? '');
        foreach (qa("SELECT token,created,package,price,config,cdescr,speed,status,vpn,ready_at FROM orders WHERE user_id=? AND status IN ('success','need_id') AND COALESCE(hidden,0)=0 ORDER BY created DESC LIMIT 500", [$u['id']]) as $r) {
            $offer = strpos((string)$r['speed'], 'offer:') === 0; $parts = array_map('trim', explode(' — ', (string)$r['package'])); $sid = (string)substr((string)$r['speed'], 6);
            if ($offer || count($parts) < 3) { $net = in_array($sid, $ES, true) ? 'eSIM' : 'Offers'; $plan = $r['package']; $label = ''; }
            else { $net = $parts[0]; $plan = $parts[1]; $label = implode(' — ', array_slice($parts, 2)); }
            $row = ['token' => $r['token'], 't' => date('Y-m-d H:i', (int)$r['created']), 'package' => $r['package'], 'price' => (int)$r['price'], 'config' => cfg_pub($r['config'] ?: '', $r['token']), 'descr' => (string)$r['cdescr'],
                    'kind' => $offer ? (in_array($sid, $ES, true) ? 'esim' : 'offer') : 'package', 'net' => $net, 'plan' => $plan, 'label' => $label, 'need_id' => $r['status'] === 'need_id' ? 1 : 0];
            if ($r['status'] === 'success') { if ($offer) { if (!empty($OV[$sid])) $row['ev'] = $OV[$sid]; } else { $iv = vid_get('imp_' . $r['vpn']); if ($iv !== '') $row['iv'] = $iv; } }   // video: how to install / import it
            if ($r['status'] === 'need_id') { $row = array_merge($row, vpn_info($r['vpn'])); if ((int)$r['ready_at'] > time()) { $row['making'] = 1; $row['wait'] = max(1, (int)$r['ready_at'] - time()); } }
            $items[] = $row;
        }
        out(200, ['items' => $items]);
    }
    if (strpos($p, '/api/banner-img/') === 0) {
        if (!me()) out(401, ['error' => 'login']);
        $id = substr($p, strrpos($p, '/') + 1); $r = q1('SELECT img FROM banners WHERE id=?', [ctype_digit($id) ? (int)$id : -1]);
        if ($r && preg_match('#^data:(image/\w+);base64,(.*)$#s', (string)$r['img'], $m)) out(200, base64_decode($m[2]), $m[1]);
        out(404, ['error' => 'none']);
    }
    if ($p === '/api/site') { $u = me(); if (!$u) out(401, ['error' => 'login']); out(200, site_payload($s, $u)); }
    if (strpos($p, '/api/order/status/') === 0) {
        $u = me(); if (!$u) out(401, ['error' => 'login']);
        $tk = substr($p, strrpos($p, '/') + 1); release_due($u['id']); $o = q1('SELECT status,config,cdescr,user_id,vpn,price FROM orders WHERE token=?', [$tk]);
        if (!$o || $o['user_id'] != $u['id']) out(404, ['payment_status' => 'failed']);
        if ($o['status'] === 'pending') {
            $r = pp_poll($s, site_host($s, $_SERVER['HTTP_HOST'] ?? ''), $tk, $o['price']);
            if ($r === 'ok') deliver($tk); elseif ($r === 'failed') fail_order($tk);
            $o = q1('SELECT status,config,cdescr,user_id,vpn FROM orders WHERE token=?', [$tk]);
        }
        out(200, array_merge(['payment_status' => $o['status'], 'config' => cfg_pub($o['config'] ?: '', $tk), 'descr' => (string)$o['cdescr']], $o['status'] === 'need_id' ? vpn_info($o['vpn']) : ($o['status'] === 'success' ? ['iv' => vid_get('imp_' . $o['vpn'])] : [])));
    }
    if (strpos($p, '/r/') === 0) {
        $u = me(); if (!$u) out(302, '', 'text/html', [['Location', '/']]);
        $o = q1('SELECT status,config,cdescr,package,user_id,hidden,speed FROM orders WHERE token=?', [(string)substr($p, 3)]);
        if ($o && ($o['user_id'] != $u['id'] || $o['hidden'])) $o = null;   // someone else's order, or deleted by the customer
        $cfg = ($o && $o['status'] === 'success') ? ($o['config'] ?: '') : '';
        if ($cfg !== '') {
            $isE = strpos((string)$o['speed'], 'offer:') === 0 && in_array((string)substr((string)$o['speed'], 6), esim_ids(), true);
            $vp = vt_parts($cfg); $msg = $vp ? vt_page($vp, substr($p, 3)) : ($isE ? esim_html($cfg) : '<pre style="white-space:pre-wrap;word-break:break-all;background:#111A2E;padding:16px;border-radius:12px">' . hesc($cfg) . '</pre>');
            if ((string)($o['cdescr'] ?? '') !== '') $msg .= '<p style="margin-top:12px;color:#9fb0c8;white-space:pre-wrap">' . hesc($o['cdescr']) . '</p>';
        } else $msg = '<p>Config not available yet (status: ' . hesc($o ? $o['status'] : 'missing') . ').</p>';
        out(200, '<meta name=viewport content="width=device-width,initial-scale=1"><body style="background:#0A0F1C;color:#E8EEF8;font-family:sans-serif;max-width:640px;margin:30px auto;padding:0 16px"><h2>' . hesc($s['name']) . '</h2>' . $msg, 'text/html');
    }
    out(404, ['error' => 'not found']);
}
function do_post($s, $p) {
    if (!origin_ok()) out(403, ['error' => 'Cross-site request blocked']);
    $isAdmin = strpos($p, '/' . $s['admin_path'] . '/') === 0;
    if ($isAdmin && $p === '/' . $s['admin_path'] . '/api/backup/chunk') bk_chunk();
    $f = form($isAdmin ? ADMIN_MAX : 20000);
    if ($isAdmin) admin($s, (string)substr($p, strlen($s['admin_path']) + 1), $f, true);
    if (strpos($p, '/api/auth/') === 0) auth($s, $p, $f);
    if ($p === '/api/notifications/read') {
        $u = me(); if (!$u) out(401, ['error' => 'login']);
        with_lock(function () use ($u) { q('INSERT OR IGNORE INTO notif_read(user_id,notif_id) SELECT ?,id FROM notifs WHERE user_id IS NULL OR user_id=?', [$u['id'], $u['id']]); });
        out(200, ['ok' => true]);
    }
    if ($p === '/api/notifications/delete') {
        $u = me(); if (!$u) out(401, ['error' => 'login']);
        with_lock(function () use ($u, $f) {
            if (tr($f['all'] ?? 0)) q('INSERT OR IGNORE INTO notif_hidden(user_id,notif_id) SELECT ?,id FROM notifs WHERE user_id IS NULL OR user_id=?', [$u['id'], $u['id']]);
            elseif (ctype_digit((string)($f['id'] ?? ''))) q('INSERT OR IGNORE INTO notif_hidden VALUES(?,?)', [$u['id'], (int)$f['id']]);
        });
        out(200, ['ok' => true]);
    }
    if ($p === '/api/my-configs/delete') {
        $u = me(); if (!$u) out(401, ['error' => 'login']);
        $toks = is_array($f['tokens'] ?? null) ? array_map(function ($t) { return cut($t, 60); }, array_slice($f['tokens'], 0, 500)) : [];
        with_lock(function () use ($toks, $u) { foreach ($toks as $t) q("UPDATE orders SET hidden=1 WHERE token=? AND user_id=? AND status='success'", [$t, $u['id']]); });
        out(200, ['ok' => true, 'deleted' => count($toks)]);
    }
    if ($p === '/api/order/hwid') {   // PAID order of a locked VPN app: customer sends HWID / public key -> format checked against that VPN -> config released
        $u = me(); if (!$u) out(401, ['error' => 'login']);
        $ip = ip(); rl_add('hw:' . $ip);
        if (rl_count('hw:' . $ip, 600) > 30) out(429, ['status' => 'error', 'message' => 'Maombi mengi mno. Subiri dakika chache.']);
        $tok = cut($f['token'] ?? '', 60); $v = trim((string)($f['hwid'] ?? '')); $fin = tr($f['finish'] ?? 0);
        $lk = lock_on();
        $o = q1('SELECT * FROM orders WHERE token=? AND user_id=?', [$tok, $u['id']]);
        if ($o && $fin && $o['status'] === 'success') out(200, ['status' => 'success', 'config' => cfg_pub($o['config'], $tok), 'descr' => (string)$o['cdescr'], 'iv' => vid_get('imp_' . $o['vpn'])]);   // already released (page reopened / another tab)
        if (!$o || $o['status'] !== 'need_id') out(400, ['status' => 'error', 'message' => 'Oda hii haihitaji ID.']);
        $inf = vpn_info($o['vpn']); $ra = (int)$o['ready_at'];
        if (!$ra) {   // first time: check the ID, then start the countdown - the config is NOT sent yet
            if ($fin) out(400, ['status' => 'error', 'message' => 'Tuma ID kwanza.']);
            if (!hwid_ok($o['vpn'], $v)) {
                $g = hwid_guess($v);
                $msg = $g ? sprintf('ID hii inaonekana ni ya %s, lakini config yako ni ya %s. Weka %s ya %s.', $g, $inf['app'], $inf['idn'], $inf['app']) : sprintf('%s si sahihi. Weka %s ya %s.', $inf['idn'], $inf['idn'], $inf['app']);
                out(400, ['status' => 'error', 'message' => $msg]);
            }
            q('UPDATE orders SET hwid=?,ready_at=? WHERE token=?', [$v, time() + MAKE_WAIT, $tok]);
            out(200, ['status' => 'making', 'wait' => MAKE_WAIT]);
        }
        $left = $ra - time();
        if ($left > 0) out(200, ['status' => 'making', 'wait' => $left]);   // too early: nothing is released before the time is up
        $cfg = release_hwid($o);
        if ($cfg === null) out(400, ['status' => 'error', 'message' => 'Config haipatikani. Wasiliana nasi.']);
        out(200, ['status' => 'success', 'config' => cfg_pub($cfg, $tok), 'descr' => (string)qv('SELECT cdescr FROM orders WHERE token=?', [$tok]), 'iv' => vid_get('imp_' . $o['vpn'])]);
    }
    if ($p === '/api/msg/send') {   // customer -> admin message (shows up in the admin panel as a notification)
        $u = me(); if (!$u) out(401, ['error' => 'login']);
        $b = cut(trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', (string)($f['body'] ?? ''))), 1000);
        if (mb_strlen($b) < 2) out(400, ['error' => 'Andika ujumbe wako kwanza.']);
        $now = time();
        if ((int)qv('SELECT COUNT(*) FROM msgs WHERE user_id=? AND created>?', [$u['id'], $now - 600]) >= 3 || (int)qv('SELECT COUNT(*) FROM msgs WHERE user_id=? AND created>?', [$u['id'], $now - 86400]) >= 20)
            out(429, ['error' => 'Umetuma jumbe nyingi. Subiri dakika chache kisha ujaribu tena.']);
        with_lock(function () use ($u, $b, $now) { q('INSERT INTO msgs(user_id,body,created,seen,replied) VALUES(?,?,?,0,0)', [$u['id'], $b, $now]); });
        out(200, ['ok' => true]);
    }
    if ($p === '/api/coupon') {
        $cu = me(); if (!$cu) out(401, ['error' => 'login']);
        $ip = ip(); rl_add('cp:' . $ip); if (rl_count('cp:' . $ip, 600) > 30) out(429, ['error' => 'Maombi mengi mno. Subiri dakika chache.']);
        $it = resolve_item($s, $f, $cu);
        if ($it && !empty($it['locked'])) out(403, ['error' => 'Hii ni kwa wanachama wa Premium pekee.']);
        if (!$it) out(400, ['error' => 'Kifurushi hakijapatikana.']);
        if (rl_count('cpf:' . $ip, 600) >= 10 || rl_count('cpf:u' . $cu['id'], 600) >= 10) out(429, ['error' => 'Majaribio mengi mno ya kodi. Subiri dakika chache.']);   // guessing codes is slowed down per customer AND per IP
        list($newp, $k, $err) = apply_coupon(trim((string)($f['code'] ?? '')), $it['price'], $cu, $it);
        if ($err) { rl_add('cpf:' . $ip); rl_add('cpf:u' . $cu['id']); out(400, ['error' => $err]); }
        out(200, ['ok' => true, 'price' => $newp, 'base' => $it['price'], 'label' => $k['kind'] === 'pct' ? sprintf('-%d%%', $k['value']) : sprintf('-%d/=', $k['value'])]);
    }
    if ($p === '/api/buy-direct') {
        $me = me(); if (!$me) out(401, ['status' => 'error', 'message' => 'Tafadhali ingia kwanza.', 'login' => true]);
        $ip = ip(); $now = time(); rl_add('by:' . $ip);
        if (rl_count('by:' . $ip, 600) > 10) out(429, ['status' => 'error', 'message' => 'Maombi mengi mno. Subiri dakika chache.']);
        $it = resolve_item($s, $f, $me);
        if ($it && !empty($it['locked'])) out(403, ['status' => 'error', 'message' => 'Hii ni kwa wanachama wa Premium pekee. Pata Premium kwanza.']);
        if (!$it) out(400, ['status' => 'error', 'message' => 'Kifurushi hakijapatikana.']);
        if (($it['kind'] ?? '') === 'offer') {   // free offer shared by MANY people: nothing is used up, the limit counts PEOPLE
            $of = q1('SELECT * FROM offers WHERE id=?', [(int)$it['oid']]);
            if ($of && offer_shared($of)) {
                $r = claim_shared($of, $me, $it['title']);
                if (!empty($r['err'])) out(200, ['status' => 'error', 'message' => $r['err']]);
                out(200, ['status' => 'success', 'free' => true, 'config' => cfg_pub($r['cfg'], $r['tok']), 'descr' => $r['descr'], 'redirect' => '/r/' . $r['tok']]);
            }
        }
        $phone = preg_replace('/\D/', '', (string)($f['phone'] ?? '')); $code = trim((string)($f['coupon'] ?? '')); $token = rand_url(16);
        $lk = lock_on();
        $price = $it['price']; $kc = null;   // price always comes from the server tables, never from the browser
        if ($code !== '') {   // checked again HERE inside the lock (who, what, limits) - the preview alone is never trusted
            if (rl_count('cpf:' . $ip, 600) >= 10 || rl_count('cpf:u' . $me['id'], 600) >= 10) out(429, ['status' => 'error', 'message' => 'Majaribio mengi mno ya kodi. Subiri dakika chache.']);
            list($price, $kc, $err) = apply_coupon($code, $price, $me, $it);
            if ($err) { rl_add('cpf:' . $ip); rl_add('cpf:u' . $me['id']); out(400, ['status' => 'error', 'message' => $err]); }
        }
        if ($price && (strlen($phone) !== 10 || strpos($phone, '0') !== 0)) out(400, ['status' => 'error', 'message' => 'Namba ya simu si sahihi.']);
        if ($price > 0 && $price < 500) out(400, ['status' => 'error', 'message' => 'Kiasi cha chini cha malipo ni 500/=.']);
        if ($price && ((int)qv("SELECT COUNT(*) FROM orders WHERE phone=? AND status='pending' AND created>?", [$phone, $now - 3600]) >= 3
                       || (int)qv("SELECT COUNT(*) FROM orders WHERE user_id=? AND status='pending' AND created>?", [$me['id'], $now - 600]) >= 5))
            out(429, ['status' => 'error', 'message' => 'Una maombi mengi ya malipo yanayosubiri. Subiri dakika chache kisha ujaribu tena.']);
        purge();
        if (strpos($it['speed'], 'member:') !== 0 && !q1('SELECT 1 FROM configs WHERE speed=? AND duration=? AND order_token IS NULL AND ' . live() . ' LIMIT 1', [$it['speed'], $it['duration']]))
            out(200, ['status' => 'error', 'message' => strpos($it['speed'], 'offer:') === 0 ? ($it['price'] ? 'eSIM hii imeisha kwa sasa.' : 'Ofa hii imeisha kwa sasa.') : 'Kifurushi hiki kimeisha kwa sasa.']);
        q('INSERT INTO orders(token,created,package,speed,duration,network,phone,price,status,user_id,coupon) VALUES(?,?,?,?,?,?,?,?,?,?,?)', [$token, $now, $it['title'], $it['speed'], $it['duration'], $it['network'], $phone, $price, 'pending', $me['id'], $kc ? $kc['code'] : null]);
        if ($kc) q('UPDATE coupons SET used=used+1 WHERE id=?', [$kc['id']]);
        lock_off($lk);
        if (!$price) {
            $st = deliver($token); $oo = q1('SELECT config,cdescr,vpn FROM orders WHERE token=?', [$token]); $cfg = $oo['config'];
            if ($st === 'need_id') out(200, array_merge(['status' => 'success', 'free' => true, 'need_id' => true, 'token' => $token], vpn_info($oo['vpn'])));   // coupon / free package of a locked VPN app: ask the ID before handing the config
            if ($st !== 'success') out(200, ['status' => 'error', 'message' => 'Config zimeisha kwa sasa.']);
            out(200, ['status' => 'success', 'free' => true, 'config' => cfg_pub($cfg, $token), 'descr' => (string)$oo['cdescr'], 'iv' => vid_get('imp_' . $oo['vpn']), 'redirect' => '/r/' . $token]);
        }
        $o = q1('SELECT * FROM orders WHERE token=?', [$token]);
        $err = pay_request($s, $token, $o, site_host($s, $_SERVER['HTTP_HOST'] ?? ''));
        if ($err) { fail_order($token, 'Imeshindikana kutuma ombi la malipo'); out(200, ['status' => 'error', 'message' => $err]); }
        out(200, ['status' => 'success', 'free' => false, 'access_token' => $token, 'redirect' => '/r/' . $token]);
    }
    if (eq($p, '/api/webhook/' . $s['webhook_secret'])) {   // provider -> {order_id, status: success|failed}
        if (sp_on($s)) {   // SonicPesa: X-SonicPesa-Signature = hex HMAC-SHA256(raw body, API secret). Body: {event, order_id (SonicPesa's own id), amount, currency, status: SUCCESS|...}
            $sec = (string)($s['pay_api_secret'] ?? '');
            if ($sec === '') { error_log('SonicPesa webhook refused: API secret is not set in the admin settings'); out(503, ['error' => 'secret not configured']); }
            $sig = strtolower(trim((string)($_SERVER['HTTP_X_SONICPESA_SIGNATURE'] ?? '')));
            if (!eq(hash_hmac('sha256', (string)($GLOBALS['RAW_B'] ?? ''), $sec), $sig)) { error_log('SonicPesa webhook: invalid signature'); out(403, ['error' => 'Invalid signature']); }
            $prov = (string)($f['order_id'] ?? ''); $tok = $prov !== '' ? sp_map_token($prov) : '';
            $st = strtoupper((string)($f['status'] ?? ''));
            if ($tok === '') { error_log('SonicPesa webhook: unknown order ' . $prov); out(200, ['ok' => true]); }
            if ($st === 'SUCCESS') {
                $o = q1('SELECT price FROM orders WHERE token=?', [$tok]);
                if ($o && strtoupper((string)($f['currency'] ?? 'TZS')) === 'TZS' && (float)($f['amount'] ?? 0) >= (float)$o['price']) deliver($tok);
                elseif ($o) error_log('SonicPesa webhook: amount/currency does not cover the price, order ' . substr($tok, 0, 6) . '... NOT delivered (got ' . ($f['amount'] ?? '?') . ' ' . ($f['currency'] ?? '?') . ')');
            } elseif (in_array($st, ['CANCELLED', 'CANCELED', 'REJECTED', 'FAILED'], true)) fail_order($tok);
            out(200, ['ok' => true]);
        }
        if (ep_on($s)) {   // EasyPay: X-Webhook-Signature = hex HMAC-SHA256(raw body, webhook signing secret). Events: TRANSACTION_COMPLETED / TRANSACTION_FAILED. No polling API exists, so the signature IS the proof.
            $sec = (string)($s['pay_secret'] ?? '');
            if ($sec === '') { error_log('EasyPay webhook refused: webhook signing secret is not set in the admin settings'); out(503, ['error' => 'webhook secret not configured']); }
            $sig = strtolower(trim((string)($_SERVER['HTTP_X_WEBHOOK_SIGNATURE'] ?? '')));
            if (!eq(hash_hmac('sha256', (string)($GLOBALS['RAW_B'] ?? ''), $sec), $sig)) { error_log('EasyPay webhook: invalid signature'); out(403, ['error' => 'Invalid signature']); }
            $ev = (string)($_SERVER['HTTP_X_WEBHOOK_EVENT'] ?? ($f['eventType'] ?? ''));
            if (!empty($f['test'])) out(200, ['ok' => true, 'ignored' => 'test event']);   // simulated test-events never deliver anything
            if (strpos($ev, 'DISBURSEMENT') === 0) out(200, ['ok' => true]);   // payout callbacks are not customer orders
            $dd = is_array($f['data'] ?? null) ? $f['data'] : [];
            $tok = (string)($dd['merchantReference'] ?? ''); if ($tok === '' && is_array($dd['metadata'] ?? null)) $tok = (string)($dd['metadata']['orderId'] ?? '');
            $st = strtoupper((string)($dd['state'] ?? ''));
            if ($tok !== '') {
                if ($ev === 'TRANSACTION_COMPLETED' && $st === 'COMPLETED') {
                    if (($s['pay_live'] ?? '0') !== '1') { error_log('EasyPay webhook: LIVE mode is OFF -> completed event for order ' . substr($tok, 0, 6) . '... NOT delivered (simulated payments move no money). Turn on "Live" in admin Settings only after EasyPay promotes the application to live.'); out(200, ['ok' => true, 'ignored' => 'live mode off']); }
                    $o = q1('SELECT price FROM orders WHERE token=?', [$tok]);
                    if ($o && strtoupper((string)($dd['currency'] ?? 'TZS')) === 'TZS' && (float)($dd['amount'] ?? 0) >= (float)$o['price']) deliver($tok);
                    elseif ($o) error_log('EasyPay webhook: amount/currency does not cover the price, order ' . substr($tok, 0, 6) . '... NOT delivered (got ' . ($dd['amount'] ?? '?') . ' ' . ($dd['currency'] ?? '?') . ')');
                } elseif ($ev === 'TRANSACTION_FAILED' || in_array($st, ['FAILED', 'CANCELLED'], true)) fail_order($tok);
            }
            out(200, ['ok' => true]);
        }
        if (!pp_on($s)) {   // generic provider: the URL secret alone is NOT proof of payment -> signed body + amount are mandatory
            if (empty($s['pay_secret'])) { error_log('Webhook refused: no payment provider with a signing secret is configured'); out(503, ['error' => 'webhook secret not configured']); }
            $sig0 = str_replace('sha256=', '', strtolower(trim((string)($_SERVER['HTTP_X_PETERPAY_SIGNATURE'] ?? ($_SERVER['HTTP_X_SIGNATURE'] ?? '')))));
            if (!eq(hash_hmac('sha256', (string)($GLOBALS['RAW_B'] ?? ''), $s['pay_secret']), $sig0)) out(403, ['error' => 'Invalid signature']);
        }
        if (pp_on($s) && !empty($s['pay_secret'])) {   // PeterPay signs every webhook: X-PeterPay-Signature = HMAC-SHA256(raw body, secret key)
            $sig = str_replace('sha256=', '', strtolower(trim((string)($_SERVER['HTTP_X_PETERPAY_SIGNATURE'] ?? ''))));
            if (!eq(hash_hmac('sha256', (string)($GLOBALS['RAW_B'] ?? ''), $s['pay_secret']), $sig)) out(403, ['error' => 'Invalid signature']);
        }
        $dd = is_array($f['data'] ?? null) ? $f['data'] : [];
        $g = function ($k) use ($f, $dd) { return tr($f[$k] ?? '') ? $f[$k] : (tr($dd[$k] ?? '') ? $dd[$k] : ''); };
        $ev = (string)($g('event') !== '' ? $g('event') : $g('type'));
        if (strpos($ev, 'payout') === 0) out(200, ['ok' => true]);   // payout events are not customer orders
        $tok = (string)$g('order_id'); $st = strtolower((string)($g('payment_status') !== '' ? $g('payment_status') : $g('status')));
        if (in_array($st, ['success', 'completed', 'paid'], true)) {   // never trust the webhook alone: PeterPay -> ask its API; others -> amount must cover the price
            $o = q1('SELECT price FROM orders WHERE token=?', [$tok]);
            if ($o) {
                if (pp_on($s)) { @unlink(D . '/pp/' . sha1($tok)); $ok = pp_poll($s, site_host($s, $_SERVER['HTTP_HOST'] ?? ''), $tok, $o['price'], 3) === 'ok'; }   // 3s: PeterPay needs HTTP 200 within 5s; if missed, customer status polling delivers
                else { $amt = (float)$g('amount'); $ok = $g('amount') !== '' && $amt >= $o['price']; }   // amount must be present AND cover the price
                if ($ok) deliver($tok);
            }
        } elseif (in_array($st, ['failed', 'cancelled', 'canceled', 'expired'], true)) fail_order($tok);
        out(200, ['ok' => true]);
    }
    out(404, ['error' => 'not found']);
}
function handle() {
    $s = load(); $p = explode('?', (string)($_SERVER['REQUEST_URI'] ?? '/'), 2)[0];
    bk_tick();
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') do_post($s, $p); else do_get($s, $p);
}

// ---------------- command line (used by the install.sh menu) ----------------
function plan_index($s, $speed, $dur) {   // -> [network idx, plan idx, row idx] or null
    foreach ($s['networks'] as $ni => $n) foreach ($n['plans'] as $pi => $p) if ($p['id'] === (string)$speed) foreach ($p['rows'] as $ri => $r) if ($r['d'] === (string)$dur) return [$ni, $pi, $ri];
    return null;
}
function flat_index($s) { $o = []; foreach ($s['networks'] as $ni => $n) foreach ($n['plans'] as $pi => $p) foreach ($p['rows'] as $ri => $r) $o[] = [$ni, $pi, $ri]; return $o; }
function cli($a) {
    $s = load(); $cmd = $a[0] ?? 'serve';
    switch ($cmd) {
        case 'serve':
            if (!getenv('PHP_CLI_SERVER_WORKERS')) putenv('PHP_CLI_SERVER_WORKERS=8');
            $args = ['-d', 'post_max_size=32M', '-d', 'memory_limit=512M', '-d', 'max_execution_time=120', '-d', 'display_errors=0', '-d', 'log_errors=1', '-S', BIND . ':' . PORT, __FILE__];
            if (function_exists('pcntl_exec')) pcntl_exec(PHP_BINARY, $args);
            passthru(escapeshellarg(PHP_BINARY) . ' ' . implode(' ', array_map('escapeshellarg', $args)));
            break;
        case 'stock':
            foreach (qa('SELECT speed,duration,SUM(order_token IS NULL) free,COUNT(*) total FROM configs GROUP BY 1,2') as $r) printf("%-10s %-10s free=%s / %s\n", $r['speed'], $r['duration'], $r['free'], $r['total']);
            break;
        case 'orders':
            foreach (qa('SELECT token,created,package,phone,price,status FROM orders ORDER BY created DESC LIMIT 25') as $r) printf("%s  %s  %-26s %-11s %6s  %s\n", $r['token'], date('m-d H:i', (int)$r['created']), $r['package'], $r['phone'], $r['price'], $r['status']);
            break;
        case 'users':
            foreach (qa('SELECT id,email,phone,name,created FROM users ORDER BY created DESC') as $r) echo $r['id'], ' ', $r['email'], ' ', $r['phone'], ' ', $r['name'], ' ', date('m-d H:i', (int)$r['created']), "\n";
            break;
        case 'confirm': echo deliver($a[1] ?? ''), "\n"; break;
        case 'addconfigs':   // FOLDER -> every file inside = one config | FILE + 'file' -> whole file = one config | FILE -> one config per line
            $items = []; $src = $a[1] ?? '';
            if (is_dir($src)) { $fl = scandir($src); sort($fl); foreach ($fl as $fn) if (is_file($src . '/' . $fn)) $items[] = (string)file_get_contents($src . '/' . $fn); }
            elseif (($a[4] ?? '') === 'file') $items[] = (string)file_get_contents($src);
            else $items = preg_split('/\r\n|\r|\n/', (string)file_get_contents($src));
            $items = array_values(array_filter(array_map('trim', $items), function ($x) { return $x !== ''; })); $n = 0;
            tx(function () use ($items, $a, &$n) { foreach ($items as $x) $n += add_config($a[2], $a[3], $x); });
            echo 'added ', $n, ' of ', count($items), "\n";
            break;
        case 'price':
            $i = plan_index($s, $a[1] ?? '', $a[2] ?? ''); if (!$i) { fwrite(STDERR, "no such speed/duration\n"); exit(1); }
            $s['networks'][$i[0]]['plans'][$i[1]]['rows'][$i[2]]['price'] = (int)$a[3]; save($s); echo "ok\n";
            break;
        case 'list':   // numbered list of every package
            foreach (flat_index($s) as $k => $i) { $n = $s['networks'][$i[0]]; $p = $n['plans'][$i[1]]; $r = $p['rows'][$i[2]];
                printf("%2d) %-9s %-14s | %-10s %-10s | %7s%s\n", $k + 1, $n['name'], $p['title'], $r['label'], $r['small'], $r['price'], $r['best'] ? '  [recommended]' : ''); }
            break;
        case 'ids':   // ids to type when adding configs from the menu
            foreach ($s['networks'] as $n) foreach ($n['plans'] as $p) foreach ($p['rows'] as $r) printf("%-10s speed-id=%-10s duration-id=%-10s  (%s - %s)\n", $n['name'], $p['id'], $r['d'], $p['title'], $r['label']);
            break;
        case 'edit':   // edit N price|label|small|best VALUE
            $fl = flat_index($s); $i = $fl[(int)($a[1] ?? 0) - 1] ?? null; $f = $a[2] ?? ''; $v = $a[3] ?? ''; if (!$i) { fwrite(STDERR, "no such package number\n"); exit(1); }
            $row = &$s['networks'][$i[0]]['plans'][$i[1]]['rows'][$i[2]];
            if ($f === 'price') $row['price'] = (int)$v;
            elseif ($f === 'label' || $f === 'small') $row[$f] = $v;
            elseif ($f === 'best') { foreach ($s['networks'][$i[0]]['plans'][$i[1]]['rows'] as $k => $x) $s['networks'][$i[0]]['plans'][$i[1]]['rows'][$k]['best'] = 0; $row['best'] = in_array($v, ['1', 'y', 'yes'], true) ? 1 : 0; }
            else { fwrite(STDERR, "field: price|label|small|best\n"); exit(1); }
            unset($row); save($s); echo "ok\n";
            break;
        case 'plan':   // plan N title|range|footer VALUE   (N = plan number 1,2..)
            $pl = []; foreach ($s['networks'] as $ni => $n) foreach ($n['plans'] as $pi => $p) $pl[] = [$ni, $pi]; $i = $pl[(int)($a[1] ?? 0) - 1] ?? null;
            if (!$i || !in_array($a[2] ?? '', ['title', 'range', 'footer'], true)) { fwrite(STDERR, "field: title|range|footer\n"); exit(1); }
            $s['networks'][$i[0]]['plans'][$i[1]][$a[2]] = $a[3] ?? ''; save($s); echo "ok\n";
            break;
        case 'admin':   // admin [reset] -> prints web panel path/user (and a NEW password when created/reset)
            if (!$s['admin_hash'] || ($a[1] ?? '') === 'reset') { $pw = rand_url(9); $s['admin_hash'] = mkpw($pw); save($s); q('DELETE FROM admin_sess'); echo 'NEWPASS ', $pw, "\n"; }
            echo 'PATH ', $s['admin_path'], "\n", 'USER ', $s['admin_user'], "\n";
            break;
        case 'backup':   // php store.php backup [manual]  (the web server does this by itself every 30 minutes; you may also run it from cron)
            @set_time_limit(0);
            try { $r = bk_make(($a[1] ?? '') === 'manual' ? 'manual' : ''); echo 'saved ', bk_dir(), '/', $r[0], ' (', $r[1], " bytes)\n"; } catch (\Throwable $e) { fwrite(STDERR, 'backup failed: ' . $e->getMessage() . "\n"); exit(1); }
            break;
        case 'backups':
            foreach (bk_list() as $x) printf("%-52s %10d  %s  %s\n", $x['name'], $x['size'], $x['t'], $x['kind']);
            break;
        case 'restore':   // php store.php restore /path/to/backup.tar.gz [files]   - emergency restore when the admin panel is not reachable ('files' = also index.html / admin.html / app.js)
            if (empty($a[1]) || !is_file($a[1])) { fwrite(STDERR, "usage: store.php restore FILE.tar.gz [files]\n"); exit(1); }
            @set_time_limit(0);
            try { $r = bk_restore($a[1], ($a[2] ?? '') === 'files'); echo 'restored backup of ', $r['created'], ' (', $r['orders'], ' orders, ', $r['users'], ' users, ', $r['configs'], " configs)\n"; } catch (\Throwable $e) { fwrite(STDERR, 'restore failed: ' . $e->getMessage() . "\n"); exit(1); }
            break;
        case 'set':
            if (!in_array($a[1] ?? '', ['name', 'whatsapp', 'youtube', 'pay_api_url', 'pay_api_key', 'pay_secret', 'pay_api_secret', 'pay_live', 'pay_site', 'login_required'], true)) { fwrite(STDERR, "unknown key\n"); exit(1); }
            $s[$a[1]] = $a[2] ?? ''; save($s); echo "ok\n";
            break;
        case 'show':
            $x = $s; unset($x['networks'], $x['admin_hash'], $x['pay_secret'], $x['pay_api_secret']); echo json_encode($x, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), "\n";
            foreach ($s['networks'] as $n) foreach ($n['plans'] as $p) { $l = []; foreach ($p['rows'] as $r) $l[] = $r['d'] . '=' . $r['price']; echo $n['name'], ' / ', $p['id'], ' ', implode(' ', $l), "\n"; }
            break;
        default: fwrite(STDERR, "unknown command\n"); exit(1);
    }
}

if (PHP_SAPI === 'cli') { cli(array_slice($argv, 1)); } else { handle(); }
