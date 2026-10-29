#!/usr/bin/env bash
set -euo pipefail

REPO_URL="https://github.com/PedroGaspdev/cloud-proj-02.git"
REPO_BRANCH="scripts_salvos"
APP_DIR="/opt/cloud-projeto_2"
DATA_DIR="/var/lib/cloud-projeto_2"

export DEBIAN_FRONTEND=noninteractive

# Remove qualquer linha no sources.list que comece com a palavra 'ubuntu' por engano
sed -i '/^ubuntu/d' /etc/apt/sources.list

apt-get update
apt-get install -y apache2 php libapache2-mod-php php-mysql mysql-server git util-linux

if [ -d "$APP_DIR/.git" ]; then
    git -C "$APP_DIR" pull
    git -C "$APP_DIR" checkout "$REPO_BRANCH"
    git -C "$APP_DIR" pull origin "$REPO_BRANCH"
else
    git clone -b "$REPO_BRANCH" "$REPO_URL" "$APP_DIR"
fi

mkdir -p "$DATA_DIR"/{scripts,envs}
chown -R www-data:www-data "$DATA_DIR"

mysql < "$APP_DIR/DB/schema.sql"

WEB_DIR="$APP_DIR/Web"
if [ ! -d "$WEB_DIR" ]; then
    WEB_DIR="$APP_DIR/web"
fi
ln -sfn "$WEB_DIR" /var/www/html/cloud-projeto_2

sed -i 's|DocumentRoot /var/www/html|DocumentRoot /var/www/html/cloud-projeto_2|g' /etc/apache2/sites-available/000-default.conf

install -m 0755 "$APP_DIR/bin/envctl" /usr/local/bin/envctl
echo 'www-data ALL=(root) NOPASSWD: /usr/local/bin/envctl' > /etc/sudoers.d/cloud-projeto_2
chmod 0440 /etc/sudoers.d/cloud-projeto_2

mkdir -p /sys/fs/cgroup/projeto2
echo '+cpu +memory +io' > /sys/fs/cgroup/cgroup.subtree_control
echo '+cpu +memory +io' > /sys/fs/cgroup/projeto2/cgroup.subtree_control

systemctl enable --now apache2 mysql
systemctl restart apache2