# ERP Learning Project — Common Commands
# Usage: make <target>

.PHONY: install fresh seed test demo

## Full setup from scratch
install:
	composer install
	cp -n .env.example .env || true
	php artisan key:generate
	php artisan migrate
	php artisan db:seed
	@echo ""
	@echo "✅ Setup complete! Run: php artisan serve"

## Wipe the database and reseed (fresh start)
fresh:
	php artisan migrate:fresh --seed
	@echo "✅ Database wiped and reseeded."

## Just reseed (keeps schema)
seed:
	php artisan db:seed --force

## Run all tests
test:
	php artisan test

## Run only feature tests
test-feature:
	php artisan test --testsuite=Feature

## Run only unit tests
test-unit:
	php artisan test --testsuite=Unit

## Run the interactive ERP demo
demo:
	php artisan erp:demo

## Start the dev server
serve:
	php artisan serve

## Show all API routes
routes:
	php artisan route:list --path=api
