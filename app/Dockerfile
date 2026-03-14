# =============================================================================
# App image: built FROM the base image
# =============================================================================

ARG BASE_IMAGE=ghcr.io/freyr/ci-pipeline-demo/demo-base-prod:latest
FROM ${BASE_IMAGE}

WORKDIR /app

# Install dependencies first (layer caching)
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --no-progress --optimize-autoloader

# Copy application source
COPY src/ src/
RUN composer dump-autoload --optimize --no-dev

# Write version file
ARG APP_VERSION=dev
RUN echo "${APP_VERSION}" > VERSION

CMD ["php", "-r", "require 'vendor/autoload.php'; echo (new Demo\\Pipeline\\Runner(new Demo\\Pipeline\\Greeter()))->run('demo') . PHP_EOL;"]
