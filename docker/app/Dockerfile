# =============================================================================
# App image: multi-target build from base images
# =============================================================================
# Build context: repo root (needs composer.json, src/, etc.)
# Usage: docker build -f docker/app/Dockerfile --target <ci|prod|dev> .
# =============================================================================

ARG BASE_DEV_IMAGE=ghcr.io/freyr/ci-pipeline-demo/demo-base-dev
ARG BASE_PROD_IMAGE=ghcr.io/freyr/ci-pipeline-demo/demo-base-prod
ARG BASE_DEV_TAG=latest
ARG BASE_PROD_TAG=8.4.2

# -- ci target ----------------------------------------------------------------
# Used for: static analysis, unit tests, functional tests
# Built from: demo-base-dev (has xdebug, dev tools)
FROM ${BASE_DEV_IMAGE}:${BASE_DEV_TAG} AS ci

WORKDIR /app

COPY composer.json composer.lock ./
RUN composer install --no-interaction --no-progress

COPY . .
RUN composer dump-autoload --optimize

# -- prod target --------------------------------------------------------------
# Used for: QA deployment, production release
# Built from: demo-base-prod (lean, no dev tools)
FROM ${BASE_PROD_IMAGE}:${BASE_PROD_TAG} AS prod

WORKDIR /app

COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --no-progress --optimize-autoloader

COPY . .
RUN composer dump-autoload --classmap-authoritative --no-dev

ARG APP_VERSION=dev
RUN echo "${APP_VERSION}" > VERSION

ARG BASE_PROD_TAG
LABEL com.demo.base.version="${BASE_PROD_TAG}"

CMD ["php", "-r", "require 'vendor/autoload.php'; echo (new Demo\\Pipeline\\Runner(new Demo\\Pipeline\\Greeter()))->run('demo') . PHP_EOL;"]

# -- dev target (local only, not built in CI) ---------------------------------
# Used for: local development with docker-compose bind-mount
FROM ${BASE_DEV_IMAGE}:${BASE_DEV_TAG} AS dev

WORKDIR /app

COPY composer.json composer.lock ./
RUN composer install --no-interaction --no-progress
# Code is NOT copied - bind-mounted via docker-compose
