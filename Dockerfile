FROM php:7.4-cli-alpine AS build
WORKDIR /app
COPY composer.json composer.lock action.php ./
RUN php -r "copy('https://getcomposer.org/installer', 'composer-setup.php');" && \
    EXPECTED_SIGNATURE="$(wget -q -O - https://composer.github.io/installer.sig)" && \
    ACTUAL_SIGNATURE="$(php -r "echo hash_file('sha384', 'composer-setup.php');")" && \
    [[ "$ACTUAL_SIGNATURE" == "$EXPECTED_SIGNATURE" ]] || { echo >&2 "Corrupt installer"; exit 1; } && \
    php composer-setup.php && \
    php -r "unlink('composer-setup.php');"
RUN apk update && \
    apk add zip
RUN addgroup -S cleanup && \
    adduser -S cleanup -G cleanup && \
    chown -R cleanup /app
USER cleanup
RUN php composer.phar install -o --no-dev && \
    rm composer.phar

FROM php:7.4-cli-alpine
LABEL "repository"="https://github.com/navikt/remove-package-versions"
LABEL "maintainer"="@christeredvartsen"
COPY --from=build /app /app
ENTRYPOINT ["php", "/app/action.php"]
