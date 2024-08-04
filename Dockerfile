FROM composer:latest AS setup

WORKDIR /guzzle

RUN set -xe \
    && composer init --name=guzzlehttp/test --description="Simple project for testing Guzzle scripts" --author="Márk Sági-Kazár <mark.sagikazar@gmail.com>" --no-interaction \
    && composer require guzzlehttp/guzzle

FROM php:7.4.29

WORKDIR /guzzle

COPY --FROM=setup /guzzle /guzzle
