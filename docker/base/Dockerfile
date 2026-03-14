# =============================================================================
# Base image: multi-stage with prod and dev targets
# =============================================================================

# -- prod target --------------------------------------------------------------
FROM php:8.4-cli-alpine AS prod

COPY --from=composer/composer:2-bin /composer /usr/bin/composer

RUN apk add --no-cache git unzip

WORKDIR /app

# -- dev target ---------------------------------------------------------------
FROM prod AS dev

COPY --from=mlocati/php-extension-installer /usr/bin/install-php-extensions /usr/local/bin/

RUN install-php-extensions xdebug
