COMPOSER = docker run --rm -u $(shell id -u):$(shell id -g) -v $(CURDIR):/app -w /app composer:2

install:
	$(COMPOSER) install --ignore-platform-reqs

update:
	$(COMPOSER) update --ignore-platform-reqs

test:
	./vendor/bin/phpunit -c tests/phpunit/phpunit.xml

lint:
	./vendor/bin/phpcs
	./vendor/bin/phpstan analyse -c tests/phpstan.neon

format:
	./vendor/bin/phpcbf
