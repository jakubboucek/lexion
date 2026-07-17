# Lexion

Sledování soudních řízení na českém infoSoudu (infosoud.gov.cz) a notifikace
o změnách. Postaveno na Nette Frameworku. Produkce poběží na **lex.ion.cz**.
Podrobnosti o projektu a konvencích: [CLAUDE.md](CLAUDE.md), zadání:
[docs/zadani.md](docs/zadani.md).

## První spuštění (lokální vývoj)

Vyžaduje Docker a Node.js na hostu.

```bash
# 1. Start devstacku (web + MariaDB + Adminer)
docker compose up -d

# 2. Composer závislosti (vendor není v gitu)
docker compose exec -w /var/www/html/web web composer install

# 3. Konfigurace (local.neon je povinný, gitignorovaný)
cp web/config/local.sample.neon web/config/local.neon

# 4. Runtime adresáře (nejsou v gitu)
mkdir -p web/temp web/log

# 5. Databáze – aplikuj migrace z migrations/structures/ (ručně, po pořadí)
docker compose exec -T mysqldb mysql -uroot -pdevstack default < migrations/structures/2026-07-17-00-create-user-table.sql

# 6. Frontend build (Node na hostu, ne v kontejneru)
npm install
npm run build

# 7. První uživatel
docker compose exec -w /var/www/html web php bin/create-user.php <email> <nick> <heslo>
```

Aplikace poté běží na http://localhost:8080, Adminer na http://localhost:8088
(server `mysqldb`, root/devstack, DB `default`).
