# Vitrine

Page publique de présentation et de demande d'accès. Vit sur le **domaine
racine**, séparée des instances clients qui occupent les sous-domaines :
`exemple.fr` d'un côté, `monclient.exemple.fr` de l'autre. Elle reste donc
debout même si une instance tombe, et une correction de texte ne demande aucun
déploiement applicatif.

## Contenu

| Fichier | Rôle |
|---|---|
| `index.php` | La page. En PHP pour une seule raison : afficher le résultat d'une demande sans dépendre de JavaScript |
| `souscription.php` | Réception du formulaire. Seul point exécutable exposé |
| `mentions-legales.html` | **Squelette à compléter avant mise en ligne** |
| `assets/site.css` | Toute la mise en forme |
| `assets/site.js` | Uniquement l'animation de la balance. Rien d'essentiel n'en dépend |
| `config.example.php` | Modèle de configuration, à recopier en `config.php` |

## Aucune ressource externe

La page ne charge ni police distante, ni framework, ni icône tierce : trois
requêtes au total, toutes locales. Ce n'est pas une coquetterie de performance.
Une page qui collecte des données personnelles et qui appellerait Google Fonts
transmettrait l'adresse IP du visiteur aux États-Unis avant tout consentement —
c'est le motif exact de la condamnation prononcée par le tribunal de Munich en
janvier 2022. Conséquence directe : aucun bandeau de consentement n'est
nécessaire, et les mentions légales peuvent l'affirmer.

**À ne pas défaire.** Ajouter une police distante ou un outil de mesure
d'audience rendrait le bandeau obligatoire et le paragraphe « Traceurs » des
mentions légales mensonger.

## Le nom commercial

Il n'est pas arrêté, la page n'en porte donc aucun — comme le logo, dessiné sans
mot-symbole pour cette raison. Les deux emplacements qui l'attendent sont
signalés par `NOM À DÉFINIR` dans `index.php` : l'en-tête et le pied. Le jour où
il est choisi, il reste à le poser à ces deux endroits, dans le `<title>` et
dans la balise `og:title`.

## Installation

```bash
cp config.example.php config.php
php -r "echo bin2hex(random_bytes(16));"   # à reporter dans « sel »
```

Puis renseigner dans `config.php` l'adresse qui reçoit les demandes et celle qui
les expédie. **L'expéditeur doit appartenir au domaine du serveur**, sinon les
messageries classeront la notification en indésirable, voire la refuseront.

Le dossier qui accueille les demandes doit être accessible en écriture à
l'utilisateur du serveur web, et **hors de la racine web** :

```bash
mkdir -p ../var && chown www-data:www-data ../var && chmod 750 ../var
```

### nginx

```nginx
server {
    listen 443 ssl http2;
    server_name exemple.fr www.exemple.fr;

    root /srv/gestion-recettes/landing;
    index index.php;

    # La configuration ne doit jamais être lue, même si PHP tombe en panne et
    # que nginx se met à servir les .php en texte brut.
    location = /config.php { deny all; return 404; }

    location / {
        try_files $uri $uri/ =404;
    }

    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php8.4-fpm.sock;
    }

    # La vitrine ne sert que ces types : tout le reste est refusé.
    location ~ /\. { deny all; }
}
```

## Vérifier avant mise en ligne

```bash
php -S 127.0.0.1:8099
```

Puis, dans l'ordre :

1. **Les mentions légales sont complétées.** Les passages en rouge sont des
   trous, pas des exemples. Publier sans identifier l'éditeur contrevient à
   l'article 6 III de la LCEN.
2. **Une demande valide arrive bien** dans `../var/souscriptions.jsonl` et le
   courriel de notification est reçu.
3. **Le fichier des demandes n'est pas servi** : `curl` sur son chemin doit
   renvoyer 404.
4. **Les tarifs affichés sont les bons**, et la mention « HT, par établissement »
   figure sous chacun.

## Relire les demandes

```bash
cat ../var/souscriptions.jsonl | while read -r l; do
  echo "$l" | php -r '$d=json_decode(stream_get_contents(STDIN),true);
    printf("%s  %-22s %-30s %-20s %s\n", substr($d["recu_le"],0,10), $d["nom"], $d["courriel"], $d["metier"], $d["offre"]);'
done
```

Chaque demande porte une **empreinte** de l'adresse IP, jamais l'adresse
elle-même : elle ne sert qu'à limiter le nombre d'envois par origine et par
heure. Les mentions légales annoncent une purge à douze mois — c'est un
engagement, il faut le tenir :

```bash
# Retire les demandes de plus de douze mois.
php -r '$f="../var/souscriptions.jsonl"; $g=[]; $l=strtotime("-12 months");
foreach (file($f, FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES) as $x) {
  $d=json_decode($x,true); if ($d && strtotime($d["recu_le"])>=$l) { $g[]=$x; } }
file_put_contents($f, $g ? implode("\n",$g)."\n" : "");'
```

## Ce qui n'est pas fait

Le bouton ne prend aucun paiement : il enregistre une demande, et l'instance est
provisionnée à la main avec `setup-server.sh`. C'est cohérent avec une version
Alpha, où chaque premier client est accompagné à l'installation. Brancher un
paiement en libre-service supposerait d'automatiser le provisionnement, la
résiliation et la TVA — un chantier à part entière, à ouvrir quand les premiers
clients auront confirmé l'offre.
