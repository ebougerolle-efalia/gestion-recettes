# Mise à jour & maintenance

Procédures pour mettre à jour les instances clients, gérer les sauvegardes et
diagnostiquer les problèmes courants.

---

## 1. Mise à jour automatique (webhook GitHub)

Chaque instance expose un endpoint `webhook.php` qui déclenche `deploy.sh` à
chaque `push` sur la branche de production.

### Configuration (une fois par client)

Sur GitHub : **Settings → Webhooks → Add webhook**

| Champ | Valeur |
|---|---|
| Payload URL | `https://dupont.<BASE_DOMAIN>/webhook.php` |
| Content type | `application/json` |
| Secret | la valeur `WEBHOOK_SECRET` du `.env.local` de l'instance |
| Events | *Just the push event* |

Le webhook vérifie la signature HMAC-SHA256, ignore les push sur une autre
branche, puis lance `deploy.sh`. Les logs sont dans `var/log/webhook.log`.

> Le déploiement n'est autorisé que parce que `setup-server.sh` a créé la règle
> sudoers `/etc/sudoers.d/gestion-recettes-dupont` permettant à `www-data`
> d'exécuter le `deploy.sh` de cette instance sans mot de passe.

---

## 2. Mise à jour manuelle

Pour mettre à jour une instance à la main :

```bash
cd /var/www/clients/dupont
sudo -u www-data ./deploy.sh
```

`deploy.sh` enchaîne automatiquement :

1. **Sauvegarde** de la base avant toute modification (en mode update)
2. `git fetch` + `git reset --hard origin/<branche>`
3. `composer install --no-dev --optimize-autoloader`
4. Création **ou** mise à jour du schéma de base (`doctrine:schema:update --force`)
5. `cache:clear` + `cache:warmup` en environnement `prod`
6. Remise des permissions sur `var/`

La branche peut être surchargée : `GIT_BRANCH=develop sudo -u www-data ./deploy.sh`

---

## 3. Mettre à jour TOUS les clients d'un coup

```bash
for dir in /var/www/clients/*/; do
    echo "=== $(basename "$dir") ==="
    (cd "$dir" && sudo -u www-data ./deploy.sh)
done
```

> Le nouveau code est partagé via Git : un seul `git push` met à jour la
> base de code, puis chaque instance se redéploie indépendamment (chacune garde
> sa propre base de données).

---

## 4. Sauvegardes

`deploy.sh` sauvegarde automatiquement la base **avant chaque mise à jour** dans
`var/backups/`, et ne conserve que les **10 dernières**.

### Sauvegarde manuelle d'un client

```bash
cp /var/www/clients/dupont/var/data/recettes.db \
   /var/www/clients/dupont/var/backups/recettes_$(date +%Y%m%d_%H%M%S).db
```

### Sauvegarde de tous les clients (cron quotidien conseillé)

```bash
# /etc/cron.daily/backup-clients  (chmod +x)
#!/bin/bash
DEST="/var/backups/clients/$(date +%Y%m%d)"
mkdir -p "$DEST"
for dir in /var/www/clients/*/; do
    slug=$(basename "$dir")
    [ -f "$dir/var/data/recettes.db" ] && cp "$dir/var/data/recettes.db" "$DEST/${slug}.db"
done
# Purge au-delà de 30 jours
find /var/backups/clients/ -maxdepth 1 -type d -mtime +30 -exec rm -rf {} +
```

### Restauration

```bash
cd /var/www/clients/dupont
sudo -u www-data cp var/backups/recettes_AAAAMMJJ_HHMMSS.db var/data/recettes.db
```

---

## 5. Renouvellement HTTPS

Certbot installe une tâche de renouvellement automatique. Pour vérifier :

```bash
certbot renew --dry-run
systemctl list-timers | grep certbot
```

Aucune action manuelle n'est normalement nécessaire.

---

## 6. Diagnostic rapide

| Symptôme | Vérification |
|---|---|
| Page 502 | `systemctl status php8.*-fpm` puis `systemctl status nginx` |
| Webhook sans effet | `tail -f /var/www/clients/<slug>/var/log/webhook.log` |
| PDF cassé / vide | `docker ps \| grep gotenberg` ; relancer le conteneur si absent |
| Erreur de permissions | `chown -R www-data:www-data var/ public/uploads/` |
| HTTPS expiré | `certbot renew` puis `systemctl reload nginx` |
| Schéma désynchronisé | `sudo -u www-data php bin/console doctrine:schema:update --force` |

### Logs utiles

```bash
# Application
tail -f /var/www/clients/dupont/var/log/prod.log
# Nginx (par client)
tail -f /var/log/nginx/gestion-recettes-dupont_error.log
# Webhook
tail -f /var/www/clients/dupont/var/log/webhook.log
```

---

## 7. Supprimer une instance client

```bash
SLUG=dupont
rm -rf /var/www/clients/$SLUG
rm -f /etc/nginx/sites-enabled/gestion-recettes-$SLUG
rm -f /etc/nginx/sites-available/gestion-recettes-$SLUG
rm -f /etc/sudoers.d/gestion-recettes-$SLUG
systemctl reload nginx
# (optionnel) révoquer le certificat
certbot delete --cert-name $SLUG.<BASE_DOMAIN>
```

> Pense à récupérer une dernière sauvegarde de `var/data/recettes.db` avant
> suppression si le client peut revenir.