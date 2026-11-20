#!/bin/sh
# Produce an isolated copy of the Farm transport with the single STEP 05 task.
set -eu
[ "$#" -eq 1 ] || { echo 'Usage: assemble.sh NEW_DIRECTORY' >&2; exit 2; }
src=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
dest=$1
mkdir "$dest"
mkdir "$dest/bin" "$dest/public" "$dest/database"
for file in farm.php tasks.php ui_scenario.php collection.php; do cp "$src/$file" "$dest/$file"; done
cp "$src/bin/farm.php" "$dest/bin/"
cp "$src/public/index.php" "$dest/public/"
cp "$src/step05/test_batch.php" "$src/step05/test_batch_test.php" "$src/step05/lab.php" "$dest/"
cp "$src/step05/index.php" "$src/step05/test.php" "$src/step05/pool.conf" "$src/step05/locations.conf" "$dest/"
for f in up.sql 002_tasks_up.sql 004_result_delivery_up.sql; do cp "$src/database/$f" "$dest/database/"; done
cp "$src/step05/test.sql" "$dest/database/"
patch --fuzz=0 -d "$dest" -i "$src/step05/tasks.patch"
php -l "$dest/tasks.php"
php -l "$dest/index.php"
