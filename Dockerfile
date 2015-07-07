FROM alpine:3.2

RUN apk add --update \
    php-fpm \
    php-mcrypt \
    php-curl \
    php-openssl \
    php-phar \
    php-ctype \
    php-json \
    curl \
    git \
    php-dom \
    alpine-sdk \
    php-dev \
    autoconf \
    openssl-dev \
    php-pdo \
    php-pdo_pgsql \
    php-pdo_odbc \
    php-pdo_mysql \
    php-pdo_sqlite \
    php-opcache && \
    sed -i 's/\;date\.timezone\ \=/date\.timezone\ \=\ Europe\/Berlin/g' /etc/php/php.ini && \
    curl -sS https://getcomposer.org/installer | php && \
    mv composer.phar /usr/local/bin/composer && \
    cd /tmp && git clone https://github.com/phpredis/phpredis.git && cd /tmp/phpredis && \
    git checkout 2.2.7 && phpize && \
    ./configure  && \
    make  && \
    make install  && \
    echo "extension=redis.so" >> /etc/php/conf.d/redis.ini && \
    rm -rf /tmp/* && \
    apk del --purge openssl-dev autoconf php-dev alpine-sdk && \
    rm -rf /var/cache/apk/*
COPY Docker/php-fpm.conf /etc/php/php-fpm.conf
ADD  . /var/www/
WORKDIR /var/www
RUN chmod +x Docker/entrypoint.sh
CMD ["Docker/entrypoint.sh"]
EXPOSE 9000
