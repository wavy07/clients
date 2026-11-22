#!/bin/bash
# visibleTech Store - installer + VPS manager.   bash <(curl -fsSL https://raw.githubusercontent.com/wavy07/clients/main/install.sh)
[ "$EUID" -eq 0 ] || { echo "Run as root."; exit 1; }
REPO="https://raw.githubusercontent.com/wavy07/clients/main"
DIR=/etc/visibleTech-store; BIN=/usr/local/bin/visibleTech-store.py; SVC=visibleTech-store
C0=$'\e[0m'; CG=$'\e[32m'; CR=$'\e[31m'; CY=$'\e[33m'; CC=$'\e[36m'; CB=$'\e[1m'
st() { STORE_DIR=$DIR python3 $BIN "$@"; local r=$?; id vstore >/dev/null 2>&1 && chown -R vstore:vstore $DIR 2>/dev/null; return $r; }
harden_service() {  # run the store as an unprivileged user + sandbox it (works for old installs too)
  id vstore >/dev/null 2>&1 || useradd --system --no-create-home --shell /usr/sbin/nologin vstore
  mkdir -p $DIR; chown -R vstore:vstore $DIR; chmod 700 $DIR; chmod 600 $DIR/*.db $DIR/settings.json 2>/dev/null
  local f=/etc/systemd/system/$SVC.service
  grep -q '^User=vstore' $f || sed -i '/^\[Service\]/a User=vstore\nGroup=vstore\nNoNewPrivileges=true\nPrivateTmp=true\nProtectSystem=strict\nProtectHome=true\nReadWritePaths='"$DIR"'\nProtectKernelTunables=true\nProtectControlGroups=true\nRestrictSUIDSGID=true\nAmbientCapabilities=CAP_NET_BIND_SERVICE\nCapabilityBoundingSet=CAP_NET_BIND_SERVICE' $f
  systemctl daemon-reload
}
dl() {  # download + reject 404 pages
  local t; t=$(mktemp); curl -fsSL --proto '=https' --tlsv1.2 --max-time 30 "$REPO/$1" -o "$t" && [ -s "$t" ] && ! head -c 20 "$t" | grep -q "404" && install -m "$3" -o root -g root "$t" "$2" || { rm -f "$t"; return 1; }; rm -f "$t"; }
installed() { [ -f /etc/systemd/system/$SVC.service ]; }
ensure_php() {  # PeterPay accepts PHP/cURL requests (not Python's): the store sends payment requests through php-cli
  command -v php >/dev/null && php -m 2>/dev/null | grep -qix curl && return 0
  echo "Installing PHP (for PeterPay payments)..."; apt-get update -y >/dev/null 2>&1; DEBIAN_FRONTEND=noninteractive apt-get install -y php-cli php-curl >/dev/null 2>&1 || echo "${CR}Could not install php-cli php-curl - run: apt-get install -y php-cli php-curl${C0}"
}
install_store() {
  read -p "Port for the website [80]: " port; port=${port:-80}
  [[ "$port" =~ ^[0-9]+$ ]] || { echo "${CR}Invalid port${C0}"; return; }
  ss -ltn "( sport = :$port )" | grep -q LISTEN && ! systemctl is-active -q $SVC && { echo "${CR}Port $port is in use (nginx/apache?). Pick another port.${C0}"; return; }
  command -v python3 >/dev/null || { apt-get update -y >/dev/null; apt-get install -y python3 curl >/dev/null; }
  ensure_php
  mkdir -p $DIR
  dl store.py $BIN 755 || { echo "${CR}Cannot download store.py from $REPO${C0}"; return; }
  dl index.html $DIR/index.html 644 || { echo "${CR}Cannot download index.html${C0}"; return; }
  dl admin.html $DIR/admin.html 644 || { echo "${CR}Cannot download admin.html${C0}"; return; }
  cat > /etc/systemd/system/$SVC.service <<EOF
[Unit]
Description=visibleTech Store
After=network.target
[Service]
Environment=STORE_DIR=$DIR
Environment=STORE_PORT=$port
ExecStart=$(command -v python3) $BIN serve
Restart=always
RestartSec=2
LimitNOFILE=65535
[Install]
WantedBy=multi-user.target
EOF
  st show >/dev/null; harden_service; systemctl enable --now $SVC >/dev/null 2>&1; sleep 1
  dl install.sh /usr/local/bin/store 755; ln -sf /usr/local/bin/store /usr/local/bin/vstore   # type: store
  command -v ufw >/dev/null && ufw allow "$port"/tcp >/dev/null 2>&1
  admin_info
  systemctl is-active -q $SVC && echo "${CB}👉 Type  store  anytime to open this menu.${C0}"; echo "${CG}✅ Store running: http://$(curl -s4 ifconfig.me):$port  (open port $port in your provider firewall)${C0}" || journalctl -u $SVC -n 15 --no-pager
}
set_domain() {
  read -p "Domain [tz.visibletech.space]: " dom; dom=${dom:-tz.visibletech.space}
  [[ "$dom" =~ ^[A-Za-z0-9.-]+\.[A-Za-z]{2,}$ ]] || { echo "${CR}Invalid domain${C0}"; return; }
  read -p "Email for the HTTPS certificate: " mail; [ -n "$mail" ] || { echo "${CR}Email required${C0}"; return; }
  local ip dip; ip=$(curl -s4 --max-time 8 ifconfig.me); dip=$(getent ahostsv4 "$dom" | awk 'NR==1{print $1}')
  if [ "$ip" != "$dip" ]; then echo "${CY}⚠️ $dom points to '${dip:-nothing}' but this server is $ip.${C0}"
    echo "   Add an A record  $dom -> $ip  at your DNS, wait a few minutes, then try again."; read -p "Continue anyway? (y/n): " y; [ "$y" = y ] || return; fi
  systemctl stop $SVC; systemctl stop nginx 2>/dev/null
  for p in 80 443; do o=$(ss -H -ltnp "( sport = :$p )" | grep -oP 'users:\(\("\K[^"]+' | head -1)
    [ -n "$o" ] && { echo "${CR}❌ Port $p is used by '$o' (e.g. the HAProxy multiplexer / V2Ray nginx on this VPS).${C0}"
      echo "   The domain needs 80/443 free. Use another VPS for the store, or free those ports first."; systemctl start $SVC; return; }; done
  apt-get update -y >/dev/null 2>&1; apt-get install -y nginx certbot python3-certbot-nginx >/dev/null 2>&1 || { echo "${CR}Could not install nginx/certbot${C0}"; systemctl start $SVC; return; }
  sed -i '/^Environment=STORE_PORT=/d;/^Environment=STORE_BIND=/d' /etc/systemd/system/$SVC.service
  sed -i '/^Environment=STORE_DIR=/a Environment=STORE_PORT=8081\nEnvironment=STORE_BIND=127.0.0.1' /etc/systemd/system/$SVC.service
  cat > /etc/nginx/conf.d/visibleTech-store.conf <<EOF
limit_req_zone \$binary_remote_addr zone=vs_req:10m rate=10r/s;
server {
    listen 80;
    server_name $dom;
    server_tokens off;
    client_max_body_size 1m;
    client_body_timeout 15s;
    client_header_timeout 15s;
    location ~ ^/a_[0-9a-f]+/ {   # admin panel only: big config uploads allowed
        client_max_body_size 25m;
        limit_req zone=vs_req burst=40 nodelay;
        proxy_pass http://127.0.0.1:8081;
        proxy_set_header Host \$host;
        proxy_set_header X-Real-IP \$remote_addr;
        proxy_set_header X-Forwarded-Proto \$scheme;
    }
    location / {
        limit_req zone=vs_req burst=30 nodelay;
        proxy_pass http://127.0.0.1:8081;
        proxy_read_timeout 30s;
        proxy_set_header Host \$host;
        proxy_set_header X-Real-IP \$remote_addr;
        proxy_set_header X-Forwarded-Proto \$scheme;
    }
}
EOF
  harden_service; systemctl start $SVC; systemctl enable --now nginx >/dev/null 2>&1; nginx -t && systemctl reload nginx
  command -v ufw >/dev/null && { ufw allow 80/tcp; ufw allow 443/tcp; } >/dev/null 2>&1
  if certbot --nginx -d "$dom" -m "$mail" --agree-tos --non-interactive --redirect; then echo "$dom" > $DIR/domain
    echo "${CG}✅ Live: https://$dom${C0}  (webhook base is now https://$dom/api/webhook/...)"
  else echo "${CR}Certificate failed (check the DNS record). The site still works on http://$dom${C0}"; fi
}
edit_packages() {
  while true; do
    echo; st list; echo; echo " p) price   n) package name (Siku 7)   w) sub-name (Wiki 1)   r) recommended tag   t) speed title / range / footer   0) back"
    read -p " 👉 " e; case $e in
      p) read -p "Package number: " n; read -p "New price (0 = free): " v; st edit "$n" price "$v" ;;
      n) read -p "Package number: " n; read -p "New name: " v; st edit "$n" label "$v" ;;
      w) read -p "Package number: " n; read -p "New sub-name: " v; st edit "$n" small "$v" ;;
      r) read -p "Package number to mark recommended: " n; st edit "$n" best 1 ;;
      t) read -p "Speed plan number (1 or 2): " n; read -p "Field (title/range/footer): " f; read -p "New value: " v; st plan "$n" "$f" "$v" ;;
      0) return ;;
    esac; done
}
admin_info() {  # prints the web admin address + login; $1 = reset -> new password
  local out base pw; out=$(st admin $1); pw=$(echo "$out" | awk '/^NEWPASS/{print $2}')
  if [ -f $DIR/domain ]; then base="https://$(cat $DIR/domain)"; else base="http://$(curl -s4 --max-time 8 ifconfig.me):$(grep -oP 'STORE_PORT=\K[0-9]+' /etc/systemd/system/$SVC.service)"; fi
  echo "${CB}🔐 Web admin:${C0} ${CY}$base/$(echo "$out" | awk '/^PATH/{print $2}')${C0}"; echo "   User: $(echo "$out" | awk '/^USER/{print $2}')"
  [ -n "$pw" ] && echo "   Password: ${CY}$pw${C0}  (shown once - save it)" || echo "   Password: hidden (menu 14 -> reset to get a new one)"
}
wipe_all() {  # removes the store completely from this VPS (files, nginx site, domain + certificate, and nginx/certbot if nothing else uses them)
  echo "${CR}${CB}⚠ This permanently deletes the store from this VPS:${C0}"
  echo "   service, program, website files, settings, ALL orders and ALL config stock ($DIR),"
  echo "   the nginx site + domain config + HTTPS certificate, and nginx/certbot themselves if no other website uses them"
  read -p "Type DELETE to confirm: " y; [ "$y" = DELETE ] || { echo "Cancelled."; return; }
  local dom="" f o=0
  [ -f $DIR/domain ] && dom=$(cat $DIR/domain)
  [ -z "$dom" ] && dom=$(grep -h -m1 -oP 'server_name\s+\K[^; ]+' /etc/nginx/conf.d/visibleTech-store.conf 2>/dev/null)
  systemctl disable --now $SVC 2>/dev/null
  rm -f /etc/systemd/system/$SVC.service; systemctl daemon-reload
  # domain + certificate
  if [ -n "$dom" ]; then
    command -v certbot >/dev/null && certbot delete --cert-name "$dom" --non-interactive >/dev/null 2>&1
    rm -rf "/etc/letsencrypt/live/$dom" "/etc/letsencrypt/archive/$dom" "/etc/letsencrypt/renewal/$dom.conf"
  fi
  # nginx site files (every place our config could be)
  rm -f /etc/nginx/conf.d/visibleTech-store.conf /etc/nginx/sites-enabled/visibleTech-store* /etc/nginx/sites-available/visibleTech-store*
  # is nginx used by anything else (V2Ray panel, other sites)?  then keep it
  for f in /etc/nginx/conf.d/*.conf /etc/nginx/sites-enabled/*; do [ -e "$f" ] && [ "$(basename "$f")" != default ] && o=1; done
  if command -v nginx >/dev/null; then
    if [ $o = 0 ]; then
      systemctl disable --now nginx 2>/dev/null
      DEBIAN_FRONTEND=noninteractive apt-get purge -y nginx nginx-common nginx-core certbot python3-certbot-nginx >/dev/null 2>&1
      apt-get autoremove -y >/dev/null 2>&1; rm -rf /etc/nginx /var/www/html/index.nginx-debian.html
      [ -d /etc/letsencrypt/live ] && [ -z "$(ls -A /etc/letsencrypt/live 2>/dev/null | grep -v README)" ] && rm -rf /etc/letsencrypt
      echo "   nginx + certbot removed"
    else
      nginx -t >/dev/null 2>&1 && systemctl reload nginx 2>/dev/null
      echo "   ${CY}nginx kept: other sites/configs on this VPS still use it (store site removed from it)${C0}"
    fi
  fi
  rm -rf "$DIR"; rm -f $BIN /usr/local/bin/store /usr/local/bin/vstore; id vstore >/dev/null 2>&1 && userdel vstore 2>/dev/null
  echo "${CG}✅ Everything deleted. Nothing of the store is left on this VPS.${C0}"; exit 0
}
need() { installed || { echo "${CY}Install first (option 1).${C0}"; return 1; }; }
menu() {
  while true; do
    clear; echo "${CB}${CC}╔══════════════════════════════════════╗"; echo "║      visibleTech STORE MANAGER       ║"; echo "╚══════════════════════════════════════╝${C0}"
    systemctl is-active -q $SVC && echo " Status: ${CG}running${C0}" || echo " Status: ${CR}stopped / not installed${C0}"
    echo " 1) Install / Repair      7) Edit packages / prices
 2) Show settings         8) Contact (name / WhatsApp / YouTube)
 3) Add configs (stock)   9) Payment API + webhook URL
 4) Stock                10) Update from GitHub
 5) Latest orders        11) Restart / logs
 6) Confirm order paid   12) Uninstall
 13) Domain + HTTPS
 14) Web admin address / reset password
 15) ${CR}DELETE everything${C0} (script + all files + orders/stock)     0) Exit"
    read -p " 👉 " o
    case $o in
      1) install_store ;;
      2) need && st show ;;
      3) need && { read -p "Folder (each file = 1 config) OR file (one config per line): " f; read -p "Speed (middle/normal): " sp; read -p "Duration id (wiki1/wiki2/mwezi1): " du; [ -e "$f" ] && st addconfigs "$f" "$sp" "$du" || echo "${CR}Not found${C0}"; } ;;
      4) need && st stock ;;
      5) need && st orders ;;
      6) need && { read -p "Order token: " t; st confirm "$t"; } ;;
      7) need && edit_packages ;;
      8) need && { read -p "Key (name/whatsapp/youtube/network): " k; read -p "Value: " v; st set "$k" "$v"; } ;;
      9) need && { read -p "Provider API URL: " u; read -p "API key: " k; st set pay_api_url "$u"; st set pay_api_key "$k"
         echo "Webhook (POST order_id + status=success|failed): http://YOUR-DOMAIN/api/webhook/$(python3 -c "import json;print(json.load(open('$DIR/settings.json'))['webhook_secret'])")"; } ;;
      10) need && { ensure_php; dl store.py $BIN 755 && dl index.html $DIR/index.html 644 && dl admin.html $DIR/admin.html 644 && dl install.sh /usr/local/bin/store 755 && harden_service && systemctl restart $SVC && echo "${CG}Updated (settings, orders and stock kept).${C0}" || echo "${CR}Update failed${C0}"; } ;;
      11) need && { systemctl restart $SVC; journalctl -u $SVC -n 15 --no-pager; } ;;
      12) read -p "Remove service and program? Orders/stock in $DIR are kept (y/n): " y; [ "$y" = y ] && { systemctl disable --now $SVC; rm -f /etc/nginx/conf.d/visibleTech-store.conf /etc/systemd/system/$SVC.service $BIN /usr/local/bin/store /usr/local/bin/vstore; systemctl daemon-reload; } ;;
      13) need && set_domain ;;
      14) need && { admin_info; read -p "Reset the admin password? (y/n): " y; [ "$y" = y ] && admin_info reset; } ;;
      15) wipe_all ;;
      0) exit 0 ;;
    esac
    read -p "Press Enter..." _
  done
}
menu
