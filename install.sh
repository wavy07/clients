#!/bin/bash
# visibleTech Store - installer + VPS manager.   bash <(curl -fsSL https://raw.githubusercontent.com/wavy07/clients/main/install.sh)
[ "$EUID" -eq 0 ] || { echo "Run as root."; exit 1; }
REPO="https://raw.githubusercontent.com/wavy07/clients/main"
DIR=/etc/visibleTech-store; BIN=/usr/local/bin/visibleTech-store.py; SVC=visibleTech-store
C0=$'\e[0m'; CG=$'\e[32m'; CR=$'\e[31m'; CY=$'\e[33m'; CC=$'\e[36m'; CB=$'\e[1m'
st() { STORE_DIR=$DIR python3 $BIN "$@"; }
dl() {  # download + reject 404 pages
  local t; t=$(mktemp); curl -fsSL --max-time 30 "$REPO/$1" -o "$t" && [ -s "$t" ] && ! head -c 20 "$t" | grep -q "404" && install -m "$3" "$t" "$2" || { rm -f "$t"; return 1; }; rm -f "$t"; }
installed() { [ -f /etc/systemd/system/$SVC.service ]; }
install_store() {
  read -p "Port for the website [80]: " port; port=${port:-80}
  [[ "$port" =~ ^[0-9]+$ ]] || { echo "${CR}Invalid port${C0}"; return; }
  ss -ltn "( sport = :$port )" | grep -q LISTEN && ! systemctl is-active -q $SVC && { echo "${CR}Port $port is in use (nginx/apache?). Pick another port.${C0}"; return; }
  command -v python3 >/dev/null || { apt-get update -y >/dev/null; apt-get install -y python3 curl >/dev/null; }
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
  st show >/dev/null; systemctl daemon-reload; systemctl enable --now $SVC >/dev/null 2>&1; sleep 1
  cp "$0" /usr/local/bin/vstore 2>/dev/null && chmod +x /usr/local/bin/vstore
  command -v ufw >/dev/null && ufw allow "$port"/tcp >/dev/null 2>&1
  admin_info
  systemctl is-active -q $SVC && echo "${CG}✅ Store running: http://$(curl -s4 ifconfig.me):$port  (open port $port in your provider firewall)${C0}" || journalctl -u $SVC -n 15 --no-pager
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
server {
    listen 80;
    server_name $dom;
    location / {
        proxy_pass http://127.0.0.1:8081;
        proxy_set_header Host \$host;
        proxy_set_header X-Real-IP \$remote_addr;
        proxy_set_header X-Forwarded-Proto \$scheme;
    }
}
EOF
  systemctl daemon-reload; systemctl start $SVC; systemctl enable --now nginx >/dev/null 2>&1; nginx -t && systemctl reload nginx
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
 14) Web admin address / reset password     0) Exit"
    read -p " 👉 " o
    case $o in
      1) install_store ;;
      2) need && st show ;;
      3) need && { read -p "File with configs (one per line): " f; read -p "Speed (middle/normal): " sp; read -p "Duration id (wiki1/wiki2/mwezi1): " du; [ -f "$f" ] && st addconfigs "$f" "$sp" "$du" || echo "${CR}File not found${C0}"; } ;;
      4) need && st stock ;;
      5) need && st orders ;;
      6) need && { read -p "Order token: " t; st confirm "$t"; } ;;
      7) need && edit_packages ;;
      8) need && { read -p "Key (name/whatsapp/youtube/network): " k; read -p "Value: " v; st set "$k" "$v"; } ;;
      9) need && { read -p "Provider API URL: " u; read -p "API key: " k; st set pay_api_url "$u"; st set pay_api_key "$k"
         echo "Webhook (POST order_id + status=success|failed): http://YOUR-DOMAIN/api/webhook/$(python3 -c "import json;print(json.load(open('$DIR/settings.json'))['webhook_secret'])")"; } ;;
      10) need && { dl store.py $BIN 755 && dl index.html $DIR/index.html 644 && dl admin.html $DIR/admin.html 644 && systemctl restart $SVC && echo "${CG}Updated (settings, orders and stock kept).${C0}" || echo "${CR}Update failed${C0}"; } ;;
      11) need && { systemctl restart $SVC; journalctl -u $SVC -n 15 --no-pager; } ;;
      12) read -p "Remove service and program? Orders/stock in $DIR are kept (y/n): " y; [ "$y" = y ] && { systemctl disable --now $SVC; rm -f /etc/nginx/conf.d/visibleTech-store.conf /etc/systemd/system/$SVC.service $BIN /usr/local/bin/vstore; systemctl daemon-reload; } ;;
      13) need && set_domain ;;
      14) need && { admin_info; read -p "Reset the admin password? (y/n): " y; [ "$y" = y ] && admin_info reset; } ;;
      0) exit 0 ;;
    esac
    read -p "Press Enter..." _
  done
}
menu
