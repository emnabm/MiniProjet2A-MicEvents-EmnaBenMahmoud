# MiniProjet2A-MicEvent

## Gestion de réservations d'événements pour un club universitaire

## Technologies
- Symfony 8
- JWT ,passkeys
- My SQL
- Docker 

## Fonctionnalités

### Côté utilisateur
- Inscription / Connexion 
- Connexion biométrique via Passkeys
- Consultation des événements
- Réservation de places 

### Côté administrateur
- Dashboard avec statistiques
- CRUD complet sur les événements
- Consultation des réservations par événement

## Installation

### Prérequis
- PHP 8.2+
- Composer
- MySQL 
- XAMPP ou Docker

### Sans Docker (XAMPP)
```bash
# 1. Cloner le projet
git clone https://github.com/emnabm/MiniProjet2A-MicEvents-EmnaBenMahmoud.git
cd MiniProjet2A-MicEvents-EmnaBenMahmoud

# 2. Installer les dépendances
php C:\php\composer.phar install

# 3. Configurer la base de données dans .env
DATABASE_URL="mysql://root:@127.0.0.1:3306/event_reservation?serverVersion=8.0"

# 4. Créer la base de données
php bin/console doctrine:database:create
php bin/console doctrine:schema:update --force

# 5. Générer les clés JWT (Git Bash)
mkdir -p config/jwt
openssl genpkey -out config/jwt/private.pem -aes256 -algorithm rsa -pkeyopt rsa_keygen_bits:4096
openssl pkey -in config/jwt/private.pem -out config/jwt/public.pem -pubout

# 6. Lancer le serveur
symfony serve
```

### Avec Docker
```bash
docker-compose up -d
docker-compose exec php php bin/console doctrine:schema:update --force
```

### Créer le compte admin
Visiter `http://127.0.0.1:8000/admin/setup` une seule fois.
- **Username** : `admin`
- **Password** : `admin2026`
